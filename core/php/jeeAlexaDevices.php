<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    echo json_encode(array('error' => 'No input'));
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid JSON'));
    exit;
}

$expectedToken = config::byKey('daemon_token', 'alexadevices');
if (!empty($expectedToken) && (!isset($data['token']) || $data['token'] !== $expectedToken)) {
    http_response_code(403);
    echo json_encode(array('error' => 'Invalid token'));
    exit;
}

$eventType = isset($data['event_type']) ? $data['event_type'] : '';
$payload = isset($data['payload']) ? $data['payload'] : array();

log::add('alexadevices', 'debug', "Webhook recibido: {$eventType}");

switch ($eventType) {
    case 'devices_list':
        if (is_array($payload)) {
            foreach ($payload as $device) {
                alexadevices::createOrUpdateDevice($device);
            }
        }
        break;

    case 'volume_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $info) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic) && isset($info['volume'])) {
                    $eqLogic->checkAndUpdateCmd('volume', $info['volume']);
                }
            }
        }
        break;

    case 'media_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $info) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic) && isset($info['state'])) {
                    $eqLogic->checkAndUpdateCmd('media_state', $info['state']);
                }
            }
        }
        break;

    case 'dnd_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $enabled) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic)) {
                    $eqLogic->checkAndUpdateCmd('dnd_status', $enabled ? 1 : 0);
                }
            }
        }
        break;

    case 'history_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $rec) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic) && !empty($rec['description'])) {
                    $eqLogic->checkAndUpdateCmd('last_command', $rec['description']);
                }
            }
        }
        break;

    case 'sensor_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $sensors) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic) && is_array($sensors)) {
                    $eqLogic->updateSensors($sensors);
                }
            }
        }
        break;

    case 'notification_state':
        if (is_array($payload)) {
            foreach ($payload as $serial => $info) {
                $eqLogic = alexadevices::byLogicalId($serial, 'alexadevices');
                if (is_object($eqLogic) && is_array($info)) {
                    foreach (array('next_alarm', 'next_timer', 'next_reminder') as $cmdKey) {
                        if (array_key_exists($cmdKey, $info)) {
                            $eqLogic->checkAndUpdateCmd($cmdKey, (string)$info[$cmdKey]);
                        }
                    }
                }
            }
        }
        break;

    case 'auth_required':
        message::add('alexadevices', __('Amazon session has expired. Please log in again in the plugin configuration.', __FILE__));
        break;

    case 'reinstall_dependencies_needed':
        $reason = isset($payload['reason']) ? ' (' . $payload['reason'] . ')' : '';
        message::add('alexadevices', __('Amazon has updated its services', __FILE__) . $reason . '. ' . __('Please reinstall plugin dependencies from Plugins > Plugin management > Alexa Devices.', __FILE__));
        break;
}

echo json_encode(array('status' => 'ok'));
