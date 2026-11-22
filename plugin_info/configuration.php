<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

$dep_info = alexadevices::dependancy_info();
$daemon_info = alexadevices::deamon_info();
$daemon_ok = (isset($daemon_info['state']) && $daemon_info['state'] == 'ok');
$deps_ok = (isset($dep_info['state']) && $dep_info['state'] == 'ok');

$jeeObjects = jeeObject::all();
$alexaDevices = eqLogic::byType('alexadevices');
$routinesJson = config::byKey('routines_list', 'alexadevices', array());
$routinesList = is_string($routinesJson) ? json_decode($routinesJson, true) : $routinesJson;
if (!is_array($routinesList)) {
    $routinesList = array();
}
$routinesUpdatedAt = config::byKey('routines_updated_at', 'alexadevices', '');
$sleepAllowWhisper = config::byKey('sleep_allow_whisper', 'alexadevices', 1);

// Pays Amazon : valeur enregistrée, sinon déduite de la langue de Jeedom.
$current_domain = config::byKey('amazon_domain', 'alexadevices', '');
if (empty($current_domain)) {
    $lang_map = array('es' => 'es', 'fr' => 'fr', 'de' => 'de', 'it' => 'it', 'en' => 'gb', 'pt' => 'es');
    $lang = substr(config::byKey('language', 'core', 'fr_FR'), 0, 2);
    $current_domain = isset($lang_map[$lang]) ? $lang_map[$lang] : 'es';
}
$countries = array(
    'es' => '🇪🇸 {{Spain}} (amazon.es)',
    'fr' => '🇫🇷 {{France}} (amazon.fr)',
    'de' => '🇩🇪 {{Germany}} (amazon.de)',
    'it' => '🇮🇹 {{Italy}} (amazon.it)',
    'gb' => '🇬🇧 {{United Kingdom}} (amazon.co.uk)',
    'us' => '🇺🇸 {{United States}} (amazon.com)',
    'ca' => '🇨🇦 {{Canada}} (amazon.ca)',
    'mx' => '🇲🇽 {{Mexico}} (amazon.com.mx)',
    'br' => '🇧🇷 {{Brazil}} (amazon.com.br)',
    'jp' => '🇯🇵 {{Japan}} (amazon.co.jp)',
    'au' => '🇦🇺 {{Australia}} (amazon.com.au)',
);

// Jeton temporaire autorisant la fenêtre de connexion Amazon (30 min).
$auth_token = sha1(random_bytes(32));
cache::set('alexadevices_auth_token', $auth_token, 1800);
?>

<style>
    #alexa_auth_card { border-radius: 8px; padding: 22px 24px; margin: 10px 0 20px 0; border: 1px solid rgba(128,128,128,.3); }
    #alexa_auth_card .alexa-status { font-size: 16px; font-weight: bold; margin-bottom: 6px; }
    #alexa_auth_card .alexa-sub { opacity: .8; margin-bottom: 16px; }
    #alexa_auth_card .alexa-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    #alexa_auth_card select { max-width: 320px; display: inline-block; }
    #btn_alexa_connect { font-weight: bold; padding: 10px 24px; font-size: 15px; }
    #alexa_auth_card .alexa-steps { margin: 14px 0 0 0; padding-left: 20px; opacity: .85; }
    #alexa_auth_card .alexa-waiting { display: none; margin-top: 16px; }
