# Plugin Alexa Devices pour Jeedom

Plugin d'intégration moderne pour les appareils Amazon Echo et compatibles Alexa dans Jeedom v4 / v4.4+, basé sur la bibliothèque asynchrone `aioamazondevices` et le protocole officiel de push HTTP/2 d'Amazon.

---

## Fonctionnalités principales
- **Découverte automatique** : Détecte automatiquement tous les appareils Amazon Echo (Dot, Show, Pop, Studio, Fire TV, etc.) associés à votre compte Amazon.
- **Push en temps réel (HTTP/2)** : Connexion persistante par flux HTTP/2 avec Amazon AVS. Les changements de volume, de capteurs, d'état multimédia et de commandes vocales sont répercutés instantanément sans requêtes de scrutation intensives.
- **Synthèse vocale (TTS)** : Commande pour faire parler n'importe quel Echo (`speak`).
- **Annonces** : Diffuse des annonces sonores avec carillon d'attention préalable (`announcement`).
- **Commandes vocales textuelles** : Permet de simuler des ordres vocaux par écrit (`text_command`), par exemple : *"allume la lumière du salon"*, *"mets du jazz"*.
- **Contrôle multimédia complet** : Lecture, Pause, Stop, Suivant, Précédent et état actuel (`media_state` : *PLAYING*, *PAUSED*, *IDLE*).
- **Contrôle de volume bidirectionnel** : Curseur de 0 à 100 avec synchronisation en direct.
- **Mode Ne pas déranger (DND)** : Activation, désactivation et statut synchronisé en temps réel.
- **Alarmes, minuteurs et rappels (Push HTTP/2)** : Commandes `next_alarm`, `next_timer` et `next_reminder` mises à jour automatiquement par événements push.
- **Capteurs** : Température et qualité de l'air mis à jour par push HTTP/2 ; luminosité et présence/mouvement mis à jour par le polling périodique configurable.
- **Historique vocal** : Affiche la dernière commande vocale entendue par le microphone de l'Echo (`last_command`).
- **Authentification officielle Amazon (2FA / OTP)** : Fenêtre officielle de connexion Amazon compatible SMS, WhatsApp, notification applicative ou application d'authentification.

---

## Installation et configuration

1. **Activer le plugin** : Rendez-vous dans **Plugins > Gestion des plugins > Alexa Devices** et cliquez sur **Activer**.
2. **Installer les dépendances** : Cliquez sur **Relancer / Installer** dans la section Dépendances pour initialiser l'environnement virtuel Python 3 et démarrez le démon.
3. **Se connecter à Amazon** : Dans la configuration du plugin, choisissez votre pays Amazon et cliquez sur **Se connecter avec Amazon** (activé automatiquement dès que le démon démarre). Pour changer de compte, utilisez **Réinitialiser la session** dans *Maintenance & Réparations*.
4. **Découverte et polling** : Les appareils se synchronisent automatiquement. Dans **Paramètres avancés**, vous pouvez ajuster l'*Intervalle de sondage* (`poll_interval`, `300` s par défaut). Après une mise à jour ajoutant des commandes, cliquez sur **Recharger les commandes**.

---

## Widget Dashboard et Options des Commandes

- **Sélecteur universel de commandes** : depuis la tuile du Dashboard, choisissez n'importe quelle commande de l'appareil (`speak`, `whisper`, `announcement`, `text_command`, `sound`, `info_skill`, `music`, `routine`, `set_volume`, `dnd_on/off`, `play/pause/stop/next/previous`).
- **Listes déroulantes automatiques** : en sélectionnant `sound`, `info_skill` ou `routine`, le champ se transforme en liste des options disponibles.
- **Musique (`music`)** : saisissez un titre, artiste ou station (ou `SPOTIFY|Queen`, `TUNEIN|Los 40`, `AMAZON_MUSIC|Jazz`).
- **Accès rapide** : cliquez sur le nom de l'appareil ou sur le bouton **`?`** du widget pour ouvrir la fiche complète avec le testeur de commandes, exemples SSML/domotique et routines.

## Avertissement et limitation de responsabilité

- **Non-affiliation** : Ce plugin est un projet communautaire indépendant et n'est **en aucun cas** affilié, approuvé ou certifié par Amazon.com, Inc. ou Amazon Technologies, Inc. Toutes les marques appartiennent à leurs propriétaires respectifs.
- **Exclusion de garantie** : Ce logiciel est fourni **"en l'état" (AS IS)**, sans garantie d'aucune sorte, expresse ou implicite. Les protocoles et API tiers dans le cloud peuvent être modifiés sans préavis par Amazon.
- **Limitation de responsabilité** : Les auteurs et contributeurs ne peuvent en aucun cas être tenus responsables des dommages directs ou indirects, pertes de données, interruptions de service ou dysfonctionnements domotiques résultant de l'utilisation de ce logiciel. L'utilisation se fait sous votre seule responsabilité.
