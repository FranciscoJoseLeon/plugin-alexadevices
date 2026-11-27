# Alexa Devices Plugin for Jeedom

Modern integration plugin for Amazon Echo and Alexa-enabled devices in Jeedom v4 / v4.4+, based on the asynchronous `aioamazondevices` library and Amazon's official HTTP/2 push protocol.

---

## Features
- **Automatic Discovery**: Automatically discovers all Amazon Echo devices (Dot, Show, Pop, Studio, Fire TV, etc.) linked to your Amazon account.
- **Real-Time Push (HTTP/2)**: Persistent stream connection with Amazon AVS. Volume changes, sensors, media state, and voice commands update instantly without polling delays.
- **Text-to-Speech (TTS)**: Send text to make any Echo speaker speak (`speak`).
- **Announcements**: Broadcast attention chimes and spoken messages across devices (`announcement`).
- **Text Voice Commands**: Execute voice commands via text (`text_command`), e.g., *"turn on the living room light"*, *"play jazz on Spotify"*.
- **Full Media Player Control**: Play, Pause, Stop, Next, Previous, and `media_state` reporting (*PLAYING*, *PAUSED*, *IDLE*).
- **Bidirectional Volume Control**: 0-100 slider with live feedback.
- **Do Not Disturb (DND)**: Enable, disable, and live-synced status.
- **Alarms, Timers & Reminders (HTTP/2 Push)**: `next_alarm`, `next_timer`, and `next_reminder` commands updated automatically via push events.
- **Sensors**: Temperature and air quality updated via HTTP/2 push; illuminance and motion/presence updated via configurable periodic polling.
- **Voice History**: Displays the last voice command captured by each Echo microphone (`last_command`).
- **Official Amazon Authentication (2FA / OTP)**: Official Amazon sign-in window supporting SMS, WhatsApp, app approval, and authenticator apps.

---

## Installation & Configuration

1. **Activate the plugin**: Navigate to **Plugins > Plugin management > Alexa Devices** and click **Enable**.
2. **Install Dependencies**: Click **Relaunch / Install** in the Dependencies section to set up the Python 3 virtual environment and start the daemon.
3. **Connect to Amazon**: In the plugin configuration, select your Amazon country and click **Connect with Amazon** (automatically enabled once the daemon starts). To switch accounts, use **Reset session** under *Maintenance & Repair*.
4. **Discover & Polling**: Devices sync automatically. Under **Advanced settings**, you can adjust the *Polling Interval* (`poll_interval`, default `300` s). After plugin updates that add new commands, click **Reload commands**.

---

## Dashboard Widget & Command Options

- **Universal Command Selector**: directly from the Dashboard tile, select any device command (`speak`, `whisper`, `announcement`, `text_command`, `sound`, `info_skill`, `music`, `routine`, `set_volume`, `dnd_on/off`, `play/pause/stop/next/previous`).
- **Automatic Dropdowns**: selecting `sound`, `info_skill`, or `routine` automatically switches the input to a dropdown of available options.
- **Music (`music`)**: enter a song, artist, or radio station (or `SPOTIFY|Queen`, `TUNEIN|Los 40`, `AMAZON_MUSIC|Jazz`).
- **Quick Access**: click the device title or the **`?`** button on the widget to open the full device page with the interactive command tester, SSML/smart-home examples, and routines.

## Disclaimer & Limitation of Liability

- **Non-Affiliation**: This software is an independent community project and is **not** endorsed, certified, or affiliated with Amazon.com, Inc. or Amazon Technologies, Inc. All trademarks belong to their respective owners.
- **Warranty Disclaimer**: This plugin is provided **"as is"**, without warranty of any kind, express or implied. Third-party cloud APIs are subject to change without notice.
- **Limitation of Liability**: Under no circumstances shall the authors or contributors be held liable for any direct or indirect damages, data loss, home automation failures, or service interruptions resulting from the use or inability to use this plugin. Use at your own risk.
