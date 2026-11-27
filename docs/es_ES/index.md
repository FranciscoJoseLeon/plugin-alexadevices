# Plugin Alexa Devices para Jeedom

Plugin de integración nativa para dispositivos Amazon Echo y Alexa en Jeedom v4 / v4.4+, basado en la librería asíncrona `aioamazondevices` y el protocolo push oficial HTTP/2 de Amazon AVS.

---

## Características principales
- **Descubrimiento automático**: Detecta automáticamente todos los dispositivos Amazon Echo asociados a tu cuenta.
- **Push en tiempo real (HTTP/2)**: Conexión persistente mediante stream HTTP/2 con Amazon AVS sin sobrecargar con peticiones continuas.
- **Text-to-Speech (TTS)**: Comando para hacer hablar a cualquier Echo (`speak`).
- **Anuncios sonoros**: Difunde avisos con tono previo (`announcement`).
- **Comandos de voz por texto**: Emula órdenes habladas mediante texto (`text_command`).
- **Control multimedia y volumen**: Play, Pausa, Stop, Siguiente, Anterior, estado multimedia (`media_state`: `PLAYING`, `PAUSED`, `IDLE`) y slider de volumen (0-100).
- **Modo No Molestar (DND)**: Activación, desactivación y estado sincronizado en tiempo real.
- **Alarmas, temporizadores y recordatorios (Push HTTP/2)**: Comandos `next_alarm`, `next_timer` y `next_reminder` actualizados automáticamente mediante eventos push.
- **Sensores**: Temperatura y calidad del aire actualizados por push HTTP/2; iluminancia y presencia/movimiento actualizados mediante el polling periódico configurable.
- **Historial vocal**: Registra la última orden escuchada por el micrófono (`last_command`).
- **Autenticación oficial Amazon (2FA / OTP)**: Ventana emergente oficial de Amazon compatible con SMS, WhatsApp, notificación en app o app autenticadora.

---

## Instalación y Configuración

1. **Activar el plugin**: Ve a **Plugins > Gestión de Plugins > Alexa Devices** y haz clic en **Activar**.
2. **Instalar dependencias**: Haz clic en **Relancer / Instalar** en la sección de dependencias y arranca el demonio.
3. **Conectar tu cuenta**: En la configuración del plugin, elige tu país y pulsa **Conectar con Amazon**. Inicia sesión en la ventana de Amazon (el botón se habilita automáticamente en cuanto el demonio arranca). Si necesitas cambiar de cuenta, usa **Reiniciar sesión** en *Mantenimiento y reparación*.
4. **Sincronizar y ajustar polling**: Los dispositivos Echo se sincronizan automáticamente. En **Ajustes avanzados** puedes ajustar el *Intervalo de sondeo* (`poll_interval`, por defecto `300` s). Si actualizas el plugin y hay comandos nuevos, pulsa **Recargar comandos** (*Reload commands*).

---

## Widget del Dashboard y Opciones de Comandos

- **Selector universal de comandos**: desde la propia tarjeta del Dashboard puedes elegir cualquier comando del dispositivo (`speak`, `whisper`, `announcement`, `text_command`, `sound`, `info_skill`, `music`, `routine`, `set_volume`, `dnd_on/off`, `play/pause/stop/next/previous`).
- **Desplegables automáticos**: al seleccionar `sound`, `info_skill` o `routine`, el campo de texto cambia automáticamente a una lista con las opciones disponibles.
- **Música (`music`)**: escribe una canción, artista o emisora (o `SPOTIFY|Queen`, `TUNEIN|Los 40`, `AMAZON_MUSIC|Jazz`).
- **Acceso rápido**: pulsa el nombre del dispositivo o el botón **`?`** en el widget para abrir la ficha completa con el probador de comandos, ejemplos SSML/hogar digital y rutinas.

## Descargo de Responsabilidad y Garantía

- **No Afiliación**: Este plugin es un desarrollo independiente de la comunidad y **no** está afiliado, certificado ni respaldado por Amazon.com, Inc. o sus filiales.
- **Sin Garantía (AS IS)**: Este software se ofrece "tal cual", sin garantías expresas o implícitas. Dado que depende de servicios en la nube de terceros, Amazon puede modificar sus protocolos en cualquier momento.
- **Limitación de Responsabilidad**: En ningún caso los autores serán responsables de daños directos o indirectos, pérdidas de datos, fallos en automatizaciones domésticas o cortes de servicio derivados del uso de este software. El uso corre bajo tu propia responsabilidad.
