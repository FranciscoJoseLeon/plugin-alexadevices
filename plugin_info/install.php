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
}

function alexadevices_update() {
    log::add('alexadevices', 'info', 'Actualizando plugin Alexa Devices...');
}

function alexadevices_remove() {
    log::add('alexadevices', 'info', 'Desinstalando plugin Alexa Devices...');
    try {
        alexadevices::deamon_stop();
    } catch (Exception $e) {
        log::add('alexadevices', 'error', 'Error al detener demonio durante la desinstalación: ' . $e->getMessage());
    }
}
