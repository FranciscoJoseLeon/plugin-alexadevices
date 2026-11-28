<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class alexadevices extends eqLogic {

    /* =========================================================================
     * GESTIÓN DEL DEMONIO (DAEMON)
     * ========================================================================= */

    public static function deamon_info() {
        $return = array();
        $return['log'] = 'alexadevices_daemon';
        $return['state'] = 'nok';

        $pid_file = jeedom::getTmpFolder('alexadevices') . '/daemon.pid';

        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            $isRunning = false;
            if ($pid > 0) {
                if (function_exists('posix_kill')) {
                    $isRunning = @posix_kill($pid, 0);
                } else {
                    $isRunning = file_exists('/proc/' . $pid);
                }
            }
            if ($isRunning) {
                $return['state'] = 'ok';
            } else {
                try {
                    $health = self::callDaemon('/api/health', array(), 'GET');
                    if (isset($health['status']) && $health['status'] == 'ok') {
                        $return['state'] = 'ok';
                    } else {
                        @unlink($pid_file);
                    }
                } catch (Exception $e) {
                    @unlink($pid_file);
                }
            }
        } else {
            try {
                $health = self::callDaemon('/api/health', array(), 'GET');
                if (isset($health['status']) && $health['status'] == 'ok') {
                    $return['state'] = 'ok';
                }
            } catch (Exception $e) {
                // Demonio inactivo
            }
        }

        $return['launchable'] = 'ok';
        $return['auto'] = (config::byKey('deamonAutoMode', 'alexadevices', 1) == 1) ? 1 : 0;
        return $return;
    }

    public static function cron5() {
        $deamon_info = self::deamon_info();
        if ($deamon_info['auto'] == 1 && $deamon_info['state'] != 'ok') {
            log::add('alexadevices', 'info', 'Watchdog: Daemon is stopped, restarting...');
            self::deamon_start();
        }
    }

    public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Please verify plugin configuration and dependencies.', __FILE__));
        }

        $port = config::byKey('daemon_port', 'alexadevices', 55065);
        $poll_interval = config::byKey('poll_interval', 'alexadevices', 300);
        $token = config::byKey('daemon_token', 'alexadevices');
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            config::save('daemon_token', $token, 'alexadevices');
        }

        $internal_ip = config::byKey('internalAddr');
        if (empty($internal_ip)) {
            $internal_ip = '127.0.0.1';
        }
        $jeedom_url = 'http://127.0.0.1/plugins/alexadevices/core/php/jeeAlexaDevices.php';

        $path = realpath(dirname(__FILE__) . '/../../resources');
        $python_bin = $path . '/demond/venv/bin/python3';
        if (!file_exists($python_bin)) {
            $python_bin = 'python3';
        }

        $daemon_script = $path . '/demond/alexadevices_daemon.py';
        $storage_dir = $path . '/data/storage';

        $cmd = $python_bin . ' ' . $daemon_script;
        $cmd .= ' --port ' . intval($port);
        $cmd .= ' --jeedom-url ' . escapeshellarg($jeedom_url);
        $cmd .= ' --token ' . escapeshellarg($token);
        $cmd .= ' --storage-dir ' . escapeshellarg($storage_dir);
        $cmd .= ' --poll-interval ' . intval($poll_interval);

        $log_level = log::getLogLevel('alexadevices');
        $log_str = 'info';
        if ($log_level <= 100) $log_str = 'debug';
        elseif ($log_level >= 400) $log_str = 'error';
        $cmd .= ' --loglevel ' . $log_str;

        $log_file = log::getPathToLog('alexadevices_daemon');
        $pid_file = jeedom::getTmpFolder('alexadevices') . '/daemon.pid';
        $full_cmd = $cmd . ' >> ' . $log_file . ' 2>&1 & echo $! > ' . $pid_file;

        log::add('alexadevices', 'info', 'Starting Alexa Devices daemon: ' . $full_cmd);
        exec($full_cmd);

        $i = 0;
        while ($i < 10) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }

        if ($i >= 10) {
            log::add('alexadevices', 'error', 'Could not start Alexa Devices daemon');
            return false;
        }

        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder('alexadevices') . '/daemon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0) {
                $sigterm = defined('SIGTERM') ? SIGTERM : 15;
                $sigkill = defined('SIGKILL') ? SIGKILL : 9;

                if (function_exists('posix_kill')) {
                    @posix_kill($pid, $sigterm);
                    sleep(1);
                    if (@posix_kill($pid, 0)) {
                        @posix_kill($pid, $sigkill);
                    }
                } elseif (function_exists('exec')) {
                    $sudo = (class_exists('system') && method_exists('system', 'getCmdSudo')) ? system::getCmdSudo() : 'sudo ';
                    exec($sudo . 'kill -' . intval($sigterm) . ' ' . $pid . ' 2>/dev/null');
                    sleep(1);
                    exec($sudo . 'kill -' . intval($sigkill) . ' ' . $pid . ' 2>/dev/null');
                }
            }
            @unlink($pid_file);
        }

        // Asegurar que no quede ningún proceso huérfano bloqueando el puerto
        $port = config::byKey('daemon_port', 'alexadevices', 55065);
        if (function_exists('exec')) {
            $sudo = (class_exists('system') && method_exists('system', 'getCmdSudo')) ? system::getCmdSudo() : 'sudo ';
            exec($sudo . 'fuser -k ' . intval($port) . '/tcp 2>/dev/null');
            exec($sudo . 'pkill -f "alexadevices_daemon.py" 2>/dev/null');
        }

        log::add('alexadevices', 'info', 'Alexa Devices daemon stopped.');
    }

    public static function deamon_changeAutoMode($_mode) {
        config::save('deamonAutoMode', $_mode, 'alexadevices');
    }

    public static function dependancy_info() {
        $return = array();
        $return['log'] = log::getPathToLog('alexadevices_dep');
        $return['progress_file'] = '/tmp/jeedom_install_in_progress_alexadevices';
        $return['state'] = 'ok';

        $venv_py = dirname(__FILE__) . '/../../resources/demond/venv/bin/python3';
        $req_file = dirname(__FILE__) . '/../../resources/requirements.txt';

        if (!file_exists($venv_py)) {
            $return['state'] = 'nok';
            return $return;
        }

        // Obtener versión requerida en requirements.txt
        $target_version = '18.0.2';
        if (file_exists($req_file)) {
            $content = file_get_contents($req_file);
            if (preg_match('/aioamazondevices[>=~]=?([0-9.]+)/', $content, $m)) {
                $target_version = $m[1];
            }
        }

        // Comprobar versión instalada en el venv de Python
        $installed_version = trim(shell_exec($venv_py . ' -c "import importlib.metadata; print(importlib.metadata.version(\'aioamazondevices\'))" 2>/dev/null'));
        if (empty($installed_version) || version_compare($installed_version, $target_version, '<')) {
            $return['state'] = 'nok';
            log::add('alexadevices', 'warning', "Dependencias desactualizadas: aioamazondevices instalada (" . ($installed_version ?: 'ninguna') . ") < requerida ({$target_version}). Reinstalación necesaria.");
        }

        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
        }

        return $return;
    }

    public static function dependancy_install() {
        log::remove('alexadevices_dep');
        return array('script' => dirname(__FILE__) . '/../../resources/install_apt.sh', 'log' => log::getPathToLog('alexadevices_dep'));
    }

    /* =========================================================================
     * COMUNICACIÓN CON EL DEMONIO LOCAL (REST API)
     * ========================================================================= */

    public static function callDaemon($endpoint, $data = array(), $method = 'POST') {
        $port = config::byKey('daemon_port', 'alexadevices', 55065);
        $url = 'http://127.0.0.1:' . $port . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception("Error de conexión con el demonio: " . $err);
        }

        $res = json_decode($response, true);
        if ($http_code >= 400) {
            $msg = isset($res['error']) ? $res['error'] : 'HTTP ' . $http_code;
            throw new Exception("Error del demonio: " . $msg);
        }

        return $res;
    }

    /* =========================================================================
     * SINCRONIZACIÓN Y CREACIÓN DE DISPOSITIVOS
     * ========================================================================= */

    public static function syncDevices() {
        $res = self::callDaemon('/api/sync', array(), 'POST');
        try {
            self::syncRoutines(false);
        } catch (Exception $e) {
            log::add('alexadevices', 'debug', 'No se pudieron sincronizar rutinas automáticamente: ' . $e->getMessage());
        }
        return $res;
    }

    public static function isSleepModeActive() {
        if (config::byKey('sleep_protection_enabled', 'alexadevices', 0) != 1) {
            return false;
        }
        $start = intval(config::byKey('sleep_start_hour', 'alexadevices', 22));
        $end = intval(config::byKey('sleep_end_hour', 'alexadevices', 7));
        $now = intval(date('G'));
        if ($start == $end) {
            return false;
        }
        if ($start < $end) {
            return ($now >= $start && $now < $end);
        }
        return ($now >= $start || $now < $end);
    }

    public static function syncRoutines($forceRefresh = true) {
        $endpoint = $forceRefresh ? '/api/routines/refresh' : '/api/routines';
        $method = $forceRefresh ? 'POST' : 'GET';
        $res = self::callDaemon($endpoint, array(), $method);
        $routines = isset($res['routines']) && is_array($res['routines']) ? $res['routines'] : array();
        sort($routines, SORT_NATURAL | SORT_FLAG_CASE);
        config::save('routines_list', json_encode($routines), 'alexadevices');
        config::save('routines_updated_at', date('Y-m-d H:i:s'), 'alexadevices');

        $listParts = array();
        foreach ($routines as $r) {
            $safe = str_replace(array(';', '|'), ' ', $r);
            $listParts[] = $safe . '|' . $safe;
        }
        $listValue = implode(';', $listParts);
        foreach (self::byType('alexadevices') as $eqLogic) {
            $cmd = $eqLogic->getCmd(null, 'routine_select');
            if (is_object($cmd)) {
                $cmd->setConfiguration('listValue', $listValue);
                $cmd->save();
            }
        }
        return $routines;
    }

    public static function reloadAllCommands() {
        $count = 0;
        foreach (self::byType('alexadevices') as $eqLogic) {
            $eqLogic->createDefaultCommands();
            $count++;
        }
        return $count;
    }

    public static function deleteAllAndRescan() {
        foreach (self::byType('alexadevices') as $eqLogic) {
            $eqLogic->remove();
        }
        return self::syncDevices();
    }

    public static function resetAmazonSession() {
        try {
            self::callDaemon('/api/reset_session', array(), 'POST');
        } catch (Exception $e) {
            $sessionFile = dirname(__FILE__) . '/../../resources/data/storage/daemon_session.json';
            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
            }
        }
        return true;
    }

    public static function createOrUpdateDevice($device) {
        $serial = $device['serial_number'];
        $name = !empty($device['account_name']) ? $device['account_name'] : $serial;

        $eqLogic = self::byLogicalId($serial, 'alexadevices');
        if (!is_object($eqLogic)) {
            $eqLogic = new self();
            $eqLogic->setLogicalId($serial);
            $eqLogic->setEqType_name('alexadevices');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $defaultObj = config::byKey('default_object_id', 'alexadevices', '');
            if (!empty($defaultObj) && is_object(jeeObject::byId($defaultObj))) {
                $eqLogic->setObject_id($defaultObj);
            }
        }

        $devType = isset($device['device_type']) ? strtoupper((string)$device['device_type']) : '';
        $hwVersion = isset($device['hardware_version']) ? trim((string)$device['hardware_version']) : '';
        if ($hwVersion === '' && $devType !== '') {
            $hwFallback = array(
                'A3EH2E0YZ30OD6' => '2024 / 2nd Gen',
                'A10A33FOX2NUBK' => '1st Gen',
                'A2U21SRK4QGSE1' => '4th Gen',
                'A3RMGO6LYLH7YN' => '4th Gen',
                'A3FX4UWTP28V1P' => '3rd Gen',
                'A32DOYMUN6DTXA' => '3rd Gen',
                'A3S5BH2HU6VAYF' => '2nd Gen',
            );
            if (isset($hwFallback[$devType])) {
                $hwVersion = $hwFallback[$devType];
            }
        }

        $eqLogic->setName($name);
        $eqLogic->setConfiguration('device_family', isset($device['device_family']) ? $device['device_family'] : '');
        $eqLogic->setConfiguration('device_type', isset($device['device_type']) ? $device['device_type'] : '');
        $eqLogic->setConfiguration('software_version', isset($device['software_version']) ? $device['software_version'] : '');
        $eqLogic->setConfiguration('model', isset($device['model']) ? $device['model'] : '');
        $eqLogic->setConfiguration('hardware_version', $hwVersion);
        $eqLogic->setConfiguration('manufacturer', isset($device['manufacturer']) ? $device['manufacturer'] : '');
        $eqLogic->setConfiguration('primary_display_category', isset($device['primary_display_category']) ? $device['primary_display_category'] : '');
        $eqLogic->setConfiguration('media_player_supported', !empty($device['media_player_supported']) ? 1 : 0);
        $eqLogic->setConfiguration('notifications_supported', !empty($device['notifications_supported']) ? 1 : 0);
        $eqLogic->setConfiguration('voice_control_supported', !empty($device['voice_control_supported']) ? 1 : 0);
        $eqLogic->save();

        // Crear/actualizar comandos por defecto para este dispositivo Echo
        $eqLogic->createDefaultCommands($device);

        // Actualizar estado online
        if (isset($device['online'])) {
            $eqLogic->checkAndUpdateCmd('status', $device['online'] ? 1 : 0);
        }

        return $eqLogic;
    }

    /**
     * Resolves a distinct SVG image path and human-readable model/generation label for an eqLogic.
     */
    public static function resolveDeviceVisual($eqLogic) {
        $base = 'plugins/alexadevices/desktop/img/devices/';
        if (method_exists($eqLogic, 'isAirQualityMonitor') && $eqLogic->isAirQualityMonitor()) {
            $m = trim((string)$eqLogic->getConfiguration('model', ''));
            return array(
                'image' => $base . 'air_quality_monitor.svg',
                'label' => !empty($m) ? $m : __('Smart Air Quality Monitor', __FILE__)
            );
        }

        $family = strtoupper((string)$eqLogic->getConfiguration('device_family', 'ECHO'));
        $type = strtoupper((string)$eqLogic->getConfiguration('device_type', ''));
        $model = trim((string)$eqLogic->getConfiguration('model', ''));
        $hw = trim((string)$eqLogic->getConfiguration('hardware_version', ''));
        $mfg = trim((string)$eqLogic->getConfiguration('manufacturer', ''));
        $name = (string)$eqLogic->getName();

        $mLow = strtolower($model);
        $hwLow = strtolower($hw);
        $base = 'plugins/alexadevices/desktop/img/devices/';

        // Determinar imagen exacta según modelo, generación y tipo
        if ($family === 'WHA' || in_array($type, array('A3C9PE6TNYLTCH', 'AP1F6KUH00XPV'), true) || strpos($mLow, 'speaker group') !== false || strpos($mLow, 'stereo pair') !== false) {
            $file = 'speaker_group.svg';
        } elseif (strpos($family, 'FIRE_TV') !== false || strpos($mLow, 'fire tv') !== false || strpos($mLow, 'cube') !== false) {
            $file = 'fire_tv.svg';
        } elseif (strpos($family, 'VIDEO') !== false || strpos($mLow, 'tv') !== false || (!empty($mfg) && strcasecmp($mfg, 'Amazon') !== 0 && preg_match('/tv|qled|oled/i', $name))) {
            $file = 'smart_tv.svg';
        } elseif (strpos($mLow, 'spot') !== false || in_array($type, array('A3EH2E0YZ30OD6', 'A10A33FOX2NUBK'), true) || stripos($name, 'Spot') !== false) {
            $file = ($type === 'A10A33FOX2NUBK' || strpos($hwLow, '1st') !== false) ? 'echo_spot_gen1.svg' : 'echo_spot_2024.svg';
        } elseif (strpos($mLow, 'show') !== false || strpos($family, 'KNIGHT') !== false || stripos($name, 'Show') !== false) {
            if (strpos($mLow, '15') !== false || strpos($mLow, '21') !== false) {
                $file = 'echo_show_15.svg';
            } elseif (strpos($mLow, '10') !== false) {
                $file = 'echo_show_10.svg';
            } else {
                $file = 'echo_show_compact.svg';
            }
        } elseif (strpos($mLow, 'pop') !== false || preg_match('/\bpop\b/i', $name)) {
            $file = 'echo_pop.svg';
        } elseif (strpos($mLow, 'dot') !== false || in_array($type, array('A2U21SRK4QGSE1', 'A32DOYMUN6DTXA', 'A3S5BH2HU6VAYF', 'A2M35JJZWCQOMZ'), true)) {
            if (strpos($mLow, 'clock') !== false || stripos($name, 'clock') !== false || stripos($name, 'reloj') !== false) {
                $file = 'echo_dot_clock.svg';
            } elseif (strpos($hwLow, '1st') !== false || strpos($hwLow, '2nd') !== false || $type === 'A3S5BH2HU6VAYF') {
                $file = 'echo_dot_gen1_2.svg';
            } elseif (strpos($hwLow, '3rd') !== false || $type === 'A32DOYMUN6DTXA') {
                $file = 'echo_dot_gen3.svg';
            } else {
                $file = 'echo_dot_gen4_5.svg';
            }
        } elseif (strpos($mLow, 'studio') !== false) {
            $file = 'echo_studio.svg';
        } elseif (strpos($mLow, 'flex') !== false) {
            $file = 'echo_flex.svg';
        } elseif (strpos($mLow, 'auto') !== false) {
            $file = 'echo_auto.svg';
        } elseif ($mLow === 'echo' || strpos($mLow, 'echo plus') !== false || in_array($type, array('A3RMGO6LYLH7YN', 'A3FX4UWTP28V1P'), true)) {
            if (strpos($hwLow, '1st') !== false || strpos($hwLow, '2nd') !== false) {
                $file = 'echo_gen1_2.svg';
            } elseif (strpos($hwLow, '3rd') !== false || $type === 'A3FX4UWTP28V1P') {
                $file = 'echo_gen3.svg';
            } else {
                $file = 'echo_gen4.svg';
            }
        } elseif (strpos($family, 'VOX') !== false || strpos($family, 'MOBILE') !== false) {
            $file = 'mobile_app.svg';
        } else {
            $file = 'echo_generic.svg';
        }

        // Etiqueta legible con modelo y generación
        if (!empty($model)) {
            if ($type === 'A3EH2E0YZ30OD6' && empty($hw)) {
                $label = 'Echo Spot (2024)';
            } elseif (!empty($hw)) {
                $label = $model . ' (' . $hw . ')';
            } elseif (!empty($mfg) && strcasecmp($mfg, 'Amazon') !== 0) {
                $shortMfg = preg_replace('/\s+Electronics$/i', '', $mfg);
                $label = $shortMfg . ' · ' . $model;
            } else {
                $label = $model;
            }
        } else {
            $label = $family ?: $type;
        }

        return array(
            'image' => $base . $file,
            'label' => $label,
        );
    }

    public function isAirQualityMonitor() {
        $cat = strtoupper((string)$this->getConfiguration('primary_display_category', ''));
        if (strpos($cat, 'AIR_QUALITY') !== false) {
            return true;
        }
        $fam = strtoupper((string)$this->getConfiguration('device_family', ''));
        if (strpos($fam, 'AIR_QUALITY') !== false) {
            return true;
        }
        $type = strtoupper((string)$this->getConfiguration('device_type', ''));
        if (in_array($type, array('A8KSS7MTPDMS', 'A3RMGO6LYLH7AQM'), true)) {
            return true;
        }
        $name = strtolower($this->getName());
        if (strpos($name, 'qualité de l\'air') !== false || strpos($name, 'qualite de l\'air') !== false || strpos($name, 'calidad del aire') !== false || strpos($name, 'air quality') !== false) {
            return true;
        }
        if ($this->getCmd(null, 'sensor_air_quality') || $this->getCmd(null, 'sensor_indoor_air_quality') || $this->getCmd(null, 'sensor_pm25') || $this->getCmd(null, 'sensor_voc')) {
            return true;
        }
        return false;
    }

    public function supportsMediaPlayer() {
        if ($this->isAirQualityMonitor()) {
            return false;
        }
        $cfg = $this->getConfiguration('media_player_supported', null);
        if ($cfg !== null && $cfg !== '') {
            return ($cfg == 1);
        }
        return is_object($this->getCmd(null, 'play'));
    }

    public function supportsVolume() {
        if ($this->isAirQualityMonitor()) {
            return false;
        }
        return is_object($this->getCmd(null, 'set_volume'));
    }

    public function supportsVoiceControl() {
        if ($this->isAirQualityMonitor()) {
            return false;
        }
        $cfg = $this->getConfiguration('voice_control_supported', null);
        if ($cfg !== null && $cfg !== '') {
            return ($cfg == 1);
        }
        return is_object($this->getCmd(null, 'last_command'));
    }

    public function supportsActions() {
        if ($this->isAirQualityMonitor()) {
            return false;
        }
        return true;
    }

    public function buildAqmWidgetHtml($isOnline) {
        $iaqCmd = $this->getCmd(null, 'sensor_air_quality');
        if (!is_object($iaqCmd)) {
            $iaqCmd = $this->getCmd(null, 'sensor_indoor_air_quality');
        }
        $iaqVal = is_object($iaqCmd) ? $iaqCmd->execCmd() : null;
        $hasIaq = ($iaqVal !== '' && $iaqVal !== null && is_numeric($iaqVal));
        $score = $hasIaq ? intval($iaqVal) : null;

        if ($score !== null) {
            if ($score >= 80) {
                $scoreColor = '#00E676';
                $scoreRgb = '0, 230, 118';
                $scoreText = __('Very Good', __FILE__);
                $scoreSub = __('Clean and healthy air', __FILE__);
            } elseif ($score >= 65) {
                $scoreColor = '#00B0FF';
                $scoreRgb = '0, 176, 255';
                $scoreText = __('Good', __FILE__);
                $scoreSub = __('Acceptable air quality', __FILE__);
            } elseif ($score >= 50) {
                $scoreColor = '#FFD600';
                $scoreRgb = '255, 214, 0';
                $scoreText = __('Moderate', __FILE__);
                $scoreSub = __('Ventilation recommended', __FILE__);
            } elseif ($score >= 35) {
                $scoreColor = '#FF9100';
                $scoreRgb = '255, 145, 0';
                $scoreText = __('Poor', __FILE__);
                $scoreSub = __('Consider opening windows', __FILE__);
            } else {
                $scoreColor = '#FF1744';
                $scoreRgb = '255, 23, 68';
                $scoreText = __('Very Poor', __FILE__);
                $scoreSub = __('Hazardous indoor air', __FILE__);
            }
        } else {
            $scoreColor = '#64748B';
            $scoreRgb = '100, 116, 139';
            $scoreText = __('Measuring...', __FILE__);
            $scoreSub = __('Gathering sensor data', __FILE__);
            $score = '--';
        }

        $ledCmd = $this->getCmd(null, 'sensor_aqm_led');
        $ledHtml = '';
        if (is_object($ledCmd)) {
            $ledVal = strtoupper(trim((string)$ledCmd->execCmd()));
            $isLedOn = ($ledVal === 'ON' || $ledVal === '1' || $ledVal === 'TRUE');
            $ledStatusLabel = $isLedOn ? __('Active', __FILE__) : __('Off', __FILE__);
            $ledTooltip = __('Physical status LED (Configured in Alexa app · Read-only)', __FILE__);
            $ledHtml = '<div class="alexa-aqm-led-badge ' . ($isLedOn ? 'on' : 'off') . '" title="' . $ledTooltip . '">
                <i class="fas fa-lightbulb"></i> <span>LED: ' . $ledStatusLabel . '</span>
            </div>';
        }

        $metrics = array(
            'sensor_temperature' => array(
                'label' => __('Temperature', __FILE__),
                'icon' => 'fas fa-thermometer-half',
                'icon_color' => '#FF7043',
                'type' => 'temperature'
            ),
            'sensor_humidity' => array(
                'label' => __('Humidity', __FILE__),
                'icon' => 'fas fa-tint',
                'icon_color' => '#00B0FF',
                'type' => 'humidity'
            ),
            'sensor_pm25' => array(
                'label' => 'PM2.5',
                'icon' => 'fas fa-smog',
                'icon_color' => '#AB47BC',
                'type' => 'pm25'
            ),
            'sensor_pm10' => array(
                'label' => 'PM10',
                'icon' => 'fas fa-wind',
                'icon_color' => '#7E57C2',
                'type' => 'pm10'
            ),
            'sensor_voc' => array(
                'label' => __('VOC', __FILE__),
                'icon' => 'fas fa-leaf',
                'icon_color' => '#66BB6A',
                'type' => 'voc'
            ),
            'sensor_co' => array(
                'label' => __('Carbon Monoxide (CO)', __FILE__),
                'icon' => 'fas fa-shield-alt',
                'icon_color' => '#FFA726',
                'type' => 'co'
            ),
        );

        $cardsHtml = '';
        foreach ($metrics as $cmdId => $cfg) {
            $cmd = $this->getCmd(null, $cmdId);
            if (!is_object($cmd)) {
                continue;
            }
            $rawVal = $cmd->execCmd();
            if ($rawVal === '' || $rawVal === null) {
                continue;
            }

            $unit = trim((string)$cmd->getUnite());
            if (stripos($unit, 'MicroGrams') !== false || stripos($unit, 'ug/m3') !== false) {
                $unit = 'µg/m³';
            }

            $evalText = '';
            $evalColor = '#00E676';
            $displayVal = $rawVal;

            if (is_numeric($rawVal)) {
                $numVal = floatval($rawVal);
                switch ($cfg['type']) {
                    case 'temperature':
                        $displayVal = number_format($numVal, 1, '.', '');
                        if ($numVal >= 19 && $numVal <= 24) {
                            $evalText = __('Optimal', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal >= 17 && $numVal <= 26) {
                            $evalText = __('Moderate', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = ($numVal < 17) ? __('Cool', __FILE__) : __('Warm', __FILE__);
                            $evalColor = '#FF9100';
                        }
                        break;
                    case 'humidity':
                        $displayVal = round($numVal);
                        if ($numVal >= 40 && $numVal <= 60) {
                            $evalText = __('Ideal', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal >= 30 && $numVal <= 70) {
                            $evalText = __('Acceptable', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = ($numVal < 30) ? __('Dry', __FILE__) : __('Humid', __FILE__);
                            $evalColor = '#FF9100';
                        }
                        break;
                    case 'pm25':
                        $displayVal = round($numVal);
                        if ($numVal <= 15) {
                            $evalText = __('Low (<15)', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal <= 35) {
                            $evalText = __('Moderate', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = __('Elevated', __FILE__);
                            $evalColor = '#FF1744';
                        }
                        break;
                    case 'pm10':
                        $displayVal = round($numVal);
                        if ($numVal <= 45) {
                            $evalText = __('Low (<45)', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal <= 75) {
                            $evalText = __('Moderate', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = __('Elevated', __FILE__);
                            $evalColor = '#FF1744';
                        }
                        break;
                    case 'voc':
                        $displayVal = round($numVal);
                        if ($numVal <= 220) {
                            $evalText = __('Low (<220)', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal <= 660) {
                            $evalText = __('Moderate', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = __('Elevated', __FILE__);
                            $evalColor = '#FF1744';
                        }
                        break;
                    case 'co':
                        $displayVal = round($numVal);
                        if ($numVal <= 4) {
                            $evalText = __('Safe (<5)', __FILE__);
                            $evalColor = '#00E676';
                        } elseif ($numVal <= 9) {
                            $evalText = __('Attention', __FILE__);
                            $evalColor = '#FFD600';
                        } else {
                            $evalText = __('Hazardous', __FILE__);
                            $evalColor = '#FF1744';
                        }
                        break;
                }
            }

            $cardsHtml .= '
            <div class="alexa-aqm-metric-card" title="' . htmlspecialchars($cmd->getName()) . '">
                <div class="alexa-aqm-metric-header">
                    <i class="' . $cfg['icon'] . '" style="color: ' . $cfg['icon_color'] . ';"></i>
                    <span class="alexa-aqm-metric-title">' . htmlspecialchars($cfg['label']) . '</span>
                </div>
                <div class="alexa-aqm-metric-val-row">
                    <span class="alexa-aqm-metric-val">' . htmlspecialchars((string)$displayVal) . '</span>
                    ' . ($unit !== '' ? '<span class="alexa-aqm-metric-unit">' . htmlspecialchars($unit) . '</span>' : '') . '
                </div>
                ' . ($evalText !== '' ? '
                <div class="alexa-aqm-metric-eval" style="color: ' . $evalColor . ';">
                    <span class="alexa-aqm-eval-dot" style="background-color: ' . $evalColor . ';"></span>
                    <span>' . htmlspecialchars($evalText) . '</span>
                </div>' : '') . '
            </div>';
        }

        $extraPills = '';
        foreach ($this->getCmd('info') as $cmd) {
            $lid = $cmd->getLogicalId();
            if (strpos($lid, 'sensor_') !== 0) continue;
            if (in_array($lid, array('sensor_air_quality', 'sensor_indoor_air_quality', 'sensor_temperature',
                                     'sensor_humidity', 'sensor_pm25', 'sensor_pm10', 'sensor_voc',
                                     'sensor_co', 'sensor_aqm_led', 'sensor_reachability'), true)) {
                continue;
            }
            $v = $cmd->execCmd();
            if ($v === '' || $v === null) continue;
            $u = trim((string)$cmd->getUnite());
            $extraPills .= '<span class="alexa-aqm-extra-pill" title="' . htmlspecialchars($cmd->getName()) . '">
                ' . htmlspecialchars($cmd->getName()) . ': <b>' . htmlspecialchars((string)$v) . ($u !== '' ? ' ' . $u : '') . '</b>
            </span>';
        }

        $fillWidth = is_numeric($score) ? min(100, max(5, intval($score))) : 0;
        $statusBadge = $isOnline
            ? '<span class="alexa-badge-status online"><i class="fas fa-wifi"></i> ' . __('Online', __FILE__) . '</span>'
            : '<span class="alexa-badge-status offline"><i class="fas fa-times-circle"></i> ' . __('Offline', __FILE__) . '</span>';

        return '
        <div class="alexa-aqm-container" style="--aqm-color: ' . $scoreColor . '; --aqm-color-rgb: ' . $scoreRgb . ';">
            <div class="alexa-aqm-top-bar">
                <div class="alexa-aqm-status-wrap">' . $statusBadge . '</div>
                ' . $ledHtml . '
            </div>

            <!-- HERO SCORE -->
            <div class="alexa-aqm-hero">
                <div class="alexa-aqm-hero-circle">
                    <span class="alexa-aqm-hero-num">' . $score . '</span>
                    <span class="alexa-aqm-hero-denom">/100</span>
                </div>
                <div class="alexa-aqm-hero-text">
                    <div class="alexa-aqm-hero-label">' . __('Air Quality Index', __FILE__) . '</div>
                    <div class="alexa-aqm-hero-badge">
                        <span class="alexa-aqm-hero-dot"></span>
                        ' . $scoreText . '
                    </div>
                    <div class="alexa-aqm-hero-desc">' . $scoreSub . '</div>
                </div>
            </div>

            <!-- QUALITY PROGRESS BAR -->
            <div class="alexa-aqm-gauge-track">
                <div class="alexa-aqm-gauge-fill" style="width: ' . $fillWidth . '%;"></div>
            </div>

            <!-- METRIC CARDS GRID -->
            <div class="alexa-aqm-grid">
                ' . $cardsHtml . '
            </div>

            ' . ($extraPills !== '' ? '<div class="alexa-aqm-extra-row">' . $extraPills . '</div>' : '') . '
        </div>';
    }

    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        $replace['#height#'] = 'auto';
        if (isset($replace['#style#'])) {
            $replace['#style#'] = preg_replace('/height\s*:\s*[^;]+;?/', '', $replace['#style#']);
        }
        $replace['#eqLogic_href#'] = $this->getLinkToConfiguration();

        $status = $this->getCmd(null, 'status');
        $isOnline = (is_object($status) && $status->execCmd() == 1);
        $replace['#status_display#'] = $isOnline
            ? '<span class="alexa-badge-status online"><i class="fas fa-wifi"></i> ' . __('Online', __FILE__) . '</span>'
            : '<span class="alexa-badge-status offline"><i class="fas fa-times-circle"></i> ' . __('Offline', __FILE__) . '</span>';

        $isAqm = $this->isAirQualityMonitor();
        $replace['#is_aqm#'] = $isAqm ? '1' : '0';

        if ($isAqm) {
            // Limpiar comandos multimedia si existían por error en la base de datos
            $unwanted = array('volume', 'media_state', 'dnd_status', 'last_command', 'speak', 'whisper',
                              'announcement', 'text_command', 'set_volume', 'play', 'pause', 'stop',
                              'next', 'previous', 'dnd_on', 'dnd_off', 'restart', 'sound', 'music',
                              'info_skill', 'routine', 'routine_select');
            foreach ($unwanted as $uId) {
                $c = $this->getCmd(null, $uId);
                if (is_object($c)) {
                    try { $c->remove(); } catch (Exception $e) {}
                }
            }

            // Corregir unidades de microgramos en comandos existentes
            foreach (array('sensor_pm25', 'sensor_pm10') as $pmKey) {
                $pmCmd = $this->getCmd(null, $pmKey);
                if (is_object($pmCmd) && stripos($pmCmd->getUnite(), 'MicroGrams') !== false) {
                    $pmCmd->setUnite('µg/m³');
                    $pmCmd->save();
                }
            }

            $replace['#header_row#'] = '';
            $replace['#dnd_display#'] = '';
            $replace['#media_section#'] = '';
            $replace['#volume_section#'] = '';
            $replace['#last_command_section#'] = '';
            $replace['#notifications_section#'] = '';
            $replace['#quick_actions_section#'] = '';
            $replace['#sensors_display#'] = '';
            $replace['#aqm_display#'] = $this->buildAqmWidgetHtml($isOnline);
        } else {
            $replace['#aqm_display#'] = '';

            $dnd = $this->getCmd(null, 'dnd_status');
            $isDnd = (is_object($dnd) && $dnd->execCmd() == 1);
            $dndOnCmd = $this->getCmd(null, 'dnd_on');
            $dndOffCmd = $this->getCmd(null, 'dnd_off');
            $dndToggleId = $isDnd
                ? (is_object($dndOffCmd) ? $dndOffCmd->getId() : '')
                : (is_object($dndOnCmd) ? $dndOnCmd->getId() : '');
            $dndTitle = $isDnd ? __('Disable Do Not Disturb', __FILE__) : __('Enable Do Not Disturb', __FILE__);
            $replace['#dnd_display#'] = $isDnd
                ? '<span class="label label-warning cmd-btn-alexa cursor" data-cmd_id="' . $dndToggleId . '" title="' . htmlspecialchars($dndTitle, ENT_QUOTES, 'UTF-8') . '" style="font-size:10px;cursor:pointer;"><i class="fas fa-moon"></i> DND</span>'
                : '<span class="label cmd-btn-alexa cursor" data-cmd_id="' . $dndToggleId . '" title="' . htmlspecialchars($dndTitle, ENT_QUOTES, 'UTF-8') . '" style="font-size:10px;cursor:pointer;background:rgba(255,255,255,0.08);color:#94A3B8;border:1px solid rgba(255,255,255,0.12);"><i class="fas fa-moon"></i> DND</span>';

            $replace['#header_row#'] = '
            <div class="alexa-header-row">
                <div class="alexa-status-badge">' . $replace['#status_display#'] . '</div>
                <div class="alexa-dnd-toggle">' . $replace['#dnd_display#'] . '</div>
            </div>';

            // Media Player
            if ($this->supportsMediaPlayer()) {
                $media = $this->getCmd(null, 'media_state');
                $mState = is_object($media) ? $media->execCmd() : '';
                $replace['#media_state#'] = !empty($mState) ? htmlspecialchars($mState) : __('Idle', __FILE__);
                $actionCmds = array('play', 'pause', 'stop', 'next', 'previous');
                foreach ($actionCmds as $c) {
                    $cmdObj = $this->getCmd(null, $c);
                    $replace['#' . $c . '_id#'] = is_object($cmdObj) ? $cmdObj->getId() : '';
                }
                $replace['#media_section#'] = '
                <div class="alexa-media-section">
                    <div class="alexa-media-info">
                        <i class="fas fa-music alexa-music-icon"></i>
                        <span class="alexa-media-state">' . $replace['#media_state#'] . '</span>
                    </div>
                    <div class="alexa-media-controls">
                        <button type="button" class="btn btn-default btn-circle btn-sm cmd-btn-alexa" data-cmd_id="' . $replace['#previous_id#'] . '" title="' . __('Previous', __FILE__) . '">
                            <i class="fas fa-step-backward"></i>
                        </button>
                        <button type="button" class="btn btn-primary btn-circle cmd-btn-alexa" data-cmd_id="' . $replace['#play_id#'] . '" title="' . __('Play', __FILE__) . '">
                            <i class="fas fa-play"></i>
                        </button>
                        <button type="button" class="btn btn-default btn-circle cmd-btn-alexa" data-cmd_id="' . $replace['#pause_id#'] . '" title="' . __('Pause', __FILE__) . '">
                            <i class="fas fa-pause"></i>
                        </button>
                        <button type="button" class="btn btn-default btn-circle btn-sm cmd-btn-alexa" data-cmd_id="' . $replace['#stop_id#'] . '" title="' . __('Stop', __FILE__) . '">
                            <i class="fas fa-stop"></i>
                        </button>
                        <button type="button" class="btn btn-default btn-circle btn-sm cmd-btn-alexa" data-cmd_id="' . $replace['#next_id#'] . '" title="' . __('Next', __FILE__) . '">
                            <i class="fas fa-step-forward"></i>
                        </button>
                    </div>
                </div>';
            } else {
                $replace['#media_section#'] = '';
            }

            // Volume
            if ($this->supportsVolume()) {
                $vol = $this->getCmd(null, 'volume');
                $replace['#volume#'] = is_object($vol) ? intval($vol->execCmd()) : 50;
                $set_vol = $this->getCmd(null, 'set_volume');
                $replace['#set_volume_id#'] = is_object($set_vol) ? $set_vol->getId() : '';
                $replace['#volume_section#'] = '
                <div class="alexa-volume-row">
                    <i class="fas fa-volume-down alexa-vol-icon"></i>
                    <input type="range" class="alexa-vol-slider" min="0" max="100" value="' . $replace['#volume#'] . '" data-cmd_id="' . $replace['#set_volume_id#'] . '" />
                    <i class="fas fa-volume-up alexa-vol-icon"></i>
                    <span class="alexa-vol-val">' . $replace['#volume#'] . '%</span>
                </div>';
            } else {
                $replace['#volume_section#'] = '';
            }

            // Last voice command
            $last = $this->getCmd(null, 'last_command');
            $lastVal = is_object($last) ? $last->execCmd() : '';
            if ($this->supportsVoiceControl() && !empty($lastVal)) {
                $replace['#last_command#'] = htmlspecialchars($lastVal);
                $replace['#last_command_section#'] = '
                <div class="alexa-last-cmd-bubble" title="' . __('Last command heard by Alexa', __FILE__) . '">
                    <i class="fas fa-comment-dots" style="color: #00CAFA; margin-right: 4px;"></i>
                    <span class="alexa-last-cmd-text">' . $replace['#last_command#'] . '</span>
                </div>';
            } else {
                $replace['#last_command_section#'] = '';
            }

            // Next Alarm / Timer / Reminder pills (populated via HTTP/2 push)
            $notifPills = array();
            $notifMeta = array(
                'next_alarm' => array('icon' => 'fa-bell', 'label' => __('Next Alarm', __FILE__), 'color' => '#F59E0B'),
                'next_timer' => array('icon' => 'fa-hourglass-half', 'label' => __('Next Timer', __FILE__), 'color' => '#38BDF8'),
                'next_reminder' => array('icon' => 'fa-calendar-check', 'label' => __('Next Reminder', __FILE__), 'color' => '#A78BFA'),
            );
            $today = date('Y-m-d');
            foreach ($notifMeta as $nKey => $nInfo) {
                $nCmd = $this->getCmd(null, $nKey);
                $nVal = is_object($nCmd) ? trim((string)$nCmd->execCmd()) : '';
                if ($nVal !== '') {
                    $shortVal = (strpos($nVal, $today . ' ') === 0) ? substr($nVal, 11, 5) : substr($nVal, 5, 11);
                    $notifPills[] = '<span class="alexa-sensor-pill" title="' . htmlspecialchars($nInfo['label'] . ': ' . $nVal, ENT_QUOTES, 'UTF-8') . '">'
                        . '<i class="fas ' . $nInfo['icon'] . '" style="color: ' . $nInfo['color'] . ';"></i> '
                        . htmlspecialchars($shortVal, ENT_QUOTES, 'UTF-8') . '</span>';
                }
            }
            $replace['#notifications_section#'] = !empty($notifPills)
                ? '<div class="alexa-sensors-row alexa-notifications-row">' . implode('', $notifPills) . '</div>'
                : '';

            // Quick Actions / TTS
            $cmdOptionsHtml = '';
            if ($this->supportsActions()) {
                $placeholders = array(
                    'speak' => __('Text to speak (SSML supported)...', __FILE__),
                    'whisper' => __('Text to whisper...', __FILE__),
                    'announcement' => __('Announcement text...', __FILE__),
                    'text_command' => __('Voice command (e.g. turn on kitchen light)...', __FILE__),
                    'sound' => __('Sound ID (e.g. amzn_sfx_doorbell_chime_01)...', __FILE__),
                    'info_skill' => __('weather, traffic, joke, time, calendar_today...', __FILE__),
                    'music' => __('Song, artist or playlist...', __FILE__),
                    'routine' => __('Exact Alexa routine name...', __FILE__),
                    'set_volume' => __('0 - 100', __FILE__),
                );
                $soundOptions = array(
                    array('label' => __('Doorbell', __FILE__), 'value' => 'amzn_sfx_doorbell_chime_01'),
                    array('label' => __('Doorbell 2', __FILE__), 'value' => 'amzn_sfx_doorbell_chime_02'),
                    array('label' => __('Bell', __FILE__), 'value' => 'bell_02'),
                    array('label' => __('Church Bell', __FILE__), 'value' => 'amzn_sfx_church_bell_1x_02'),
                    array('label' => __('Siren', __FILE__), 'value' => 'amzn_sfx_siren_01'),
                    array('label' => __('Sci-Fi Alarm', __FILE__), 'value' => 'amzn_sfx_scifi_alarm_01'),
                    array('label' => __('Dog Bark', __FILE__), 'value' => 'amzn_sfx_dog_bark_02'),
                    array('label' => __('Cat Meow', __FILE__), 'value' => 'amzn_sfx_cat_meow_01'),
                    array('label' => __('Applause', __FILE__), 'value' => 'amzn_sfx_crowd_applause_01'),
                    array('label' => __('Christmas', __FILE__), 'value' => 'christmas_01'),
                );
                $skillOptions = array(
                    array('label' => 'weather', 'value' => 'weather'),
                    array('label' => 'traffic', 'value' => 'traffic'),
                    array('label' => 'flash_briefing', 'value' => 'flash_briefing'),
                    array('label' => 'time', 'value' => 'time'),
                    array('label' => 'date', 'value' => 'date'),
                    array('label' => 'calendar_today', 'value' => 'calendar_today'),
                    array('label' => 'calendar_tomorrow', 'value' => 'calendar_tomorrow'),
                    array('label' => 'calendar_next', 'value' => 'calendar_next'),
                    array('label' => 'joke', 'value' => 'joke'),
                    array('label' => 'fun_fact', 'value' => 'fun_fact'),
                    array('label' => 'tell_story', 'value' => 'tell_story'),
                    array('label' => 'sing_song', 'value' => 'sing_song'),
                    array('label' => 'good_morning', 'value' => 'good_morning'),
                    array('label' => 'goodnight', 'value' => 'goodnight'),
                    array('label' => 'im_home', 'value' => 'im_home'),
                    array('label' => 'clean_up', 'value' => 'clean_up'),
                    array('label' => 'national_news', 'value' => 'national_news'),
                );

                $allActions = $this->getCmd('action');
                if (is_array($allActions)) {
                    foreach ($allActions as $actCmd) {
                        $lid = $actCmd->getLogicalId();
                        $sub = $actCmd->getSubType();
                        $ph = isset($placeholders[$lid]) ? $placeholders[$lid] : __('Parameter...', __FILE__);
                        $selected = ($lid === 'speak') ? ' selected' : '';
                        $optsJson = '';
                        if ($lid === 'sound') {
                            $optsJson = json_encode($soundOptions, JSON_UNESCAPED_UNICODE);
                        } elseif ($lid === 'info_skill') {
                            $optsJson = json_encode($skillOptions, JSON_UNESCAPED_UNICODE);
                        } elseif ($lid === 'routine_select' || $lid === 'routine') {
                            $listVal = $actCmd->getConfiguration('listValue', '');
                            if (!empty($listVal)) {
                                $rOpts = array();
                                foreach (explode(';', $listVal) as $pair) {
                                    $parts = explode('|', $pair, 2);
                                    if (count($parts) === 2 && $parts[0] !== '') {
                                        $rOpts[] = array('label' => $parts[1], 'value' => $parts[0]);
                                    }
                                }
                                if (!empty($rOpts)) {
                                    $optsJson = json_encode($rOpts, JSON_UNESCAPED_UNICODE);
                                }
                            }
                        }
                        $optsAttr = $optsJson !== '' ? ' data-options="' . htmlspecialchars($optsJson, ENT_QUOTES, 'UTF-8') . '"' : '';
                        $cmdOptionsHtml .= '<option value="' . intval($actCmd->getId()) . '" data-subtype="' . htmlspecialchars($sub) . '" data-logical_id="' . htmlspecialchars($lid) . '" data-placeholder="' . htmlspecialchars($ph) . '"' . $optsAttr . $selected . '>' . htmlspecialchars($actCmd->getName()) . '</option>';
                    }
                }
            }

            if (!empty($cmdOptionsHtml)) {
                $volVal = isset($replace['#volume#']) ? $replace['#volume#'] : 50;
                $replace['#quick_actions_section#'] = '
                <div class="alexa-quick-action-row alexa-unified-action-bar">
                    <select class="form-control input-sm alexa-widget-cmd-select" title="' . __('Select command', __FILE__) . '">
                        ' . $cmdOptionsHtml . '
                    </select>
                    <div class="alexa-input-wrapper">
                        <input type="text" class="form-control alexa-input-text" placeholder="' . __('Text to speak (SSML supported)...', __FILE__) . '" />
                        <select class="form-control alexa-input-select" style="display: none;"></select>
                        <input type="number" class="form-control alexa-input-slider" min="0" max="100" value="' . $volVal . '" style="display: none;" />
                    </div>
                    <button type="button" class="btn btn-info alexa-btn-run-selected" title="' . __('Execute command', __FILE__) . '">
                        <i class="fas fa-play"></i>
                    </button>
                    <a href="' . $replace['#eqLogic_href#'] . '" class="btn btn-default alexa-btn-help-link reportModeHidden" title="' . __('Command help & configuration', __FILE__) . '">
                        <i class="fas fa-question-circle"></i>
                    </a>
                </div>';
            } else {
                $replace['#quick_actions_section#'] = '';
            }

            // Echo Sensors
            $sensorPills = '';
            $allInfos = $this->getCmd('info');
            if (is_array($allInfos)) {
                foreach ($allInfos as $infoCmd) {
                    $lid = $infoCmd->getLogicalId();
                    if (strpos($lid, 'sensor_') !== 0) continue;
                    if ($lid === 'sensor_reachability') continue;
                    $val = $infoCmd->execCmd();
                    if ($val === '' || $val === null) continue;
                    $unit = trim((string)$infoCmd->getUnite());
                    if (stripos($unit, 'MicroGrams') !== false) $unit = 'µg/m³';
                    $icon = 'fas fa-info-circle';
                    if (strpos($lid, 'temp') !== false) $icon = 'fas fa-thermometer-half';
                    elseif (strpos($lid, 'hum') !== false) $icon = 'fas fa-tint';
                    elseif (strpos($lid, 'pres') !== false || strpos($lid, 'motion') !== false) $icon = 'fas fa-running';
                    elseif (strpos($lid, 'light') !== false || strpos($lid, 'illum') !== false) $icon = 'fas fa-sun';

                    $displayVal = ($infoCmd->getSubType() === 'binary')
                        ? ($val ? __('Detected', __FILE__) : __('Clear', __FILE__))
                        : (is_numeric($val) ? round($val, 1) : htmlspecialchars((string)$val)) . ($unit !== '' ? ' ' . htmlspecialchars($unit) : '');
                    $sensorPills .= '<span class="alexa-sensor-pill" title="' . htmlspecialchars($infoCmd->getName()) . '"><i class="' . $icon . '"></i> '
                        . htmlspecialchars($infoCmd->getName()) . ': <b>' . $displayVal . '</b></span>';
                }
            }
            $replace['#sensors_display#'] = ($sensorPills !== '') ? '<div class="alexa-sensors-row">' . $sensorPills . '</div>' : '';
        }

        $template = translate::exec(
            getTemplate('core', $version, 'alexadevices', 'alexadevices'),
            'plugins/alexadevices/core/template/' . $version . '/alexadevices.html'
        );
        return $this->postToHtml($_version, template_replace($replace, $template));
    }

    public function createDefaultCommands($device = null) {
        $isAqm = $this->isAirQualityMonitor();
        if ($device !== null) {
            $cat = isset($device['primary_display_category']) ? strtoupper((string)$device['primary_display_category']) : '';
            $fam = isset($device['device_family']) ? strtoupper((string)$device['device_family']) : '';
            if (strpos($cat, 'AIR_QUALITY') !== false || strpos($fam, 'AIR_QUALITY') !== false) {
                $isAqm = true;
            }
        }

        // 1. Info: Online Status
        $this->getOrCreateCmd('status', 'info', 'binary', __('Online Status', __FILE__), false);

        // Si es un monitor de calidad del aire, no creamos comandos multimedia/audio
        if ($isAqm) {
            $unwanted = array('volume', 'media_state', 'dnd_status', 'last_command', 'speak', 'whisper',
                              'announcement', 'text_command', 'set_volume', 'play', 'pause', 'stop',
                              'next', 'previous', 'dnd_on', 'dnd_off', 'restart', 'sound', 'music',
                              'info_skill', 'routine', 'routine_select');
            foreach ($unwanted as $uId) {
                $c = $this->getCmd(null, $uId);
                if (is_object($c)) {
                    try { $c->remove(); } catch (Exception $e) {}
                }
            }
            return;
        }

        // 2. Info: Current Volume
        $this->getOrCreateCmd('volume', 'info', 'numeric', __('Current Volume', __FILE__), false, '#slider#');

        // 3. Info: Media State (PLAYING, PAUSED, IDLE)
        $this->getOrCreateCmd('media_state', 'info', 'string', __('Media State', __FILE__), false);

        // 4. Info: Do Not Disturb
        $this->getOrCreateCmd('dnd_status', 'info', 'binary', __('Do Not Disturb', __FILE__), false);

        // 5. Info: Last Voice Command
        $this->getOrCreateCmd('last_command', 'info', 'string', __('Last Voice Command', __FILE__), false);

        // 5b. Info: Next Alarm / Timer / Reminder (via HTTP/2 push)
        $this->getOrCreateCmd('next_alarm', 'info', 'string', __('Next Alarm', __FILE__), false);
        $this->getOrCreateCmd('next_timer', 'info', 'string', __('Next Timer', __FILE__), false);
        $this->getOrCreateCmd('next_reminder', 'info', 'string', __('Next Reminder', __FILE__), false);

        // 6. Action: TTS (Speak)
        $this->getOrCreateCmd('speak', 'action', 'message', __('Speak (TTS)', __FILE__), true);

        // 6b. Action: Whisper (SSML whispered TTS)
        $this->getOrCreateCmd('whisper', 'action', 'message', __('Whisper', __FILE__), true);

        // 7. Action: Announcement (Chime + Speech)
        $this->getOrCreateCmd('announcement', 'action', 'message', __('Announcement', __FILE__), true);

        // 8. Action: Text command (Voice command emulation)
        $this->getOrCreateCmd('text_command', 'action', 'message', __('Text Command', __FILE__), true);

        // 9. Action: Set Volume (Slider 0-100)
        $volCmd = $this->getOrCreateCmd('set_volume', 'action', 'slider', __('Set Volume', __FILE__), true);
        if (is_object($volCmd)) {
            $volCmd->setConfiguration('minValue', 0);
            $volCmd->setConfiguration('maxValue', 100);
            $volCmd->save();
        }

        // 10. Action: Play / Pause / Stop / Next / Previous
        $this->getOrCreateCmd('play', 'action', 'other', __('Play', __FILE__), true);
        $this->getOrCreateCmd('pause', 'action', 'other', __('Pause', __FILE__), true);
        $this->getOrCreateCmd('stop', 'action', 'other', __('Stop', __FILE__), true);
        $this->getOrCreateCmd('next', 'action', 'other', __('Next', __FILE__), true);
        $this->getOrCreateCmd('previous', 'action', 'other', __('Previous', __FILE__), true);

        // 11. Action: DND ON / OFF
        $this->getOrCreateCmd('dnd_on', 'action', 'other', __('Enable Do Not Disturb', __FILE__), true);
        $this->getOrCreateCmd('dnd_off', 'action', 'other', __('Disable Do Not Disturb', __FILE__), true);

        // 12. Action: Restart Echo device
        $this->getOrCreateCmd('restart', 'action', 'other', __('Restart Echo', __FILE__), false);

        // 13. Action: Play built-in Amazon sound
        $this->getOrCreateCmd('sound', 'action', 'message', __('Play Sound', __FILE__), false);

        // 14. Action: Play music / radio
        $this->getOrCreateCmd('music', 'action', 'message', __('Play Music', __FILE__), false);

        // 15. Action: Info skill (dropdown)
        $infoCmd = $this->getOrCreateCmd('info_skill', 'action', 'select', __('Info Skill', __FILE__), false);
        if (is_object($infoCmd)) {
            $skillsList = implode(';', array(
                'weather|' . __('Weather', __FILE__),
                'traffic|' . __('Traffic', __FILE__),
                'flash_briefing|' . __('Flash Briefing', __FILE__),
                'national_news|' . __('News', __FILE__),
                'time|' . __('Time', __FILE__),
                'date|' . __('Date', __FILE__),
                'calendar_today|' . __('Calendar Today', __FILE__),
                'calendar_tomorrow|' . __('Calendar Tomorrow', __FILE__),
                'joke|' . __('Joke', __FILE__),
                'fun_fact|' . __('Fun Fact', __FILE__),
                'tell_story|' . __('Tell a Story', __FILE__),
                'sing_song|' . __('Sing a Song', __FILE__),
                'good_morning|' . __('Good Morning', __FILE__),
                'goodnight|' . __('Good Night', __FILE__),
                'im_home|' . __("I'm Home", __FILE__),
                'clean_up|' . __('Clean Up', __FILE__),
            ));
            $infoCmd->setConfiguration('listValue', $skillsList);
            $infoCmd->save();
        }

        // 16. Action: Run Alexa routine (text)
        $this->getOrCreateCmd('routine', 'action', 'message', __('Run Routine', __FILE__), false);

        // 17. Action: Run Alexa routine (dropdown list)
        $routineSelectCmd = $this->getOrCreateCmd('routine_select', 'action', 'select', __('Run Routine (List)', __FILE__), false);
        if (is_object($routineSelectCmd)) {
            $rawRoutines = config::byKey('routines_list', 'alexadevices', array());
            $routines = is_string($rawRoutines) ? json_decode($rawRoutines, true) : $rawRoutines;
            if (is_array($routines) && !empty($routines)) {
                $parts = array();
                foreach ($routines as $r) {
                    $safe = str_replace(array(';', '|'), ' ', $r);
                    $parts[] = $safe . '|' . $safe;
                }
                $routineSelectCmd->setConfiguration('listValue', implode(';', $parts));
                $routineSelectCmd->save();
            }
        }
    }

    private function getOrCreateCmd($logicalId, $type, $subType, $name, $isVisible = true, $template = null) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new alexadevicesCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName($name);
            $cmd->setType($type);
            $cmd->setSubType($subType);
            $cmd->setIsVisible($isVisible ? 1 : 0);
            if ($template) {
                $cmd->setTemplate('dashboard', $template);
                $cmd->setTemplate('mobile', $template);
            }
            $cmd->save();
        }
        return $cmd;
    }

    public function updateSensors($sensors) {
        if (!is_array($sensors)) {
            return;
        }
        $sensorMeta = array(
            'temperature' => array('name' => __('Temperature', __FILE__), 'unit' => '°C', 'generic' => 'TEMPERATURE'),
            'humidity' => array('name' => __('Humidity', __FILE__), 'unit' => '%', 'generic' => 'HUMIDITY'),
            'pm25' => array('name' => __('PM2.5', __FILE__), 'unit' => 'µg/m³', 'generic' => 'DONT'),
            'voc' => array('name' => __('VOC', __FILE__), 'unit' => 'ppb', 'generic' => 'DONT'),
            'co' => array('name' => __('Carbon Monoxide (CO)', __FILE__), 'unit' => 'ppm', 'generic' => 'CO'),
            'air_quality' => array('name' => __('Air Quality', __FILE__), 'unit' => '', 'generic' => 'DONT'),
            'indoor_air_quality' => array('name' => __('Air Quality', __FILE__), 'unit' => '', 'generic' => 'DONT'),
            'illuminance' => array('name' => __('Illuminance', __FILE__), 'unit' => 'lx', 'generic' => 'LUMINOSITY'),
            'occupancy' => array('name' => __('Presence', __FILE__), 'unit' => '', 'generic' => 'PRESENCE'),
            'human_presence' => array('name' => __('Presence', __FILE__), 'unit' => '', 'generic' => 'PRESENCE'),
            'motion' => array('name' => __('Motion', __FILE__), 'unit' => '', 'generic' => 'PRESENCE'),
        );

        foreach ($sensors as $sensorKey => $info) {
            if (!is_array($info) || !empty($info['error']) || !array_key_exists('value', $info) || $info['value'] === null || $info['value'] === '') {
                continue;
            }
            $normKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)$sensorKey));
            $normKey = trim($normKey, '_');
            if ($normKey === '') {
                continue;
            }
            $logicalId = 'sensor_' . $normKey;
            $rawVal = $info['value'];
            $scale = isset($info['scale']) && $info['scale'] !== null ? trim((string)$info['scale']) : '';
            $meta = isset($sensorMeta[$normKey]) ? $sensorMeta[$normKey] : null;
            $label = $meta ? $meta['name'] : (!empty($info['name']) ? (string)$info['name'] : ucwords(str_replace('_', ' ', $normKey)));

            if (is_bool($rawVal)) {
                $subType = 'binary';
                $val = $rawVal ? 1 : 0;
            } elseif (is_string($rawVal) && in_array(strtoupper(trim($rawVal)), array('TRUE', 'FALSE', 'DETECTED', 'NOT_DETECTED'), true)) {
                $subType = 'binary';
                $upper = strtoupper(trim($rawVal));
                $val = ($upper === 'TRUE' || $upper === 'DETECTED') ? 1 : 0;
            } elseif (is_numeric($rawVal)) {
                $subType = 'numeric';
                $val = $rawVal + 0;
            } else {
                $subType = 'string';
                $val = (string)$rawVal;
            }

            $unit = $scale !== '' ? $scale : ($meta ? $meta['unit'] : '');
            if ($unit === 'CELSIUS' || $unit === 'C') $unit = '°C';
            if ($unit === 'FAHRENHEIT' || $unit === 'F') $unit = '°F';
            if ($unit === 'PERCENT') $unit = '%';
            if (strcasecmp($unit, 'MicroGramsPerCubicMeter') === 0 || strcasecmp($unit, 'MICROGRAMS_PER_CUBIC_METER') === 0 || strcasecmp($unit, 'ug/m3') === 0) {
                $unit = 'µg/m³';
            }
            if (strcasecmp($unit, 'PartsPerMillion') === 0 || strcasecmp($unit, 'PPM') === 0) {
                $unit = 'ppm';
            }
            if (strcasecmp($unit, 'PartsPerBillion') === 0 || strcasecmp($unit, 'PPB') === 0) {
                $unit = 'ppb';
            }

            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = $this->getOrCreateCmd($logicalId, 'info', $subType, $label, true);
                if ($unit !== '') {
                    $cmd->setUnite($unit);
                }
                if ($meta && $meta['generic'] !== 'DONT') {
                    $cmd->setGeneric_type($meta['generic']);
                }
                $cmd->save();
            } else {
                $currentUnit = $cmd->getUnite();
                if (stripos($currentUnit, 'MicroGrams') !== false || stripos($currentUnit, 'ug/m3') !== false) {
                    $cmd->setUnite('µg/m³');
                    $cmd->save();
                } elseif (empty($currentUnit) && !empty($unit)) {
                    $cmd->setUnite($unit);
                    $cmd->save();
                }
            }
            $this->checkAndUpdateCmd($logicalId, $val);
        }
    }
}

class alexadevicesCmd extends cmd {

    public function execute($options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new Exception(__('Device not found', __FILE__));
        }

        $serial = $eqLogic->getLogicalId();
        $logicalId = $this->getLogicalId();

        log::add('alexadevices', 'debug', "Ejecutando comando {$logicalId} en dispositivo {$serial}");

        if (alexadevices::isSleepModeActive()) {
            if ($logicalId === 'speak' || $logicalId === 'announcement') {
                log::add('alexadevices', 'info', "Silencio nocturno activo: comando {$logicalId} bloqueado en {$serial}");
                return;
            }
            if ($logicalId === 'whisper' && config::byKey('sleep_allow_whisper', 'alexadevices', 1) != 1) {
                log::add('alexadevices', 'info', "Silencio nocturno activo: susurro bloqueado en {$serial}");
                return;
            }
        }

        $action = '';
        $params = array();

        switch ($logicalId) {
            case 'speak':
                $action = 'speak';
                $params['text'] = isset($options['message']) ? $options['message'] : '';
                break;

            case 'whisper':
                $action = 'speak';
                $rawText = isset($options['message']) ? trim($options['message']) : '';
                if (stripos($rawText, '<speak>') === 0) {
                    $params['text'] = $rawText;
                } else {
                    $escaped = htmlspecialchars($rawText, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                    $params['text'] = '<speak><amazon:effect name="whispered">' . $escaped . '</amazon:effect></speak>';
                }
                break;

            case 'announcement':
                $action = 'announcement';
                $params['text'] = isset($options['message']) ? $options['message'] : '';
                $params['title'] = isset($options['title']) && !empty($options['title']) ? $options['title'] : 'Jeedom';
                break;

            case 'text_command':
                $action = 'text_command';
                $params['command'] = isset($options['message']) ? $options['message'] : '';
                break;

            case 'set_volume':
                $action = 'set_volume';
                $vol = isset($options['slider']) ? intval($options['slider']) : 50;
                if (alexadevices::isSleepModeActive()) {
                    $maxSleepVol = intval(config::byKey('sleep_max_volume', 'alexadevices', 0));
                    if ($maxSleepVol > 0 && $vol > $maxSleepVol) {
                        log::add('alexadevices', 'info', "Silencio nocturno: volumen limitado de {$vol}% a {$maxSleepVol}%");
                        $vol = $maxSleepVol;
                    }
                }
                $params['volume'] = $vol;
                break;

            case 'play':
            case 'pause':
            case 'stop':
            case 'next':
            case 'previous':
                $action = 'media_command';
                $params['command'] = $logicalId;
                break;

            case 'dnd_on':
                $action = 'dnd';
                $params['enable'] = true;
                break;

            case 'dnd_off':
                $action = 'dnd';
                $params['enable'] = false;
                break;

            case 'restart':
                $action = 'restart';
                break;

            case 'sound':
                $action = 'sound';
                $params['sound'] = isset($options['message']) ? trim($options['message']) : '';
                break;

            case 'music':
                $action = 'music';
                $rawMsg = isset($options['message']) ? trim($options['message']) : '';
                $provider = isset($options['title']) ? trim($options['title']) : '';
                if (empty($provider) && strpos($rawMsg, '|') !== false) {
                    list($provider, $rawMsg) = explode('|', $rawMsg, 2);
                    $provider = trim($provider);
                    $rawMsg = trim($rawMsg);
                }
                $params['search'] = $rawMsg;
                if (!empty($provider)) {
                    $params['provider'] = strtoupper($provider);
                }
                break;

            case 'info_skill':
                $action = 'info_skill';
                $params['skill'] = isset($options['select']) ? $options['select'] : 'weather';
                break;

            case 'routine':
                $action = 'routine';
                $params['routine'] = isset($options['message']) ? trim($options['message']) : '';
                $defaultSerial = config::byKey('default_device_serial', 'alexadevices', '');
                if (!empty($defaultSerial)) {
                    $params['default_serial'] = $defaultSerial;
                }
                break;

            case 'routine_select':
                $action = 'routine';
                $params['routine'] = isset($options['select']) ? trim($options['select']) : '';
                $defaultSerial = config::byKey('default_device_serial', 'alexadevices', '');
                if (!empty($defaultSerial)) {
                    $params['default_serial'] = $defaultSerial;
                }
                break;

            default:
                throw new Exception("Comando no soportado: {$logicalId}");
        }

        $data = array(
            'serial_number' => $serial,
            'action' => $action,
            'params' => $params
        );

        $res = alexadevices::callDaemon('/api/command', $data, 'POST');
        if ($logicalId === 'set_volume' && isset($params['volume'])) {
            $eqLogic->checkAndUpdateCmd('volume', $params['volume']);
        }
        return $res;
    }
}
