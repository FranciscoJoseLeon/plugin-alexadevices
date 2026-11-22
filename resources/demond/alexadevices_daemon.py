#!/usr/bin/env python3
"""
Alexa Devices Daemon for Jeedom
Async daemon powered by aioamazondevices and HTTP/2 AVS push.
"""

import argparse
import asyncio
import base64
import hashlib
import json
import logging
import os
import re
import secrets
import signal
import sys
import time
import urllib.parse
import uuid
from pathlib import Path
from typing import Any, Optional

import aiohttp
from aiohttp import web
import httpx
from yarl import URL

try:
    from aioamazondevices.api import AmazonEchoApi
    from aioamazondevices.const.http import AMAZON_DEVICE_TYPE
    from aioamazondevices.exceptions import (
        CannotAuthenticate,
        CannotConnect,
        CannotRetrieveData,
        CannotSetFeature,
    )
    from aioamazondevices.structures import (
        AmazonSaveDataConfig,
        AmazonDevice,
        AmazonMediaControls,
    )
except ImportError as err:
    print(f"Error importing aioamazondevices: {err}", file=sys.stderr)
    print("Please install requirements: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
LOGGER = logging.getLogger("alexadevices_daemon")


class _AmazonUpstreamNoiseFilter(logging.Filter):
    """Downgrade known Amazon 403 vocal-history push tracebacks so logs stay clean."""

    def filter(self, record: logging.LogRecord) -> bool:
        msg = record.getMessage()
        if record.levelno >= logging.ERROR and "PUSH_EQUALIZER_STATE_CHANGE" in msg:
            exc = record.exc_info[1] if record.exc_info else None
            if exc and "Forbidden" in str(exc):
                record.levelno = logging.DEBUG
                record.levelname = "DEBUG"
                record.exc_info = None
                record.exc_text = None
                record.msg = "Amazon vocal-history endpoint returned 403 Forbidden (upstream restriction); ignoring."
                record.args = ()
        return True


logging.getLogger("aioamazondevices").addFilter(_AmazonUpstreamNoiseFilter())


class AlexaDaemon:
    def __init__(
        self,
        port: int,
        jeedom_url: str,
        token: str,
        storage_dir: str,
        poll_interval: int = 300,
    ):
        self.port = port
        self.jeedom_url = jeedom_url
        self.token = token
        self.storage_dir = Path(storage_dir)
        self.storage_dir.mkdir(parents=True, exist_ok=True)
        self.poll_interval = poll_interval

        self.session: Optional[aiohttp.ClientSession] = None
        self.httpx_client: Optional[httpx.AsyncClient] = None
        self.api: Optional[AmazonEchoApi] = None
        self.app: Optional[web.Application] = None
        self.runner: Optional[web.AppRunner] = None
        self.devices: dict[str, AmazonDevice] = {}
        self.is_authenticated = False
        self.current_user: Optional[str] = None
        self.poll_task: Optional[asyncio.Task] = None
        self.session_file = self.storage_dir / "daemon_session.json"
        self.active_proxy: dict[str, Any] = {}

    async def init_session_from_disk(self):
        """Intenta inicializar la sesión de Amazon a partir de datos guardados previamente."""
        if not self.session_file.exists():
            LOGGER.info("No se encontró sesión previa en %s", self.session_file)
            return False

        try:
            with open(self.session_file, "r", encoding="utf-8") as f:
                saved = json.load(f)

            username = saved.get("username")
            password = saved.get("password", "")
            login_data = saved.get("login_data")

            if not username or not login_data:
                return False

            LOGGER.info("Intentando restaurar sesión para el usuario %s", username)
            self.session = aiohttp.ClientSession()
            self.api = AmazonEchoApi(
                self.session,
                username,
                password,
                login_data=login_data,
                save_data=AmazonSaveDataConfig(path=self.storage_dir),
            )

            # Registrar listeners de eventos push HTTP/2
            self._register_event_listeners()

            await self.api.login.login_mode_stored_data()
            self.is_authenticated = True
            self.current_user = username
            LOGGER.info("Sesión restaurada correctamente.")

            # Sincronización inicial de dispositivos
            await self.refresh_devices()
            await self.start_http2()
            return True
        except Exception as err:
            LOGGER.warning("No se pudo restaurar la sesión guardada: %s", err)
            self.is_authenticated = False
            return False

    def _register_event_listeners(self):
        """Registra los manejadores de eventos push HTTP/2 desde la API de Amazon."""
        if not self.api:
            return

        self.api.on_volume_state_event.append(self._on_volume_event)
        self.api.on_volume_state_event.freeze()

        self.api.on_media_state_event.append(self._on_media_event)
        self.api.on_media_state_event.freeze()

        self.api.on_dnd_event.append(self._on_dnd_event)
        self.api.on_dnd_event.freeze()

        self.api.on_sensor_event.append(self._on_sensor_event)
        self.api.on_sensor_event.freeze()

        self.api.on_history_event.append(self._on_history_event)
        self.api.on_history_event.freeze()

        self.api.on_todo_event.append(self._on_todo_event)
        self.api.on_todo_event.freeze()

    async def _send_to_jeedom(self, event_type: str, payload: dict[str, Any]):
        """Envía notificaciones de cambios y eventos a Jeedom vía webhook."""
        if not self.jeedom_url:
            return

        post_data = {
            "token": self.token,
            "event_type": event_type,
            "payload": payload,
        }

        try:
            async with aiohttp.ClientSession() as client:
                async with client.post(
                    self.jeedom_url,
                    json=post_data,
                    timeout=aiohttp.ClientTimeout(total=5),
                ) as resp:
                    if resp.status != 200:
                        LOGGER.warning("Jeedom respondió con estado HTTP %s", resp.status)
        except Exception as err:
            LOGGER.debug("Error enviando webhook a Jeedom (%s): %s", self.jeedom_url, err)

    async def _on_volume_event(self, volume_states):
        LOGGER.debug("Evento de volumen recibido: %s", volume_states)
        formatted = {}
        for serial, state in volume_states.items():
            formatted[serial] = {
                "volume": getattr(state, "volume", None),
                "muted": getattr(state, "muted", None),
            }
        await self._send_to_jeedom("volume_state", formatted)

    async def _on_media_event(self, media_states):
        LOGGER.debug("Evento de estado multimedia recibido: %s", media_states)
        formatted = {}
        for serial, state in media_states.items():
            formatted[serial] = {
                "state": getattr(state, "state", None),
                "title": getattr(state, "title", None),
                "artist": getattr(state, "artist", None),
            }
        await self._send_to_jeedom("media_state", formatted)

    async def _on_dnd_event(self, dnd_states):
        LOGGER.debug("Evento No Molestar (DND): %s", dnd_states)
        await self._send_to_jeedom("dnd_state", dict(dnd_states))

    async def _on_sensor_event(self):
        LOGGER.debug("Evento de sensor recibido.")
        if not self.api or not getattr(self.api, "sensors", None):
            return
        sensor_data = {}
        for serial, sensors in self.api.sensors.items():
            if not isinstance(sensors, dict):
                continue
            sensor_data[serial] = {
                k: {
                    "name": getattr(s, "name", k),
                    "value": getattr(s, "value", None),
                    "scale": getattr(s, "scale", None),
                    "error": getattr(s, "error", False),
                }
                for k, s in sensors.items()
            }
        if sensor_data:
            await self._send_to_jeedom("sensor_state", sensor_data)

    async def _on_history_event(self, vocal_records):
        LOGGER.debug("Evento de historial de voz recibido: %s", vocal_records)
        if not vocal_records:
            return
        formatted = {}
        items = vocal_records.items() if isinstance(vocal_records, dict) else [
            (getattr(r, "device_serial", None) or getattr(r, "serial_number", None) or getattr(r, "serial", None), r)
            for r in (vocal_records if isinstance(vocal_records, (list, tuple)) else [vocal_records])
        ]
        for serial, record in items:
            if not serial or record is None:
                continue
            desc = (
                getattr(record, "description", None)
                or getattr(record, "summary", None)
                or getattr(record, "utterance_text", None)
                or getattr(record, "text", None)
                or (record.get("description") or record.get("summary") if isinstance(record, dict) else None)
            )
            ts = getattr(record, "time", None) or (record.get("time") if isinstance(record, dict) else None)
            if desc:
                formatted[serial] = {"description": str(desc), "time": ts}
        if formatted:
            await self._send_to_jeedom("history_state", formatted)

    async def _on_todo_event(self, list_event):
        LOGGER.debug("Evento de lista de tareas: %s", list_event)
        await self._send_to_jeedom("todo_event", {"list_id": getattr(list_event, "list_id", None)})

    async def start_http2(self):
        """Inicia el procesamiento de la conexión push HTTP/2 persistente con Amazon AVS."""
        if not self.api or not self.is_authenticated:
            return

        try:
            if not self.httpx_client:
                self.httpx_client = httpx.AsyncClient(http2=True, timeout=60.0)

            async def _on_reauth():
                LOGGER.warning("Amazon solicita re-autenticación en la conexión HTTP/2.")
                self.is_authenticated = False
                await self._send_to_jeedom("auth_required", {})

            await self.api.start_http2_processing(
                self.httpx_client,
                on_reauth_required=_on_reauth,
            )
            LOGGER.info("Conexión push HTTP/2 iniciada con éxito.")
        except Exception as err:
            LOGGER.error("Error al iniciar stream HTTP/2: %s", err)

    async def refresh_devices(self) -> dict[str, Any]:
        """Obtiene la lista actualizada de dispositivos desde Amazon."""
        if not self.api or not self.is_authenticated:
            return {}

        try:
            self.devices = await self.api.get_devices_data()
            try:
                await self.api.sync_media_state()
            except Exception:
                pass
            try:
                await self.api.sync_dnd_state()
            except Exception:
                pass

            devs_summary = {
                serial: self._serialize_device(dev)
                for serial, dev in self.devices.items()
            }

            LOGGER.info("Sincronizados %d dispositivos Alexa.", len(self.devices))
            # Notificar a Jeedom de la lista de dispositivos
            await self._send_to_jeedom("devices_list", devs_summary)
            # Enviar también los sensores iniciales (temperatura, calidad del aire, presencia, etc.)
            await self._on_sensor_event()
            return devs_summary
        except CannotRetrieveData as err:
            LOGGER.error("Error al obtener datos de Amazon (posible cambio de API): %s", err)
            await self._send_to_jeedom("reinstall_dependencies_needed", {"reason": "Cambio en la API de Amazon detectado"})
            return {}
        except Exception as err:
            LOGGER.error("Error actualizando dispositivos: %s", err)
            return {}

    async def periodic_polling(self):
        """Bucle de polling de seguridad."""
        while True:
            try:
                await asyncio.sleep(self.poll_interval)
                if self.is_authenticated:
                    LOGGER.debug("Ejecutando polling de seguridad...")
                    await self.refresh_devices()
            except asyncio.CancelledError:
                break
            except Exception as err:
                LOGGER.error("Error en ciclo de polling: %s", err)

    # ================= HTTP REST API Handlers =================

    async def handle_health(self, request: web.Request) -> web.Response:
        return web.json_response({
            "status": "ok",
            "authenticated": self.is_authenticated,
            "user": self.current_user,
            "devices_count": len(self.devices),
        })

    async def handle_login(self, request: web.Request) -> web.Response:
        """Inicia sesión interactiva solicitando código OTP de 2FA."""
        try:
            data = await request.json()
        except Exception:
            return web.json_response({"error": "JSON inválido"}, status=400)

        username = data.get("username")
        password = data.get("password")
        code = data.get("code")
        site = data.get("site", "https://www.amazon.es")

        if not username or not password or not code:
            return web.json_response({"error": "Faltan campos: username, password o code"}, status=400)

        try:
            if self.session:
                await self.session.close()
            self.session = aiohttp.ClientSession()

            self.api = AmazonEchoApi(
                self.session,
                username,
                password,
                save_data=AmazonSaveDataConfig(path=self.storage_dir),
            )

            self._register_event_listeners()

            LOGGER.info("Iniciando validación de credenciales para %s...", username)
            login_result = await self.api.login.login_mode_interactive(code)

            self.is_authenticated = True
            self.current_user = username

            # Guardar datos de sesión localmente
            with open(self.session_file, "w", encoding="utf-8") as f:
                json.dump({
                    "username": username,
                    "password": password,
                    "site": site,
                    "login_data": login_result,
                }, f, indent=2)

            LOGGER.info("Autenticación exitosa para %s.", username)

            # Iniciar stream HTTP2 y descubrir dispositivos
            await self.refresh_devices()
            await self.start_http2()

            return web.json_response({
                "status": "ok",
                "message": "Autenticación realizada con éxito",
                "user": username,
            })
        except (CannotAuthenticate, CannotConnect, CannotRetrieveData) as err:
            LOGGER.error("Fallo de autenticación: %s", err)
            self.is_authenticated = False
            return web.json_response({"error": f"Fallo al autenticar: {err}"}, status=401)
        except Exception as err:
            LOGGER.exception("Error inesperado durante el login: %s", err)
            return web.json_response({"error": str(err)}, status=500)

    @staticmethod
    def _serialize_device(dev) -> dict:
        return {
            "serial_number": dev.serial_number,
            "account_name": dev.account_name,
            "device_family": dev.device_family,
            "device_type": dev.device_type,
            "primary_display_category": getattr(dev, "primary_display_category", "") or "",
            "manufacturer": getattr(dev, "manufacturer", None) or "",
            "model": getattr(dev, "model", None) or "",
            "hardware_version": getattr(dev, "hardware_version", None) or "",
            "online": dev.online,
            "media_player_supported": dev.media_player_supported,
            "notifications_supported": dev.notifications_supported,
            "voice_control_supported": dev.voice_control_supported,
            "software_version": dev.software_version,
        }

    async def handle_devices(self, request: web.Request) -> web.Response:
        summary = {
            serial: self._serialize_device(dev)
            for serial, dev in self.devices.items()
        }
        return web.json_response({"devices": summary})

    async def handle_sync(self, request: web.Request) -> web.Response:
        devs = await self.refresh_devices()
        return web.json_response({"status": "ok", "devices_count": len(devs)})

    # ================= AMAZON OAUTH PROXY =================

    DOMAIN_HANDLES = {
        "es": "_es",
        "fr": "_fr",
        "de": "_de",
        "it": "_it",
        "gb": "_uk",
        "co.uk": "_uk",
        "ca": "_ca",
        "mx": "_mx",
        "com.mx": "_mx",
        "jp": "_jp",
        "com": "",
        "us": "",
    }

    DOMAIN_LANGUAGES = {
        "es": "es_ES",
        "fr": "fr_FR",
        "de": "de_DE",
        "it": "it_IT",
        "gb": "en_GB",
        "co.uk": "en_GB",
        "ca": "en_CA",
        "mx": "es_MX",
        "com.mx": "es_MX",
        "jp": "ja_JP",
        "com": "en_US",
        "us": "en_US",
    }

    async def complete_oauth(self, authcode: str, proxy_data: dict[str, Any]) -> bool:
        """Completa el registro del dispositivo tras capturar el authorization_code de Amazon."""
        try:
            LOGGER.info("Registrando dispositivo Alexa con el código de autorización recibido...")
            code_verifier = proxy_data["code_verifier"]
            serial = proxy_data["serial"]
            domain = proxy_data["domain"]
            domain_suffix = "com" if domain == "us" else ("co.uk" if domain == "gb" else domain)
            site = f"https://www.amazon.{domain_suffix}"

            if self.session:
                await self.session.close()
            self.session = aiohttp.ClientSession()

            self.api = AmazonEchoApi(
                self.session,
                "alexa_user",
                "",
                save_data=AmazonSaveDataConfig(path=self.storage_dir),
            )
            self.api._session_state_data.country_specific_data(site)
            self.api.login._serial = serial

            self._register_event_listeners()

            login_result = await self.api.login._register_device({
                "authorization_code": authcode,
                "code_verifier": code_verifier,
                "domain": self.api._session_state_data.domain,
            })
            self.api.login._session_state_data.login_stored_data = login_result

            await self.api.login._domain_refresh_auth_cookies()
            await self.api.login._register_device_capabilities()
            await self.api.login.obtain_account_customer_id()

            self.api.login._session_state_data.login_stored_data.update({"site": site})

            customer_info = login_result.get("customer_info", {})
            user_name = customer_info.get("name") or customer_info.get("user_id") or "Amazon User"

            self.is_authenticated = True
            self.current_user = user_name
            if self.active_proxy:
                self.active_proxy["status"] = "success"

            with open(self.session_file, "w", encoding="utf-8") as f:
                json.dump({
                    "username": user_name,
                    "site": site,
                    "domain": domain,
                    "login_data": login_result,
                }, f, indent=2)

            LOGGER.info("¡Autenticación OAuth exitosa para %s (%s)!", user_name, site)

            await self.refresh_devices()
            await self.start_http2()

            await self._send_to_jeedom("auth_success", {"user": user_name, "site": site})
            return True
        except Exception as err:
            LOGGER.exception("Error al completar el registro OAuth en Amazon: %s", err)
            self.is_authenticated = False
            if self.active_proxy:
                self.active_proxy["status"] = "error"
                self.active_proxy["error"] = str(err)
            return False

    async def handle_proxy_start(self, request: web.Request) -> web.Response:
        """Inicia una sesión de proxy web para el login oficial de Amazon."""
        domain = request.query.get("domain", "es")
        proxy_base = request.query.get("proxy_base", "/plugins/alexadevices/core/php/proxy.php")

        base_handle = self.DOMAIN_HANDLES.get(domain, f"_{domain}")
        language = self.DOMAIN_LANGUAGES.get(domain, "es_ES")
        domain_suffix = "com" if domain == "us" else ("co.uk" if domain == "gb" else domain)

        temp_session = aiohttp.ClientSession()
        temp_api = AmazonEchoApi(temp_session, "alexa_user", "", save_data=AmazonSaveDataConfig(path=self.storage_dir))
        temp_api._session_state_data.country_specific_data(f"https://www.amazon.{domain_suffix}")

        verifier = temp_api.login._create_code_verifier()
        challenge = temp_api.login._create_s256_code_challenge(verifier).decode("ascii")
        serial = temp_api.login._serial
        client_id = temp_api.login._build_client_id()
        await temp_session.close()

        amazon_host = f"www.amazon.{domain_suffix}"
        return_to = f"https://{amazon_host}/ap/maplanding"

        oauth_url = (
            f"https://{amazon_host}/ap/signin"
            f"?openid.return_to={urllib.parse.quote(return_to, safe='')}"
            f"&openid.assoc_handle=amzn_dp_project_dee_ios{base_handle}"
            f"&openid.identity=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select"
            f"&pageId=amzn_dp_project_dee_ios{base_handle}"
            f"&accountStatusPolicy=P1"
            f"&openid.claimed_id=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select"
            f"&openid.mode=checkid_setup"
            f"&openid.ns.oa2=http%3A%2F%2Fwww.amazon.com%2Fap%2Fext%2Foauth%2F2"
            f"&openid.oa2.client_id=device%3A{client_id}"
            f"&openid.ns.pape=http%3A%2F%2Fspecs.openid.net%2Fextensions%2Fpape%2F1.0"
            f"&openid.oa2.response_type=code"
            f"&openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0"
            f"&openid.pape.max_auth_age=0"
            f"&openid.oa2.scope=device_auth_access"
            f"&openid.oa2.code_challenge_method=S256"
            f"&openid.oa2.code_challenge={challenge}"
            f"&language={language}"
        )

        if "session" in self.active_proxy:
            try:
                await self.active_proxy["session"].close()
            except Exception:
                pass

        cookie_jar = aiohttp.CookieJar(unsafe=True)
        # Inyectar cookies anti-bot requeridas por Amazon (frc, map-md, amzn-app-id)
        cookie_jar.update_cookies(temp_api._http_wrapper.cookies, URL(f"https://{amazon_host}"))
        proxy_session = aiohttp.ClientSession(cookie_jar=cookie_jar)

        self.active_proxy = {
            "session": proxy_session,
            "code_verifier": verifier,
            "serial": serial,
            "domain": domain,
            "language": language,
            "client_id": client_id,
            "proxy_base": proxy_base,
            "amazon_host": amazon_host,
            "status": "authenticating",
            "created_at": time.time(),
        }

        LOGGER.info("Sesión de proxy oficial Amazon iniciada para dominio %s (host %s)", domain, amazon_host)
        sep = "&" if "?" in proxy_base else "?"
        redirect_url = f"{proxy_base}{sep}url={urllib.parse.quote(oauth_url, safe='')}"
        raise web.HTTPFound(redirect_url)

    async def handle_proxy_relay(self, request: web.Request) -> web.Response:
        """Reenvía peticiones y respuestas entre el navegador y la web oficial de Amazon."""
        if not self.active_proxy or not self.active_proxy.get("session"):
            return web.Response(
                text="No hay sesión de autenticación activa. Por favor, abre de nuevo la ventana oficial de Amazon.",
                status=400,
                content_type="text/plain",
                charset="utf-8",
            )

        target_url = request.query.get("url", "")
        proxy_base = self.active_proxy.get("proxy_base", "/plugins/alexadevices/core/php/proxy.php")
        domain = self.active_proxy.get("domain", "es")
        language = self.active_proxy.get("language", "es_ES")
        amazon_host = self.active_proxy.get("amazon_host", f"www.amazon.{domain}")

        if not target_url:
            path = request.query.get("path", "")
            if path:
                target_url = f"https://{amazon_host}/{path.lstrip('/')}"
            elif "amzn" in request.query:
                # Captcha validation / Seguir comprando fallback
                target_url = f"https://{amazon_host}/errors/validateCaptcha?{request.query_string}"
            else:
                return web.Response(text="URL no especificada en el proxy.", status=400, content_type="text/plain", charset="utf-8")

        # Seguridad: evitar URLs doblemente envueltas por el proxy
        if "/plugins/alexadevices/core/php/proxy.php" in target_url:
            inner_parsed = urllib.parse.urlparse(target_url)
            inner_params = urllib.parse.parse_qs(inner_parsed.query)
            if "url" in inner_params and inner_params["url"][0]:
                target_url = inner_params["url"][0]

        # 1. Comprobar si hemos llegado a /ap/maplanding con el código de retorno
        if "/ap/maplanding" in target_url:
            parsed = urllib.parse.urlparse(target_url)
            params = urllib.parse.parse_qs(parsed.query)
            authcode = params.get("openid.oa2.authorization_code", [""])[0]

            if authcode:
                LOGGER.info("¡Código de autorización capturado desde URL de mapa de aterrizaje!")
                success = await self.complete_oauth(authcode, self.active_proxy)
                if success:
                    return self._render_success_page()
                else:
                    return web.Response(text="Error registrando en Amazon.", status=500, content_type="text/plain", charset="utf-8")
            LOGGER.warning("Llegada a /ap/maplanding sin authorization_code: %s", target_url)
            return web.Response(
                text="Amazon no devolvió el código de autorización OAuth. Cierra esta ventana e inténtalo de nuevo.",
                status=400,
                content_type="text/plain",
                charset="utf-8",
            )

        # 2. Reenviar petición a Amazon
        proxy_session: aiohttp.ClientSession = self.active_proxy["session"]
        method = request.method

        parsed_target = urllib.parse.urlparse(target_url)
        target_host = (parsed_target.hostname or "").lower()
        if parsed_target.scheme != "https" or not self._is_allowed_amazon_host(target_host):
            LOGGER.warning("Proxy: hôte refusé %s", target_host)
            return web.Response(text="Host not allowed.", status=403, content_type="text/plain", charset="utf-8")

        headers = {}
        for k, v in request.headers.items():
            if k.lower() in ("host", "content-length", "accept-encoding", "cookie", "referer", "origin", "connection"):
                continue
            headers[k] = v

        is_navigation = request.headers.get("Sec-Fetch-Mode", "navigate").lower() == "navigate"
        headers["Host"] = parsed_target.netloc or amazon_host
        headers.setdefault("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36")
        headers["Accept-Language"] = f"{language.replace('_', '-')},{language[:2]};q=0.9,en-US;q=0.8,en;q=0.7"
        if is_navigation:
            headers.setdefault("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8")
            headers["Upgrade-Insecure-Requests"] = "1"
            headers["Sec-Fetch-Dest"] = "document"
            headers["Sec-Fetch-Mode"] = "navigate"
            headers["Sec-Fetch-User"] = "?1"
        last_page = self.active_proxy.get("last_page")
        headers["Sec-Fetch-Site"] = "same-origin" if last_page else "none"
        if last_page:
            headers["Referer"] = last_page
        if method not in ("GET", "HEAD"):
            headers["Origin"] = f"https://{amazon_host}"

        body = None
        if method in ("POST", "PUT", "PATCH"):
            body = await request.read()
            if "content-type" not in [k.lower() for k in headers]:
                headers["Content-Type"] = "application/x-www-form-urlencoded"

        LOGGER.info("Proxy relay: %s %s (body=%d bytes)", method, target_url, len(body) if body else 0)

        try:
            async with proxy_session.request(
                method,
                target_url,
                data=body,
                headers=headers,
                allow_redirects=False,
            ) as resp:
                resp_status = resp.status
                resp_headers = {}
                location = resp.headers.get("Location", "")
                if location:
                    LOGGER.info("Amazon respuesta %d -> Location: %s", resp_status, location)
                else:
                    LOGGER.info("Amazon respuesta %d para %s", resp_status, target_url)

                # Interceptar redirecciones (301, 302, 303, 307)
                if resp_status in (301, 302, 303, 307):
                    location = resp.headers.get("Location", "")
                    if "/ap/maplanding" in location:
                        LOGGER.info("Redirección hacia maplanding detectada!")
                        parsed_loc = urllib.parse.urlparse(location)
                        params = urllib.parse.parse_qs(parsed_loc.query)
                        authcode = params.get("openid.oa2.authorization_code", [""])[0]
                        if authcode:
                            success = await self.complete_oauth(authcode, self.active_proxy)
                            if success:
                                return self._render_success_page()
                            else:
                                return web.Response(text="Error al registrar en Amazon.", status=500, content_type="text/plain", charset="utf-8")

                    if location:
                        location = urllib.parse.urljoin(target_url, location)
                        sep = "&" if "?" in proxy_base else "?"
                        rewritten_loc = f"{proxy_base}{sep}url={urllib.parse.quote(location, safe='')}"
                        resp_headers["Location"] = rewritten_loc
                        return web.Response(status=resp_status, headers=resp_headers)

                content = await resp.read()
                content_type = resp.headers.get("Content-Type", "")

                for hk, hv in resp.headers.items():
                    if hk.lower() in (
                        "content-security-policy",
                        "content-security-policy-report-only",
                        "x-frame-options",
                        "strict-transport-security",
                        "content-encoding",
                        "transfer-encoding",
                        "content-length",
                        "set-cookie",
                        "alt-svc",
                        "connection",
                    ):
                        continue
                    resp_headers[hk] = hv

                if "text/html" in content_type and is_navigation:
                    self.active_proxy["last_page"] = target_url

                if "text/html" in content_type:
                    try:
                        text = content.decode("utf-8", errors="replace")
                        text = self._rewrite_amazon_html(text, amazon_host, proxy_base, target_url)
                        content = text.encode("utf-8")
                        resp_headers["Content-Length"] = str(len(content))
                    except Exception as err:
                        LOGGER.warning("Error al reescribir HTML: %s", err)

                return web.Response(
                    body=content,
                    status=resp_status,
                    headers=resp_headers,
                )

        except Exception as err:
            LOGGER.error("Error en proxy relay hacia %s: %s", target_url, err)
            return web.Response(text=f"Error en proxy: {err}", status=502, content_type="text/plain", charset="utf-8")

    ALLOWED_AMAZON_HOST_RE = re.compile(
        r"(^|\.)(amazon\.(com|es|fr|de|it|co\.uk|ca|com\.mx|co\.jp|com\.br|com\.au|in|nl|se|pl|com\.tr|ae|sa|sg)"
        r"|media-amazon\.com|ssl-images-amazon\.com|amazon\.dev)$"
    )

    def _is_allowed_amazon_host(self, host: str) -> bool:
        """Limite le proxy aux domaines Amazon (évite tout usage comme proxy ouvert)."""
        return bool(host) and bool(self.ALLOWED_AMAZON_HOST_RE.search(host))

    def _rewrite_amazon_html(self, html: str, amazon_host: str, proxy_base: str, current_url: str = "") -> str:
        """Adapta enlaces, formularios y peticiones AJAX de la página de Amazon para que pasen por el proxy."""
        sep = "&" if "?" in proxy_base else "?"
        base_url = current_url or f"https://{amazon_host}/"

        def _rewrite_attr(match: re.Match) -> str:
            attr, quote, val = match.group(1), match.group(2), match.group(3)
            if not val or proxy_base in val or val.startswith(("#", "javascript:", "mailto:", "tel:", "data:")):
                return match.group(0)
            if attr.lower() == "href" and not (
                val.startswith(("/ap/", "/ax/", f"https://{amazon_host}/ap/", f"https://{amazon_host}/ax/"))
            ):
                return match.group(0)
            full_url = urllib.parse.urljoin(base_url, val)
            encoded_url = urllib.parse.quote(full_url, safe="")
            return f"{attr}={quote}{proxy_base}{sep}url={encoded_url}{quote}"

        html = re.sub(
            r'\b(action|href)\s*=\s*(["\'])([^"\']*)\2',
            _rewrite_attr,
            html,
            flags=re.IGNORECASE,
        )

        cfg = json.dumps({"p": proxy_base, "h": amazon_host, "c": current_url or f"https://{amazon_host}/"})
        helper_script = """
<style>
body, h1, h2, h3, h4, p, label, span, div, a, input, button, .a-text-bold {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif !important;
}
</style>
<script>
(function () {
    var C = %s;
    var sep = C.p.indexOf('?') !== -1 ? '&' : '?';
    function isAmazon(host) { return /(^|\\.)amazon\\.[a-z.]+$/i.test(host); }
    function abs(u) {
        try { return new URL(u, C.c).href; } catch (e) { return null; }
    }
    function rw(u) {
        if (typeof u !== 'string' && !(u instanceof URL)) return u;
        u = String(u);
        if (!u || u.indexOf(C.p) !== -1 || /^(data|blob|javascript|about):/i.test(u)) return u;
        var full;
        if (/^https?:\\/\\//i.test(u) || u.indexOf('//') === 0) {
            full = abs(u);
            if (!full) return u;
            var host = new URL(full).hostname;
            if (host === location.hostname) {
                full = abs(new URL(full).pathname + new URL(full).search);
            } else if (!isAmazon(host)) {
                return u;
            }
        } else {
            full = abs(u);
            if (!full) return u;
        }
        return C.p + sep + 'url=' + encodeURIComponent(full);
    }
    var oFetch = window.fetch;
    if (oFetch) {
        window.fetch = function (input, init) {
            try {
                if (typeof input === 'string' || input instanceof URL) {
                    input = rw(input);
                } else if (input && typeof input.url === 'string') {
                    var targetUrl = rw(input.url);
                    init = init || {};
                    if (!init.method && input.method) init.method = input.method;
                    if (!init.headers && input.headers) init.headers = input.headers;
                    return oFetch.call(this, targetUrl, init);
                }
            } catch (e) {}
            return oFetch.call(this, input, init);
        };
    }
    var oOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (m, u) {
        var a = Array.prototype.slice.call(arguments);
        try { a[1] = rw(u); } catch (e) {}
        return oOpen.apply(this, a);
    };
    if (navigator.sendBeacon) {
        var oBeacon = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = function (u, d) { try { return oBeacon(rw(u), d); } catch (e) { return false; } };
    }
    var oSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        var raw = this.getAttribute('action') || '';
        if (raw.indexOf(C.p) === -1) {
            var target = abs(raw || C.c);
            if (target) {
                this.setAttribute('action', C.p + sep + 'url=' + encodeURIComponent(target));
            }
        }
        return oSubmit.call(this);
    };
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.tagName) return;
        var raw = f.getAttribute('action') || '';
        if (raw.indexOf(C.p) !== -1) {
            if ((f.method || 'get').toLowerCase() !== 'get') return;
            var q = raw.indexOf('url=');
            raw = q !== -1 ? decodeURIComponent(raw.substring(q + 4)) : C.c;
        }
        var target = abs(raw || C.c);
        if (!target) return;
        if ((f.method || 'get').toLowerCase() === 'get') {
            e.preventDefault();
            var params = new URLSearchParams(new FormData(f)).toString();
            window.location.href = C.p + sep + 'url=' + encodeURIComponent(target.split('?')[0] + (params ? '?' + params : ''));
            return;
        }
        f.setAttribute('action', C.p + sep + 'url=' + encodeURIComponent(target));
    }, true);
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('input[type="submit"], button[type="submit"], .a-button-input') : null;
        if (btn && btn.form) {
            var raw = btn.form.getAttribute('action') || '';
            if (raw.indexOf(C.p) === -1) {
                var target = abs(raw || C.c);
                if (target) {
                    btn.form.setAttribute('action', C.p + sep + 'url=' + encodeURIComponent(target));
                }
            }
        }
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a) return;
        var raw = a.getAttribute('href');
        if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(raw) || raw.indexOf(C.p) !== -1) return;
        var target = abs(raw);
        if (!target) return;
        var host = new URL(target).hostname;
        if (!isAmazon(host) && host !== location.hostname) return;
        e.preventDefault();
        window.location.href = rw(raw);
    }, true);
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || el.type !== 'checkbox') return;
        var id = (el.id || '') + ' ' + (el.name || '');
        if (/show[-_]?password/i.test(id)) {
            var form = el.form || document;
            var pwd = form.querySelector('input[name="password"], input#ap_password');
            if (pwd) pwd.type = el.checked ? 'text' : 'password';
        }
    });
})();
</script>
""" % cfg
        lower = html.lower()
        idx = lower.find("<head")
        if idx != -1:
            end = html.find(">", idx)
            html = html[: end + 1] + helper_script + html[end + 1:]
        elif "</body>" in html:
            html = html.replace("</body>", helper_script + "</body>")
        else:
            html = helper_script + html

        return html

    def _render_success_page(self) -> web.Response:
        """Devuelve una página de confirmación visual amigable al usuario."""
        html = """<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Amazon Alexa - Autenticación Exitosa</title>
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #1e1e24;
    color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
    margin: 0;
  }
  .card {
    background: #2a2a35;
    border: 1px solid #3c3c4d;
    border-radius: 12px;
    padding: 35px 25px;
    text-align: center;
    max-width: 440px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
  }
  .icon {
    width: 64px;
    height: 64px;
    background: #28a745;
    color: white;
    font-size: 38px;
    line-height: 64px;
    border-radius: 50%;
    margin: 0 auto 20px auto;
    font-weight: bold;
  }
  h2 { margin: 0 0 12px 0; color: #ffffff; font-size: 22px; }
  p { color: #a0a0b0; font-size: 14px; line-height: 1.5; margin: 0 0 20px 0; }
  .btn {
    background: #007bff;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 6px;
    font-size: 15px;
    cursor: pointer;
    font-weight: 500;
  }
  .btn:hover { background: #0069d9; }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">✓</div>
    <h2>¡Conexión completada con éxito!</h2>
    <p>Tu cuenta de Amazon Alexa se ha vinculado correctamente a Jeedom.<br>Tus dispositivos se están sincronizando.</p>
    <button class="btn" onclick="window.close();">Cerrar ventana</button>
  </div>
  <script>
    setTimeout(function() {
      try { window.close(); } catch(e) {}
    }, 2000);
  </script>
</body>
</html>"""
        return web.Response(text=html, content_type="text/html", charset="utf-8")

    async def handle_proxy_status(self, request: web.Request) -> web.Response:
        """Devuelve el estado de la autenticación por proxy."""
        return web.json_response({
            "status": self.active_proxy.get("status", "idle") if self.active_proxy else "idle",
            "authenticated": self.is_authenticated,
            "user": self.current_user,
            "devices_count": len(self.devices),
        })

    async def handle_manual_code(self, request: web.Request) -> web.Response:
        """Permite validar un código o URL de retorno introducido manualmente."""
        try:
            data = await request.json()
        except Exception:
            return web.json_response({"error": "JSON inválido"}, status=400)

        raw_input = data.get("code") or data.get("url") or ""
        domain = data.get("domain", "es")

        if not raw_input:
            return web.json_response({"error": "Código o URL no proporcionado"}, status=400)

        authcode = raw_input
        if "openid.oa2.authorization_code=" in raw_input:
            parsed = urllib.parse.urlparse(raw_input)
            params = urllib.parse.parse_qs(parsed.query)
            authcode = params.get("openid.oa2.authorization_code", [raw_input])[0]

        proxy_data = self.active_proxy or {
            "code_verifier": base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b"="),
            "serial": uuid.uuid4().hex.upper(),
            "domain": domain,
        }

        success = await self.complete_oauth(authcode, proxy_data)
        if success:
            return web.json_response({"status": "ok", "message": "Autenticación manual completada"})
        else:
            return web.json_response({"error": "Error registrando dispositivo en Amazon"}, status=500)

    async def handle_command(self, request: web.Request) -> web.Response:
        """Ejecuta una acción o comando en un dispositivo Echo específico."""
        try:
            data = await request.json()
        except Exception:
            return web.json_response({"error": "JSON inválido"}, status=400)

        serial = data.get("serial_number")
        action = data.get("action")
        params = data.get("params", {})

        if not self.is_authenticated or not self.api:
            return web.json_response({"error": "Demonio no autenticado con Amazon"}, status=403)

        device = self.devices.get(serial)
        if not device and serial != "all":
            return web.json_response({"error": f"Dispositivo {serial} no encontrado"}, status=404)

        try:
            if action == "speak":
                text = params.get("text", "")
                stripped = text.strip()
                if stripped and not stripped.lower().startswith("<speak") and re.search(
                    r"<(break|amazon:effect|prosody|say-as|emphasis|phoneme|sub)\b",
                    stripped,
                    re.IGNORECASE,
                ):
                    text = f"<speak>{stripped}</speak>"
                members = [
                    self.devices[m]
                    for m in (getattr(device, "device_cluster_members", None) or [])
                    if m in self.devices and m != device.serial_number
                ]
                if getattr(device, "device_family", "") == "WHA" and members:
                    await asyncio.gather(
                        *(self.api.call_alexa_speak(m_dev, text) for m_dev in members),
                        return_exceptions=True,
                    )
                else:
                    await self.api.call_alexa_speak(device, text)

            elif action == "announcement":
                text = params.get("text", "")
                title = params.get("title", "Jeedom")
                await self.api.call_alexa_announcement(device, text, display_text=title)

            elif action == "text_command":
                cmd = params.get("command", "")
                await self.api.call_alexa_text_command(device, cmd)

            elif action == "set_volume":
                vol = int(params.get("volume", 50))
                await self.api.set_device_volume(device, vol)

            elif action == "media_command":
                cmd = str(params.get("command", "")).strip().lower()
                media_map = {
                    "play": AmazonMediaControls.Play,
                    "stop": AmazonMediaControls.Stop,
                    "pause": AmazonMediaControls.Pause,
                    "next": AmazonMediaControls.Next,
                    "previous": AmazonMediaControls.Previous,
                    "rewind": AmazonMediaControls.Rewind,
                    "fast_forward": AmazonMediaControls.FastForward,
                    "forward": AmazonMediaControls.FastForward,
                }
                media_enum = media_map.get(cmd)
                if media_enum is None:
                    return web.json_response({"error": f"Comando multimedia desconocido: {cmd}"}, status=400)
                try:
                    await self.api.send_media_command(device, media_enum)
                except Exception as media_err:
                    if "Not Found" in str(media_err):
                        return web.json_response(
                            {"error": "No hay reproducción multimedia activa para controlar en este dispositivo/grupo. Usa 'Reproducir música' o 'Comando de texto' para iniciar música."},
                            status=400,
                        )
                    raise

            elif action == "dnd":
                enable = bool(params.get("enable", True))
                await self.api.set_do_not_disturb(device, enable)

            elif action == "info_skill":
                skill = params.get("skill", "weather")
                mapping = {
                    "weather": "Alexa.Weather.Play",
                    "traffic": "Alexa.Traffic.Play",
                    "flash_briefing": "Alexa.FlashBriefing.Play",
                    "time": "Alexa.Time.Play",
                    "date": "Alexa.Date.Play",
                    "calendar_today": "Alexa.Calendar.PlayToday",
                    "calendar_tomorrow": "Alexa.Calendar.PlayTomorrow",
                    "calendar_next": "Alexa.Calendar.PlayNext",
                    "joke": "Alexa.Joke.Play",
                    "fun_fact": "Alexa.FunFact.Play",
                    "tell_story": "Alexa.TellStory.Play",
                    "sing_song": "Alexa.SingASong.Play",
                    "good_morning": "Alexa.GoodMorning.Play",
                    "goodnight": "Alexa.GoodNight.Play",
                    "im_home": "Alexa.ImHome.Play",
                    "clean_up": "Alexa.CleanUp.Play",
                    "national_news": "Alexa.News.NationalNews",
                }
                alexa_skill = mapping.get(skill, skill)
                await self.api.call_alexa_info_skill(device, alexa_skill)

            elif action == "sound":
                sound_name = params.get("sound", "")
                await self.api.call_alexa_sound(device, sound_name)

            elif action == "music":
                search_phrase = params.get("search", "")
                provider_id = params.get("provider", "")
                if not provider_id:
                    providers = await self.api.music_providers
                    for preferred in ("AMAZON_MUSIC", "SPOTIFY", "TUNEIN", "APPLE_MUSIC", "DEEZER"):
                        if preferred in providers:
                            provider_id = preferred
                            break
                    if not provider_id and providers:
                        provider_id = next(iter(providers.keys()))
                await self.api.call_alexa_music(device, search_phrase, provider_id)

            elif action == "routine":
                routine_name = params.get("routine", "")
                default_serial = params.get("default_serial", "")
                if default_serial and default_serial in self.devices:
                    self.api._default_device_serial = default_serial
                await self.api.call_routine(routine_name)

            elif action == "restart":
                await self.api.restart_device(device)

            else:
                return web.json_response({"error": f"Acción desconocida: {action}"}, status=400)

            return web.json_response({"status": "ok", "action": action, "serial": serial})

        except Exception as err:
            LOGGER.error("Error ejecutando comando %s en %s: %s", action, serial, err)
            return web.json_response({"error": str(err)}, status=500)

    async def handle_routines(self, request: web.Request) -> web.Response:
        """Devuelve la lista de rutinas de Alexa disponibles en la cuenta."""
        if not self.is_authenticated or not self.api:
            return web.json_response({"error": "Demonio no autenticado"}, status=403)
        return web.json_response({"status": "ok", "routines": list(self.api.routines or [])})

    async def handle_routines_refresh(self, request: web.Request) -> web.Response:
        """Actualiza desde Amazon la lista de rutinas disponibles."""
        if not self.is_authenticated or not self.api:
            return web.json_response({"error": "Demonio no autenticado"}, status=403)
        try:
            await self.api.update_routines()
            return web.json_response({"status": "ok", "routines": list(self.api.routines or [])})
        except Exception as err:
            LOGGER.error("Error actualizando rutinas: %s", err)
            return web.json_response({"error": str(err)}, status=500)

    async def handle_reset_session(self, request: web.Request) -> web.Response:
        """Limpia la sesión de Amazon guardada en disco y memoria."""
        try:
            if self.http2_task and not self.http2_task.done():
                self.http2_task.cancel()
            if "session" in self.active_proxy:
                try:
                    await self.active_proxy["session"].close()
                except Exception:
                    pass
            self.active_proxy = {}
            self.is_authenticated = False
            self.current_user = ""
            self.devices = {}
            if os.path.exists(self.session_file):
                os.remove(self.session_file)
            return web.json_response({"status": "ok"})
        except Exception as err:
            return web.json_response({"error": str(err)}, status=500)

    async def start(self):
        """Inicia el servidor HTTP del demonio y los servicios en segundo plano."""
        self.app = web.Application()
        self.app.router.add_get("/api/health", self.handle_health)
        self.app.router.add_post("/api/login", self.handle_login)
        self.app.router.add_get("/api/devices", self.handle_devices)
        self.app.router.add_post("/api/sync", self.handle_sync)
        self.app.router.add_post("/api/command", self.handle_command)
        self.app.router.add_get("/proxy/start", self.handle_proxy_start)
        self.app.router.add_route("*", "/proxy/relay", self.handle_proxy_relay)
        self.app.router.add_get("/api/proxy_status", self.handle_proxy_status)
        self.app.router.add_post("/api/manual_code", self.handle_manual_code)
        self.app.router.add_get("/api/routines", self.handle_routines)
        self.app.router.add_post("/api/routines/refresh", self.handle_routines_refresh)
        self.app.router.add_post("/api/reset_session", self.handle_reset_session)

        self.runner = web.AppRunner(self.app)
        await self.runner.setup()
        site = web.TCPSite(self.runner, "127.0.0.1", self.port)
        await site.start()
        LOGGER.info("Demonio Alexa Devices escuchando en http://127.0.0.1:%d", self.port)

        # Intento de reconexión con sesión existente
        await self.init_session_from_disk()

        # Iniciar tarea periódica de refresco
        self.poll_task = asyncio.create_task(self.periodic_polling())

    async def stop(self):
        """Detiene de forma limpia el demonio y cierra conexiones."""
        LOGGER.info("Deteniendo demonio Alexa Devices...")
        if self.poll_task:
            self.poll_task.cancel()
        if self.api:
            try:
                await self.api.stop_http2_processing()
            except Exception:
                pass
        if self.httpx_client:
            await self.httpx_client.aclose()
        if self.session:
            await self.session.close()
        if self.runner:
            await self.runner.cleanup()
        LOGGER.info("Demonio detenido.")


