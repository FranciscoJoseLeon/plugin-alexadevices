# Changelog Alexa Devices

## [0.9.1] - 2026-11-27
### Added & Improved
- Updated minimum dependency to `aioamazondevices >= 18.0.2` (improved DND state emission and cleaner push logs).
- **Real-time push for alarms, timers, and reminders**: new `next_alarm`, `next_timer`, and `next_reminder` commands updated via `on_notification_event`.
- **Fixed `media_state`**: now reads `player_state` (`PLAYING`, `PAUSED`, `IDLE`) instead of remaining *Unknown*.
- **OAuth proxy & session fixes**: exact `/ap/maplanding` path matching, OTP input preservation on CVF pages, stripped restrictive `Permissions-Policy` headers, clean `aiohttp` session closing, and fixed `Reset session` in daemon memory.
- **UI**: auto-enable *Connect with Amazon* when the daemon starts and silent background polling (`global: false`).

## [0.9.0] - 2026-11-15
### Added & Improved
- Initial v0.9.0 beta release for Jeedom v4 / v4.4+ with real-time HTTP/2 push events (`aioamazondevices >= 17.2.12`).
- Automatic discovery of Echo speakers, Alexa-enabled TVs, and Speaker Groups (`WHA`).
- **Full Dashboard Widget**: media controls (`Play`, `Pause`, `Stop`, `Next`, `Previous`), bidirectional volume slider, dropdown selector for **all** device commands, dropdown lists for built-in sounds, info skills, and synchronized routines, plus direct link (`?` and title) to device configuration.
- **Speaker Group (`WHA`) TTS**: `speak` dispatches speech in parallel to every cluster member; native `whisper` command and SSML tag support.
- **Music & Sounds**: supports `PROVIDER|query` (Spotify, TuneIn, Amazon Music, Apple Music, Deezer) and emulated voice commands (`text_command`).
- **Interactive Command Tester & Routines**: trilingual examples, Alexa routine synchronization, and guide for Jeedom scenarios and smart home actions.
