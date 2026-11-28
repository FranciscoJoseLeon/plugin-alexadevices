# Changelog Alexa Devices

## [1.0.0] - 28/11/2026
### Añadido y Mejorado
- Primera versión estable `1.0.0`.
- Botón clicable de modo No Molestar (`DND`) en el widget (Dashboard y Móvil) con actualización instantánea.
- Comando `refresh` conectado al botón de refresco del widget.

## [0.9.1] - 27/11/2026
### Añadido y Mejorado
- Actualización de dependencia mínima a `aioamazondevices >= 18.0.2` (mejora de sincronización DND y logs push más limpios).
- **Push en tiempo real de alarmas, temporizadores y recordatorios**: nuevos comandos `next_alarm`, `next_timer` y `next_reminder` actualizados mediante `on_notification_event`.
- **Corrección de `media_state`**: lectura correcta de `player_state` (`PLAYING`, `PAUSED`, `IDLE`) en lugar de quedar en *Desconocido*.
- **Mejoras del proxy OAuth y sesión**: coincidencia exacta de `/ap/maplanding` por ruta, preservación del campo OTP en páginas CVF, eliminación de cabeceras `Permissions-Policy` restrictivas, cierre limpio de sesiones `aiohttp` y corrección de `Reiniciar sesión` en memoria.
- **Interfaz**: habilitación automática del botón *Conectar con Amazon* al arrancar el demonio y sondeo silencioso (`global: false`).

## [0.9.0] - 15/11/2026
### Añadido y Mejorado
- Versión inicial beta v0.9.0 para Jeedom v4 / v4.4+ con soporte HTTP/2 push en tiempo real (`aioamazondevices >= 17.2.12`).
- Descubrimiento automático de dispositivos Echo, TV con Alexa y grupos de altavoces (`WHA` / Speaker Group).
- **Widget del Dashboard completo**: controles multimedia (`Play`, `Pause`, `Stop`, `Next`, `Previous`), deslizador de volumen bidireccional, selector desplegable de **todos** los comandos del dispositivo, desplegables de sonidos, *info skills* y rutinas sincronizadas, más enlace directo (`?` y título) a la configuración del dispositivo.
- **TTS en grupos de altavoces (`WHA`)**: `speak` envía el audio en paralelo a todos los miembros del grupo; soporte nativo de susurro (`whisper`) y etiquetas SSML.
- **Música y Sonidos**: soporte de `PROVEEDOR|búsqueda` (Spotify, TuneIn, Amazon Music, Apple Music, Deezer) y comandos de voz por texto (`text_command`).
- **Probador interactivo de comandos y Rutinas**: ejemplos listos para usar en 3 idiomas, sincronización de rutinas Alexa y guía de integración con escenarios Jeedom y acciones de hogar digital.