async def main():
    parser = argparse.ArgumentParser(description="Demonio Alexa Devices para Jeedom")
    parser.add_argument("--port", type=int, default=55065, help="Puerto HTTP local")
    parser.add_argument("--jeedom-url", type=str, default="", help="URL de webhook Jeedom")
    parser.add_argument("--token", type=str, default="", help="Token de autenticación interno")
    parser.add_argument("--storage-dir", type=str, default="./data/storage", help="Ruta de persistencia")
    parser.add_argument("--poll-interval", type=int, default=300, help="Intervalo de sondeo en segundos")
    parser.add_argument("--loglevel", type=str, default="info", choices=["debug", "info", "warning", "error"])

    args = parser.parse_args()

    numeric_level = getattr(logging, args.loglevel.upper(), logging.INFO)
    logging.getLogger().setLevel(numeric_level)
    LOGGER.setLevel(numeric_level)
    logging.getLogger("hpack").setLevel(logging.WARNING)
    logging.getLogger("charset_normalizer").setLevel(logging.WARNING)
    if numeric_level > logging.DEBUG:
        logging.getLogger("httpx").setLevel(logging.WARNING)
        logging.getLogger("httpcore").setLevel(logging.WARNING)

        class _IgnoreHarmlessCommsWarning(logging.Filter):
            def filter(self, record: logging.LogRecord) -> bool:
                return "Failed to refresh communications settings for device" not in record.getMessage()

        logging.getLogger("aioamazondevices").addFilter(_IgnoreHarmlessCommsWarning())

    daemon = AlexaDaemon(
        port=args.port,
        jeedom_url=args.jeedom_url,
        token=args.token,
        storage_dir=args.storage_dir,
        poll_interval=args.poll_interval,
    )

    loop = asyncio.get_running_loop()
    stop_event = asyncio.Event()

    def _sig_handler():
        LOGGER.info("Señal de terminación recibida.")
        stop_event.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, _sig_handler)
        except NotImplementedError:
            # En Windows los signal handlers pueden no ser soportados en asyncio loop
            pass

    await daemon.start()

    try:
        await stop_event.wait()
    except asyncio.CancelledError:
        pass
    finally:
        await daemon.stop()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
