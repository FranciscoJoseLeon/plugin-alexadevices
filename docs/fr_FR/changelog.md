# Changelog Alexa Devices

## [0.9.1] - 27/11/2026
### Ajouté et Amélioré
- Mise à jour de la dépendance minimale vers `aioamazondevices >= 18.0.2` (meilleure synchronisation DND et logs push plus propres).
- **Push en temps réel des alarmes, minuteurs et rappels** : nouvelles commandes `next_alarm`, `next_timer` et `next_reminder` mises à jour via `on_notification_event`.
- **Correction de `media_state`** : lecture correcte de `player_state` (`PLAYING`, `PAUSED`, `IDLE`) au lieu de rester sur *Inconnu*.
- **Améliorations du proxy OAuth et de la session** : détection exacte du chemin `/ap/maplanding`, préservation du champ OTP sur les pages CVF, suppression des en-têtes `Permissions-Policy` restrictifs, fermeture propre des sessions `aiohttp` et correction de *Réinitialiser la session* en mémoire.
- **Interface** : activation automatique du bouton *Se connecter avec Amazon* au démarrage du démon et polling silencieux (`global: false`).

## [0.9.0] - 15/11/2026
### Ajouté et Amélioré
- Version bêta v0.9.0 pour Jeedom v4 / v4.4+ avec flux push HTTP/2 en temps réel (`aioamazondevices >= 17.2.12`).
- Découverte automatique des appareils Echo, TV avec Alexa et groupes d'enceintes (`WHA` / Speaker Group).
- **Widget Dashboard complet** : contrôles multimédias (`Play`, `Pause`, `Stop`, `Next`, `Previous`), curseur de volume bidirectionnel, sélecteur de **toutes** les commandes de l'appareil, listes déroulantes de sons, *info skills* et routines synchronisées, ainsi qu'un lien direct (`?` et titre) vers la configuration de l'équipement.
- **TTS sur groupes d'enceintes (`WHA`)** : `speak` diffuse le message en parallèle sur chaque membre du groupe ; prise en charge native du chuchotement (`whisper`) et des balises SSML.
- **Musique et Sons** : prise en charge de `FOURNISSEUR|recherche` (Spotify, TuneIn, Amazon Music, Apple Music, Deezer) et des commandes vocales par texte (`text_command`).
- **Testeur interactif de commandes et Routines** : exemples prêts à l'emploi en 3 langues, synchronisation des routines Alexa et guide d'intégration avec les scénarios Jeedom et la maison connectée.