</style>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fab fa-amazon"></i> {{Amazon account}}</legend>

        <div id="alexa_auth_card">
            <?php if (!$deps_ok || !$daemon_ok) { ?>
                <div class="alert alert-warning" id="alexa_daemon_warning" style="margin-bottom:16px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{Before connecting:}}</strong>
                    <?php if (!$deps_ok) { ?>{{install the dependencies (Dependencies section above, "Relaunch" button), then}}<?php } ?>
                    {{start the daemon (Daemon section above, "(Re)Start" button).}}
                </div>
            <?php } ?>

            <div class="alexa-status" id="alexa_status_text"><i class="fas fa-spinner fa-spin"></i> {{Checking connection...}}</div>
            <div class="alexa-sub" id="alexa_status_sub"></div>

            <div class="alexa-row">
                <label for="alexa_country" style="margin:0;">{{Country of your Amazon account}}</label>
                <select id="alexa_country" class="form-control">
                    <?php foreach ($countries as $code => $label) { ?>
                        <option value="<?php echo $code; ?>" <?php echo ($code == $current_domain) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
                <button type="button" class="btn btn-success" id="btn_alexa_connect" <?php echo $daemon_ok ? '' : 'disabled'; ?>>
                    <i class="fab fa-amazon"></i> <span>{{Connect with Amazon}}</span>
                </button>
            </div>

            <ol class="alexa-steps" id="alexa_steps">
                <li>{{An Amazon window opens: sign in with your usual email/phone and password.}}</li>
                <li>{{Amazon asks you to verify it is you (SMS, WhatsApp, notification in the Alexa app, authenticator app...): follow the instructions.}}</li>
                <li>{{The window closes by itself and your Echo devices appear automatically.}}</li>
            </ol>

            <div class="alert alert-info alexa-waiting" id="alexa_waiting">
                <i class="fas fa-spinner fa-spin"></i>
                {{Waiting for you to finish signing in in the Amazon window...}}
                <a href="#" id="alexa_reopen">{{Window not visible? Click here to reopen it.}}</a>
            </div>

            <div style="margin-top:14px;">
                <a data-toggle="collapse" href="#alexa_manual" style="font-size:12px; opacity:.7;">
                    <i class="fas fa-life-ring"></i> {{Having trouble? Manual connection}}
                </a>
                <div class="collapse" id="alexa_manual" style="margin-top:10px;">
                    <p style="font-size:12px; opacity:.8;">
                        {{If the Amazon window ends on an error or "page not found" page after signing in, copy the full address (URL) of that window and paste it here:}}
                    </p>
                    <div class="input-group" style="max-width:700px;">
                        <input type="text" id="alexa_manual_url" class="form-control" placeholder="https://www.amazon.../ap/maplanding?...openid.oa2.authorization_code=..." />
                        <span class="input-group-btn">
                            <button class="btn btn-primary" type="button" id="btn_alexa_manual"><i class="fas fa-check"></i> {{Validate}}</button>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-moon"></i> {{Sleep protection / Night silence}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Enable night silence}}</label>
            <div class="col-sm-1">
                <input type="checkbox" class="configKey" data-l1key="sleep_protection_enabled" />
            </div>
            <div class="col-sm-8 help-block">{{Blocks spoken announcements (Speak, Whisper, Announcement, SSML) during the night schedule.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Start hour (default: 22)}}</label>
            <div class="col-sm-2">
                <input type="number" min="0" max="23" class="configKey form-control" data-l1key="sleep_start_hour" placeholder="22" />
            </div>
            <label class="col-sm-2 control-label">{{End hour (default: 7)}}</label>
            <div class="col-sm-2">
                <input type="number" min="0" max="23" class="configKey form-control" data-l1key="sleep_end_hour" placeholder="7" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Allow whisper during night}}</label>
            <div class="col-sm-1">
                <input type="checkbox" class="configKey" data-l1key="sleep_allow_whisper" <?php echo ($sleepAllowWhisper ? 'checked' : ''); ?> />
            </div>
            <div class="col-sm-8 help-block">{{If enabled, the Whisper command still speaks softly during night hours.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Max night volume (0 = disabled)}}</label>
            <div class="col-sm-2">
                <input type="number" min="0" max="100" class="configKey form-control" data-l1key="sleep_max_volume" placeholder="0" />
            </div>
            <div class="col-sm-7 help-block">{{Caps any Set Volume command above this value during night hours (0 = no limit).}}</div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-sliders-h"></i> {{Devices &amp; Amazon Routines}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Default object for new devices}}</label>
            <div class="col-sm-4">
                <select class="configKey form-control" data-l1key="default_object_id">
                    <option value="">{{None}}</option>
                    <?php foreach ($jeeObjects as $obj) { ?>
                        <option value="<?php echo $obj->getId(); ?>"><?php echo htmlspecialchars($obj->getName(), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-5 help-block">{{Automatically assigns newly discovered Echo devices to this Jeedom object.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Default Echo device}}</label>
            <div class="col-sm-4">
                <select class="configKey form-control" data-l1key="default_device_serial">
                    <option value="">{{None}}</option>
                    <?php foreach ($alexaDevices as $eq) { ?>
                        <option value="<?php echo htmlspecialchars($eq->getLogicalId(), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($eq->getHumanName(), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-5 help-block">{{Used when running Amazon routines or global actions without specifying a device.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Amazon Routines list}}</label>
            <div class="col-sm-4">
                <select id="alexa_routines_preview" class="form-control">
                    <?php if (empty($routinesList)) { ?>
                        <option value="">{{None}}</option>
                    <?php } else { ?>
                        <?php foreach ($routinesList as $rName) { ?>
                            <option value="<?php echo htmlspecialchars($rName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rName, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-2">
                <button type="button" class="btn btn-success" id="btn_refresh_routines"><i class="fas fa-sync-alt"></i> {{Refresh routines}}</button>
            </div>
            <div class="col-sm-3 help-block" id="alexa_routines_updated">
                <?php echo $routinesUpdatedAt ? '{{Last updated}}: ' . htmlspecialchars($routinesUpdatedAt, ENT_QUOTES, 'UTF-8') : '{{Not yet synchronized}}'; ?>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3 help-block" style="margin-top:-6px;">
                <i class="fas fa-lightbulb" style="color:#00CAFA;"></i>
                {{Once synchronized, every Echo device gets these routines in its "Run Routine (List)" (routine_select) dropdown for Scenarios, Dashboard widgets, and testing. To trigger a Jeedom Scenario from Alexa by voice, use the device's "Last Voice Command" (last_command) as a Scenario trigger.}}
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-tools"></i> {{Maintenance &amp; Repair}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Reload default commands}}</label>
            <div class="col-sm-3">
                <button type="button" class="btn btn-warning" id="btn_reload_commands"><i class="fas fa-redo"></i> {{Reload commands}}</button>
            </div>
            <div class="col-sm-6 help-block">{{Adds any missing default commands on all existing Alexa devices without deleting your scenarios.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Delete all devices and rescan}}</label>
            <div class="col-sm-3">
                <button type="button" class="btn btn-danger" id="btn_delete_rescan"><i class="fas fa-trash-alt"></i> {{Delete &amp; Rescan}}</button>
            </div>
            <div class="col-sm-6 help-block">{{Removes all Alexa devices from Jeedom and runs a clean discovery from Amazon.}}</div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Reset Amazon session}}</label>
            <div class="col-sm-3">
                <button type="button" class="btn btn-danger" id="btn_reset_session"><i class="fas fa-sign-out-alt"></i> {{Reset session}}</button>
            </div>
            <div class="col-sm-6 help-block">{{Clears saved Amazon cookies/tokens so you can sign in cleanly from scratch.}}</div>
        </div>
        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <div class="alert alert-info" style="margin-bottom:0;">
                    <i class="fas fa-info-circle"></i>
                    {{This plugin runs on a lightweight Python daemon (aiohttp + aioamazondevices) and does not require Node.js. If the daemon has dependency issues, use the "Relaunch" button in the Dependencies box above.}}
                </div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>
            <a data-toggle="collapse" href="#alexa_advanced" style="text-decoration:none;">
                <i class="fas fa-cogs"></i> {{Advanced settings}} <i class="fas fa-chevron-down" style="font-size:12px;"></i>
            </a>
        </legend>
        <div class="collapse" id="alexa_advanced">
            <div class="form-group">
                <label class="col-sm-3 control-label">{{Daemon Port}}</label>
                <div class="col-sm-3">
                    <input type="number" class="configKey form-control" data-l1key="daemon_port" placeholder="55065" />
                </div>
                <div class="col-sm-6 help-block">{{Local TCP port for the Python daemon REST API (default: 55065).}}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{Polling Interval (seconds)}}</label>
                <div class="col-sm-3">
                    <input type="number" class="configKey form-control" data-l1key="poll_interval" placeholder="300" />
                </div>
                <div class="col-sm-6 help-block">{{Backup polling frequency with Amazon servers (default: 300s). Most events are received in real-time via HTTP/2 push.}}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{Internal Daemon Token}}</label>
                <div class="col-sm-4">
                    <input type="text" class="configKey form-control" data-l1key="daemon_token" placeholder="{{Automatically generated}}" readonly />
                </div>
                <div class="col-sm-2">
                    <button type="button" class="btn btn-default" id="btn_generate_token"><i class="fas fa-random"></i> {{Regenerate}}</button>
                </div>
            </div>
        </div>
    </fieldset>

    <div class="alert alert-info" style="margin-top: 20px; font-size: 11px;">
        <i class="fas fa-info-circle"></i>
        {{This plugin is an independent community development and is not affiliated with or endorsed by Amazon.com, Inc. The software is provided "AS IS", without warranty of any kind.}}
    </div>
</form>

<script>
(function () {
    var authToken = <?php echo json_encode($auth_token); ?>;
    var daemonOk = <?php echo $daemon_ok ? 'true' : 'false'; ?>;
    var popup = null;
    var pollTimer = null;
    var ajaxUrl = 'plugins/alexadevices/core/ajax/alexadevices.ajax.php';

    function setStatus(state, user) {
        var $t = $('#alexa_status_text'), $s = $('#alexa_status_sub'), $b = $('#btn_alexa_connect span');
        if (state === 'connected') {
            daemonOk = true;
            $('#alexa_daemon_warning').hide();
            $('#btn_alexa_connect').prop('disabled', false);
            $t.html('<i class="fas fa-check-circle" style="color:#5cb85c;"></i> {{Connected to Amazon}}' + (user ? ' — ' + $('<span>').text(user).html() : ''));
            $s.text('{{Your Echo devices are synchronized. You only need to reconnect if Amazon closes the session.}}');
            $b.text('{{Reconnect}}');
            $('#alexa_steps').hide();
        } else if (state === 'nodaemon') {
            daemonOk = false;
            $('#alexa_daemon_warning').show();
            $('#btn_alexa_connect').prop('disabled', true);
            $t.html('<i class="fas fa-pause-circle" style="color:#f0ad4e;"></i> {{Daemon stopped}}');
            $s.text('{{Start the daemon to be able to connect.}}');
        } else {
            daemonOk = true;
            $('#alexa_daemon_warning').hide();
            $('#btn_alexa_connect').prop('disabled', false);
            $t.html('<i class="fas fa-times-circle" style="color:#d9534f;"></i> {{Not connected}}');
            $s.text('{{Choose your country and click the button. Your email and password are entered directly on the Amazon page, never in Jeedom.}}');
            $b.text('{{Connect with Amazon}}');
            $('#alexa_steps').show();
        }
    }

    function checkStatus(onConnected) {
        $.ajax({
            type: 'POST', url: ajaxUrl, data: { action: 'getAuthStatus' }, dataType: 'json', global: false,
            error: function () { setStatus(daemonOk ? 'disconnected' : 'nodaemon'); },
            success: function (data) {
                if (data.state != 'ok') { setStatus(daemonOk ? 'disconnected' : 'nodaemon'); return; }
                if (data.result && data.result.authenticated) {
                    setStatus('connected', data.result.user);
                    if (onConnected) onConnected();
                } else {
                    setStatus('disconnected');
                }
            }
        });
    }

    function notify(o) {
        if (window.jeedomUtils && jeedomUtils.showAlert) { jeedomUtils.showAlert(o); }
        else if ($.fn.showAlert) { $.fn.showAlert(o); }
        else { alert(o.message); }
    }

    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function openAmazon() {
        var domain = $('#alexa_country').val();
        var url = '/plugins/alexadevices/core/php/proxy.php?action=start&domain=' + encodeURIComponent(domain) + '&auth_token=' + encodeURIComponent(authToken);
        var w = 520, h = 760;
        var left = Math.max(0, (screen.width - w) / 2), top = Math.max(0, (screen.height - h) / 2);
        popup = window.open(url, 'AlexaAmazonLogin', 'width=' + w + ',height=' + h + ',top=' + top + ',left=' + left + ',scrollbars=yes,resizable=yes');
        if (!popup) {
            notify({ message: '{{Your browser blocked the Amazon window. Allow pop-ups for Jeedom and try again.}}', level: 'warning' });
            return;
        }
        popup.focus();
        $('#alexa_waiting').show();
        stopPolling();
        pollTimer = setInterval(function () {
            checkStatus(function () {
                stopPolling();
                try { if (popup && !popup.closed) popup.close(); } catch (e) {}
                $('#alexa_waiting').hide();
                notify({ message: '{{Connected to Amazon! Your devices are being discovered.}}', level: 'success' });
            });
        }, 2000);
    }

    $('#btn_alexa_connect').on('click', function () { if (!$(this).prop('disabled')) openAmazon(); });
    $('#alexa_reopen').on('click', function (e) {
        e.preventDefault();
        if (popup && !popup.closed) { popup.focus(); } else { openAmazon(); }
    });

    $('#btn_alexa_manual').on('click', function () {
        var code = $('#alexa_manual_url').val().trim();
        if (!code) return;
        $.ajax({
            type: 'POST', url: ajaxUrl, dataType: 'json',
            data: { action: 'submitManualCode', code: code, domain: $('#alexa_country').val() },
            success: function (data) {
                if (data.state == 'ok') {
                    notify({ message: '{{Connected to Amazon! Your devices are being discovered.}}', level: 'success' });
                    checkStatus();
                } else {
                    notify({ message: data.result, level: 'danger' });
                }
            }
        });
    });

    $('#btn_generate_token').on('click', function () {
        var token = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        $('.configKey[data-l1key=daemon_token]').val(token);
    });

    $('#btn_refresh_routines').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            type: 'POST', url: ajaxUrl, data: { action: 'refreshRoutines' }, dataType: 'json',
            complete: function () { $btn.prop('disabled', false); },
            success: function (data) {
                if (data.state !== 'ok') {
                    notify({ message: data.result || '{{Error refreshing routines}}', level: 'danger' });
                    return;
                }
                var routines = (data.result && data.result.routines) ? data.result.routines : [];
                var $sel = $('#alexa_routines_preview').empty();
                if (!routines.length) {
                    $sel.append($('<option>').val('').text('{{None}}'));
                } else {
                    $.each(routines, function (_, name) {
                        $sel.append($('<option>').val(name).text(name));
                    });
                }
                if (data.result && data.result.updated_at) {
                    $('#alexa_routines_updated').text('{{Last updated}}: ' + data.result.updated_at);
                }
                notify({ message: '{{Routines synchronized}}: ' + routines.length, level: 'success' });
            }
        });
    });

    $('#btn_reload_commands').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            type: 'POST', url: ajaxUrl, data: { action: 'reloadAllCommands' }, dataType: 'json',
            complete: function () { $btn.prop('disabled', false); },
            success: function (data) {
                if (data.state !== 'ok') {
                    notify({ message: data.result || '{{Error reloading commands}}', level: 'danger' });
                    return;
                }
                var count = (data.result && data.result.count !== undefined) ? data.result.count : 0;
                notify({ message: '{{Default commands reloaded on devices}}: ' + count, level: 'success' });
            }
        });
    });

    $('#btn_delete_rescan').on('click', function () {
        if (!confirm('{{Are you sure you want to delete all Alexa devices and rescan from Amazon?}}')) return;
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            type: 'POST', url: ajaxUrl, data: { action: 'deleteAllAndRescan' }, dataType: 'json',
            complete: function () { $btn.prop('disabled', false); },
            success: function (data) {
                if (data.state !== 'ok') {
                    notify({ message: data.result || '{{Error rescanning devices}}', level: 'danger' });
                    return;
                }
                notify({ message: '{{All devices deleted and rescan completed.}}', level: 'success' });
            }
        });
    });

    $('#btn_reset_session').on('click', function () {
        if (!confirm('{{Are you sure you want to clear the saved Amazon session? You will need to sign in again.}}')) return;
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            type: 'POST', url: ajaxUrl, data: { action: 'resetAmazonSession' }, dataType: 'json',
            complete: function () { $btn.prop('disabled', false); },
            success: function (data) {
                if (data.state !== 'ok') {
                    notify({ message: data.result || '{{Error resetting session}}', level: 'danger' });
                    return;
                }
                setStatus('disconnected');
                notify({ message: '{{Amazon session cleared. You can now sign in again.}}', level: 'success' });
            }
        });
    });

    if (daemonOk) {
        checkStatus();
    } else {
        setStatus('nodaemon');
        var daemonWaitTimer = setInterval(function () {
            checkStatus();
            if (daemonOk) { clearInterval(daemonWaitTimer); }
        }, 3000);
    }
})();
</script>
