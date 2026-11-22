<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function alexadevices_install() {
    log::add('alexadevices', 'info', 'Instalando plugin Alexa Devices...');
    alexadevices_migrate_from_legacy();
}

function alexadevices_update() {
    log::add('alexadevices', 'info', 'Actualizando plugin Alexa Devices...');
    alexadevices_migrate_from_legacy();
}

function alexadevices_remove() {
    log::add('alexadevices', 'info', 'Desinstalando plugin Alexa Devices...');
    try {
        alexadevices::deamon_stop();
    } catch (Exception $e) {
        log::add('alexadevices', 'error', 'Error al detener demonio durante la desinstalación: ' . $e->getMessage());
    }
}

/**
 * Migra automáticamente configuraciones, cookies y equipos del plugin antiguo (alexa_devices)
 * al nuevo (alexadevices) para evitar que el usuario tenga que reconfigurar o rehacer escenarios.
 */
function alexadevices_migrate_from_legacy() {
    try {
        // 1. Migrar archivos de sesión/cookies (daemon_session.json, etc.)
        $legacyStorage = dirname(__FILE__) . '/../../../../plugins/alexa_devices/resources/data/storage';
        $newStorage = dirname(__FILE__) . '/../../resources/data/storage';
        if (file_exists($legacyStorage) && is_dir($legacyStorage)) {
            if (!file_exists($newStorage)) {
                @mkdir($newStorage, 0775, true);
            }
            $files = scandir($legacyStorage);
            if (is_array($files)) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && !file_exists($newStorage . '/' . $file)) {
                        @copy($legacyStorage . '/' . $file, $newStorage . '/' . $file);
                        log::add('alexadevices', 'info', 'Copiada sesión/archivo ' . $file . ' desde alexa_devices');
                    }
                }
            }
        }

        // 2. Migrar configuración del plugin (credenciales, cookies, puerto, dominio)
        $legacyConfigs = config::byKeys(array(), 'alexa_devices');
        if (is_array($legacyConfigs) && count($legacyConfigs) > 0) {
            foreach ($legacyConfigs as $key => $val) {
                if (config::byKey($key, 'alexadevices', null) === null) {
                    config::save($key, $val, 'alexadevices');
                }
            }
            log::add('alexadevices', 'info', 'Configuración de plugin transferida desde alexa_devices.');
        }

        // 3. Migrar equipos y comandos en base de datos
        $legacyEqLogics = eqLogic::byType('alexa_devices');
        if (is_array($legacyEqLogics) && count($legacyEqLogics) > 0) {
            $count = count($legacyEqLogics);
            log::add('alexadevices', 'info', 'Detectados ' . $count . ' equipos en alexa_devices. Migrando a alexadevices...');
            
            DB::Prepare("UPDATE eqLogic SET eqType_name = 'alexadevices' WHERE eqType_name = 'alexa_devices'", array(), DB::FETCH_TYPE_ROW);
            DB::Prepare("UPDATE cmd SET eqType = 'alexadevices' WHERE eqType = 'alexa_devices'", array(), DB::FETCH_TYPE_ROW);
            
            log::add('alexadevices', 'info', 'Migración de ' . $count . ' equipos y sus comandos completada. Los IDs de equipos y comandos se han mantenido intactos para preservar los escenarios.');
        }
    } catch (Exception $e) {
        log::add('alexadevices', 'error', 'Error en la migración automática desde alexa_devices: ' . $e->getMessage());
    }
}
