# Alexa Devices — Plugin Jeedom

[![Jeedom Compatibility](https://img.shields.io/badge/Jeedom-v4.2%2B-24b35b.svg)](https://www.jeedom.com)
[![Version](https://img.shields.io/badge/version-0.9.0-blue.svg)](https://github.com/FranciscoJoseLeon/plugin-alexadevices)
[![Languages](https://img.shields.io/badge/lang-fr__FR%20%7C%20en__US%20%7C%20es__ES-informational.svg)](#readme)
[![License: GPL](https://img.shields.io/badge/License-GPL-blue.svg)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support_My_Work-FF5E5B?style=flat&logo=ko-fi&logoColor=white)](https://ko-fi.com/fjleon)

🌐 **Languages / Langues / Idiomas :** [🇫🇷 Français](#-français) • [🇬🇧 English](#-english) • [🇪🇸 Español](#-español)

📚 **Documentation complète / Full Documentation / Documentación completa :**
- 🇫🇷 [Documentation (fr_FR)](docs/fr_FR/index.md) • [Changelog (fr_FR)](docs/fr_FR/changelog.md)
- 🇬🇧 [Documentation (en_US)](docs/en_US/index.md) • [Changelog (en_US)](docs/en_US/changelog.md)
- 🇪🇸 [Documentación (es_ES)](docs/es_ES/index.md) • [Changelog (es_ES)](docs/es_ES/changelog.md)

---

## 🇫🇷 Français

Plugin d'intégration native pour les appareils **Amazon Echo et Alexa** dans **Jeedom v4.2+**, basé sur la bibliothèque asynchrone moderne [`aioamazondevices`](https://github.com/chemelli74/aioamazondevices) avec prise en charge du protocole push temps réel **HTTP/2 d'Amazon AVS**.

### 🚀 Fonctionnalités principales

- **Découverte automatique** : Détecte tous vos appareils Echo (Dot, Show, Pop, Studio, Fire TV, groupes multiroom, etc.) et affiche uniquement le modèle correspondant (avec sélecteur de modèle).
- **Push HTTP/2 en temps réel** : Connexion persistante sans polling agressif, mettant à jour instantanément le volume, l'état multimédia, les capteurs et la dernière commande vocale.
- **Widget Dashboard dédié** : Contrôle complet depuis le Dashboard (Lecture, Pause, **Stop**, Précédent, Suivant, Volume bidirectionnel, Ne pas déranger, sélection de sons/musique par liste déroulante, TTS, Annonces, Commandes texte). Un clic sur l'en-tête ouvre directement la configuration de l'équipement.
- **Authentification sécurisée 2FA / OTP** : Assistant interactif compatible avec la vérification en deux étapes Amazon.
- **Capteurs intégrés** : Température et détection de présence sur les appareils compatibles.

### 🛠️ Installation depuis GitHub dans Jeedom

1. Dans Jeedom, allez dans **Plugins → Gestion des plugins → Ajouter un plugin depuis GitHub** :
   - **ID logique du plugin** : `alexadevices`
   - **Utilisateur ou organisation** : `FranciscoJoseLeon`
   - **Nom du dépôt** : `plugin-alexadevices`
   - **Branche** : `main` (ou `beta` pour les dernières nouveautés)
2. Activez le plugin et installez les **Dépendances** (Python 3 + environnement virtuel).
3. Configurez votre domaine Amazon, connectez-vous via **Connexion / 2FA OTP**, démarrez le **Démon**, puis cliquez sur **Synchroniser les appareils**.

### ⚖️ Avertissement

Ce plugin est un projet communautaire open source sous licence **GPL**, non affilié ni approuvé par Amazon.com, Inc. ou Jeedom SAS. Fourni « tel quel », sans garantie.

---

## 🇬🇧 English

Native integration plugin for **Amazon Echo and Alexa** devices in **Jeedom v4.2+**, powered by the modern asynchronous library [`aioamazondevices`](https://github.com/chemelli74/aioamazondevices) with **Amazon AVS HTTP/2** real-time push support.

### 🚀 Key Features

- **Automatic Discovery**: Detects all Echo speakers and groups (Dot, Show, Pop, Studio, Fire TV, multiroom groups) and displays the matching device model (with optional model override).
- **Real-Time HTTP/2 Push**: Persistent connection avoiding aggressive polling, updating volume, playback state, sensors, and last voice command instantly.
- **Dedicated Dashboard Widget**: Full control from the Jeedom Dashboard (Play, Pause, **Stop**, Previous, Next, bidirectional Volume slider, Do Not Disturb, dropdown selectors for sounds/music, TTS, Announcements, and Text Commands). Clicking the widget header opens the device configuration page directly.
- **Secure 2FA / OTP Authentication**: Interactive login modal supporting Amazon two-step verification.
- **Built-in Sensors**: Temperature and presence detection on supported Echo devices.

### 🛠️ Installation from GitHub in Jeedom

1. In Jeedom, go to **Plugins → Plugin Management → Add plugin from GitHub**:
   - **Plugin logical ID**: `alexadevices`
   - **User or organization**: `FranciscoJoseLeon`
   - **Repository name**: `plugin-alexadevices`
   - **Branch**: `main` (or `beta` for latest updates)
2. Activate the plugin and install **Dependencies** (Python 3 + virtual environment).
3. Select your Amazon domain, sign in via **Login / 2FA OTP**, start the **Daemon**, and click **Sync Devices**.

### ⚖️ Disclaimer

This plugin is an independent open-source community project licensed under **GPL**, not affiliated with or endorsed by Amazon.com, Inc. or Jeedom SAS. Provided "AS IS" without warranty of any kind.

---

## 🇪🇸 Español

Plugin de integración nativa para dispositivos **Amazon Echo y Alexa** en **Jeedom v4.2+**, basado en la librería asíncrona moderna [`aioamazondevices`](https://github.com/chemelli74/aioamazondevices) con soporte para eventos push en tiempo real **HTTP/2 de Amazon AVS**.

### 🚀 Características principales

- **Descubrimiento automático**: Detecta todos tus altavoces y grupos Echo (Dot, Show, Pop, Studio, Fire TV, grupos multiroom) mostrando el modelo exacto correspondiente (con selector opcional para cambiarlo).
- **Push HTTP/2 en tiempo real**: Conexión persistente sin sondeos agresivos, reflejando al instante cambios de volumen, estado multimedia, sensores y último comando vocal.
- **Widget dedicado en el Dashboard**: Control completo desde el Dashboard (Reproducir, Pausar, **Parar**, Anterior, Siguiente, Volumen bidireccional, No Molestar/DND, desplegable de sonidos/música, TTS, Anuncios y Comandos de texto). Al pulsar en la cabecera del widget se abre la configuración del dispositivo.
- **Autenticación segura 2FA / OTP**: Asistente interactivo compatible con verificación en dos pasos de Amazon.
- **Sensores integrados**: Temperatura y detección de presencia en dispositivos Echo compatibles.

### 🛠️ Instalación desde GitHub en Jeedom

1. En Jeedom, ve a **Plugins → Gestión de plugins → Añadir un plugin desde GitHub**:
   - **ID lógico del plugin**: `alexadevices`
   - **Usuario u organización**: `FranciscoJoseLeon`
   - **Nombre del repositorio**: `plugin-alexadevices`
   - **Rama**: `main` (o `beta` para las últimas mejoras)
2. Activa el plugin e instala las **Dependencias** (Python 3 + entorno virtual).
3. Elige tu dominio de Amazon, inicia sesión con **Iniciar sesión / 2FA OTP**, arranca el **Demonio** y pulsa **Sincronizar dispositivos**.

### ⚖️ Exención de responsabilidad

Este plugin es un proyecto comunitario de código abierto bajo licencia **GPL**, sin afiliación ni respaldo oficial de Amazon.com, Inc. ni de Jeedom SAS. Se entrega «tal cual», sin garantías.

---

Developed by **Francisco José León** ([@FranciscoJoseLeon](https://github.com/FranciscoJoseLeon))
