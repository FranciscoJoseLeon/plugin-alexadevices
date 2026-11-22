/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$('#bt_syncAlexaDevices').on('click', function () {
    $('#bt_syncAlexaDevices').find('i').addClass('fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/alexadevices/core/ajax/alexadevices.ajax.php',
        data: {
            action: 'syncDevices'
        },
        dataType: 'json',
        error: function (request, status, error) {
            $('#bt_syncAlexaDevices').find('i').removeClass('fa-spin');
            $('#div_alert').showAlert({message: error, level: 'danger'});
        },
        success: function (data) {
            $('#bt_syncAlexaDevices').find('i').removeClass('fa-spin');
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: '{{Devices synchronized successfully}}', level: 'success'});
            window.location.reload();
        }
    });
});

$('#in_searchEqlogic').off('keyup').keyup(function () {
    var search = $(this).val().toLowerCase();
    $('.eqLogicDisplayCard').each(function () {
        var text = $(this).find('.name').text().toLowerCase();
        if (text.indexOf(search) >= 0) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
});

$('#bt_resetSearch').on('click', function () {
    $('#in_searchEqlogic').val('').keyup();
});

$('#bt_showPluginLog').on('click', function () {
    if (typeof jeedom.log.showLog === 'function') {
        jeedom.log.showLog({logfile: 'alexadevices'});
    } else {
        window.location.href = 'index.php?v=d&p=log&logfile=alexadevices';
    }
});

function alexaGetMessagePlaceholder(logicalId) {
    var map = {
        speak: '{{Text to speak (supports SSML)...}}',
        whisper: '{{Text to whisper...}}',
        announcement: '{{Announcement message...}}',
        text_command: '{{Voice command (e.g. turn off lights)...}}',
        music: '{{Song, artist or radio station...}}',
        sound: '{{Sound ID (e.g. amzn_sfx_doorbell_chime_01)...}}',
        routine: '{{Exact Amazon routine name...}}'
    };
    return map[logicalId] || '{{Value...}}';
}

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Name}}">';
    tr += '<span class="label label-info cmdAttr" data-l1key="logicalId"></span>';
    tr += '</td>';

    tr += '<td>';
    tr += '<span class="type label label-default">' + init(_cmd.type) + ' / ' + init(_cmd.subType) + '</span>';
    tr += '</td>';

    tr += '<td>';
    if (_cmd.type == 'info') {
        var stateVal = (_cmd.state !== undefined && _cmd.state !== null && _cmd.state !== '') ? String(_cmd.state) : '—';
        var unit = (_cmd.unite) ? ' ' + _cmd.unite : '';
        var dateTitle = (_cmd.valueDate) ? ' title="{{Last updated}}: ' + init(_cmd.valueDate) + '"' : '';
        if (_cmd.subType == 'binary') {
            var isOne = (stateVal === '1' || stateVal === 'true');
            var badgeCls = isOne ? 'label-success' : 'label-default';
            if (_cmd.logicalId === 'dnd_status') {
                badgeCls = isOne ? 'label-warning' : 'label-default';
                stateVal = isOne ? '{{ON}} (1)' : '{{OFF}} (0)';
            } else if (_cmd.logicalId === 'status') {
                stateVal = isOne ? '{{Online}} (1)' : '{{Offline}} (0)';
            }
            tr += '<span class="label ' + badgeCls + ' alexa-cmd-state"' + dateTitle + ' style="font-size:12px;">' + $('<span>').text(stateVal).html() + '</span>';
        } else {
            tr += '<span class="label label-primary alexa-cmd-state"' + dateTitle + ' style="font-size:12px; display:inline-block; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;">' + $('<span>').text(stateVal + unit).html() + '</span>';
        }
    } else if (_cmd.subType == 'slider') {
        tr += '<div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">';
        tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" style="width:55px;" title="{{Min}}">';
        tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" style="width:55px;" title="{{Max}}">';
        if (is_numeric(_cmd.id)) {
            tr += '<input type="number" class="form-control input-sm alexa-quick-val" data-subtype="slider" min="' + init(_cmd.configuration.minValue, 0) + '" max="' + init(_cmd.configuration.maxValue, 100) + '" placeholder="0-100" style="width:78px;">';
            tr += '<a class="btn btn-primary btn-xs btn-alexa-exec" title="{{Set value}}"><i class="fas fa-play"></i></a>';
        }
        tr += '</div>';
    } else if (_cmd.subType == 'select') {
        tr += '<input type="hidden" class="cmdAttr" data-l1key="configuration" data-l2key="listValue">';
        var listStr = init(_cmd.configuration.listValue);
        if (listStr) {
            tr += '<div style="display:flex; gap:4px; align-items:center;">';
            tr += '<select class="form-control input-sm alexa-quick-val" data-subtype="select" style="max-width:240px;">';
            var items = listStr.split(';');
            for (var i = 0; i < items.length; i++) {
                if (!items[i]) continue;
                var pair = items[i].split('|');
                var optVal = pair[0];
                var optLbl = (pair.length > 1) ? pair[1] : pair[0];
                tr += '<option value="' + $('<span>').text(optVal).html() + '">' + $('<span>').text(optLbl).html() + '</option>';
            }
            tr += '</select>';
            if (is_numeric(_cmd.id)) {
                tr += '<a class="btn btn-primary btn-xs btn-alexa-exec" title="{{Execute}}"><i class="fas fa-play"></i></a>';
            }
            tr += '</div>';
        } else {
            tr += '<span class="text-muted" style="font-size:11px;">{{No options synchronized yet}}</span>';
        }
    } else if (_cmd.subType == 'message') {
        if (is_numeric(_cmd.id)) {
            tr += '<div style="display:flex; gap:4px; align-items:center;">';
            tr += '<input type="text" class="form-control input-sm alexa-quick-val" data-subtype="message" placeholder="' + alexaGetMessagePlaceholder(init(_cmd.logicalId)) + '" style="min-width:180px;">';
            tr += '<a class="btn btn-primary btn-xs btn-alexa-exec" title="{{Execute}}"><i class="fas fa-play"></i></a>';
            tr += '</div>';
        }
    }
    tr += '</td>';

    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Visible}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{History}}</label>';
    tr += '</td>';

    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Test}}</a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a>';
    }
    tr += '</td>';
    tr += '</tr>';

    $('#table_cmd tbody').append(tr);
    $('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
}

$('#table_cmd').off('click', '.btn-alexa-exec').on('click', '.btn-alexa-exec', function () {
    var $tr = $(this).closest('tr.cmd');
    var cmdId = $tr.attr('data-cmd_id');
    var $input = $tr.find('.alexa-quick-val');
    var subType = $input.attr('data-subtype');
    var val = $input.val();
    var valueObj = {};
    if (subType === 'slider') {
        if (val === '' || val === null) return;
        valueObj = { slider: val };
    } else if (subType === 'select') {
        if (val === '' || val === null) return;
        valueObj = { select: val };
    } else if (subType === 'message') {
        if (!val) { $input.focus(); return; }
        valueObj = { title: '', message: val };
    }
    jeedom.cmd.execute({
        id: cmdId,
        value: valueObj,
        error: function (error) {
            $('#div_alert').showAlert({ message: error.message || error, level: 'danger' });
        },
        success: function () {
            $('#div_alert').showAlert({ message: '{{Command executed}}', level: 'success' });
        }
    });
});

$('#table_cmd').off('keypress', '.alexa-quick-val').on('keypress', '.alexa-quick-val', function (e) {
    if (e.which === 13) {
        e.preventDefault();
        $(this).closest('tr.cmd').find('.btn-alexa-exec').click();
    }
});

var alexaTesterCmds = {};

function alexaGetCmdHelp(logicalId) {
    var map = {
        speak: '{{Reads any text aloud on this Echo. Supports SSML tags (auto-wrapped in &lt;speak&gt;); max 10s per &lt;break&gt; and ~500 chars.}}',
        whisper: '{{Speaks softly in a whispered voice. Can remain active during Night Silence if enabled in plugin configuration.}}',
        announcement: '{{Plays an announcement chime and broadcasts the message on this Echo.}}',
        text_command: '{{Executes any phrase silently as if you spoke it to Alexa — ideal for controlling Alexa Smart Home devices (lights, plugs, blinds, thermostats).}}',
        set_volume: '{{Sets speaker volume between 0 and 100. Respects the Night Max Volume limit if configured.}}',
        play: '{{Resumes media playback on this device.}}',
        pause: '{{Pauses current media playback on this device.}}',
        next: '{{Skips to the next track or item.}}',
        previous: '{{Returns to the previous track or item.}}',
        music: '{{Plays a song, artist or TuneIn station (you can also use SPOTIFY|query or TUNEIN|station).}}',
        sound: '{{Plays a built-in Alexa sound effect (e.g. amzn_sfx_doorbell_chime_01, bell_02, amzn_sfx_siren_01).}}',
        info_skill: '{{Runs an Alexa info briefing such as Weather, Traffic, News, Time, Joke or Good Morning.}}',
        routine: '{{Runs an Amazon Alexa routine by typing its exact name from the Alexa app.}}',
        routine_select: '{{Selects and runs one of your synchronized Amazon Alexa routines.}}',
        dnd_on: '{{Enables Do Not Disturb (silences calls, drop-ins and notifications).}}',
        dnd_off: '{{Disables Do Not Disturb on this device.}}',
        restart: '{{Restarts media/connection state or refreshes device session.}}',
        refresh: '{{Forces an immediate status refresh from Amazon servers.}}'
    };
    return map[logicalId] || '{{Executes this command directly on the Alexa device.}}';
}

function alexaGetCmdPresets(logicalId) {
    var presets = {
        speak: [
            { label: '{{Whisper (SSML)}}', val: '<amazon:effect name="whispered">{{Hello, I am whispering}}</amazon:effect>' },
            { label: '{{Pause 3s (max 10s)}}', val: '{{One}} <break time="3s"/> {{Two}}' },
            { label: '{{Soft volume}}', val: '<prosody volume="soft">{{Hello}}</prosody>' },
            { label: '{{Loud volume}}', val: '<prosody volume="x-loud">{{Attention}}</prosody>' },
            { label: '{{Slow speed}}', val: '<prosody rate="slow">{{Speaking slowly}}</prosody>' },
            { label: '{{Fast speed}}', val: '<prosody rate="fast">{{Speaking fast}}</prosody>' },
            { label: '{{Low pitch}}', val: '<prosody pitch="low">{{Deep voice}}</prosody>' },
            { label: '{{High pitch}}', val: '<prosody pitch="high">{{High voice}}</prosody>' },
            { label: '{{Strong emphasis}}', val: '<emphasis level="strong">{{Important}}</emphasis>' },
            { label: '{{Digits}}', val: '<say-as interpret-as="digits">1234</say-as>' }
        ],
        whisper: [
            { label: '{{Good night}}', val: '{{Good night}}' },
            { label: '{{Door open}}', val: '{{The front door is open}}' }
        ],
        announcement: [
            { label: '{{Dinner ready}}', val: '{{Dinner is ready}}' },
            { label: '{{Someone at door}}', val: '{{Someone is at the door}}' }
        ],
        sound: [
            { label: '{{Doorbell}}', val: 'amzn_sfx_doorbell_chime_01' },
            { label: '{{Siren}}', val: 'amzn_sfx_siren_01' },
            { label: '{{Bell}}', val: 'bell_02' },
            { label: '{{Christmas}}', val: 'christmas_01' }
        ],
        music: [
            { label: 'Spotify', val: 'SPOTIFY|Queen' },
            { label: 'TuneIn Radio', val: 'TUNEIN|Los 40' },
            { label: 'Amazon Music', val: 'AMAZON_MUSIC|Jazz' }
        ],
        text_command: [
            { label: '{{Light on}}', val: '{{turn on living room light}}' },
            { label: '{{Light 30%}}', val: '{{set bedroom light to 30 percent}}' },
            { label: '{{Plug off}}', val: '{{turn off workshop plug}}' },
            { label: '{{Thermostat 22°}}', val: '{{set thermostat to 22 degrees}}' },
            { label: '{{Open blind}}', val: '{{open bedroom blind}}' },
            { label: '{{Weather}}', val: '{{what is the weather}}' },
            { label: '{{Timer 5m}}', val: '{{set a 5 minute timer}}' }
        ],
        set_volume: [
            { label: '10%', val: '10' },
            { label: '25%', val: '25' },
            { label: '50%', val: '50' },
            { label: '75%', val: '75' },
            { label: '100%', val: '100' }
        ]
    };
    return presets[logicalId] || [];
}

function alexaRenderTesterCmd(cmd) {
    var $wrap = $('#alexa_cmd_tester_input_wrap').empty().hide();
    var $help = $('#alexa_cmd_tester_help').empty();
    if (!cmd) {
        return;
    }
    $help.append($('<div>').html('<i class="fas fa-info-circle" style="color:#00CAFA;"></i> ' + alexaGetCmdHelp(cmd.logicalId)));

    var presets = alexaGetCmdPresets(cmd.logicalId);
    if (presets.length) {
        var $chips = $('<div class="alexa-tester-presets"></div>');
        $chips.append($('<span class="alexa-tester-presets-label">').text('{{Click an option to fill:}} '));
        for (var p = 0; p < presets.length; p++) {
            var pr = presets[p];
            $('<button type="button" class="btn btn-default btn-xs alexa-preset-chip"></button>')
                .text(pr.label)
                .attr('title', pr.val)
                .data('preset-val', pr.val)
                .appendTo($chips);
        }
        $help.append($chips);
    }

    if (cmd.subType === 'message') {
        var $inp = $('<input type="text" id="alexa_cmd_tester_val" class="form-control input-sm">')
            .attr('placeholder', alexaGetMessagePlaceholder(cmd.logicalId));
        $wrap.append($inp).show();
    } else if (cmd.subType === 'slider') {
        var minV = (cmd.configuration && cmd.configuration.minValue !== undefined) ? cmd.configuration.minValue : 0;
        var maxV = (cmd.configuration && cmd.configuration.maxValue !== undefined) ? cmd.configuration.maxValue : 100;
        var $inp = $('<input type="number" id="alexa_cmd_tester_val" class="form-control input-sm">')
            .attr({ min: minV, max: maxV, placeholder: minV + '-' + maxV, value: 40 });
        $wrap.append($inp).show();
    } else if (cmd.subType === 'select') {
        var listStr = (cmd.configuration && cmd.configuration.listValue) ? cmd.configuration.listValue : '';
        var $sel = $('<select id="alexa_cmd_tester_val" class="form-control input-sm"></select>');
        var items = listStr.split(';');
        for (var i = 0; i < items.length; i++) {
            if (!items[i]) continue;
            var pair = items[i].split('|');
            var v = pair[0];
            var l = pair.length > 1 ? pair[1] : pair[0];
            $sel.append($('<option>').val(v).text(l));
        }
        $wrap.append($sel).show();
    }
}

function printEqLogic(_eqLogic) {
    alexaTesterCmds = {};
    var $sel = $('#alexa_cmd_tester_select').empty();
    if (!_eqLogic || !Array.isArray(_eqLogic.cmd)) {
        alexaRenderTesterCmd(null);
        return;
    }
    var firstId = null;
    for (var i = 0; i < _eqLogic.cmd.length; i++) {
        var c = _eqLogic.cmd[i];
        if (c.type !== 'action') continue;
        alexaTesterCmds[c.id] = c;
        if (firstId === null) firstId = c.id;
        $sel.append($('<option>').val(c.id).text(c.name + ' (' + c.logicalId + ')'));
    }
    if (firstId !== null) {
        $sel.val(firstId);
        alexaRenderTesterCmd(alexaTesterCmds[firstId]);
    } else {
        alexaRenderTesterCmd(null);
    }
}

$('#alexa_cmd_tester_select').off('change').on('change', function () {
    var cmd = alexaTesterCmds[$(this).val()] || null;
    alexaRenderTesterCmd(cmd);
});

$('#btn_alexa_cmd_tester_run').off('click').on('click', function () {
    var cmdId = $('#alexa_cmd_tester_select').val();
    var cmd = alexaTesterCmds[cmdId];
    if (!cmd) return;
    var valueObj = {};
    var $val = $('#alexa_cmd_tester_val');
    if (cmd.subType === 'message') {
        var msg = $val.val();
        if (!msg) { $val.focus(); return; }
        valueObj = { title: '', message: msg };
    } else if (cmd.subType === 'slider') {
        var num = $val.val();
        if (num === '' || num === null) { $val.focus(); return; }
        valueObj = { slider: num };
    } else if (cmd.subType === 'select') {
        var opt = $val.val();
        if (opt === '' || opt === null) return;
        valueObj = { select: opt };
    }
    jeedom.cmd.execute({
        id: cmd.id,
        value: valueObj,
        error: function (error) {
            $('#div_alert').showAlert({ message: error.message || error, level: 'danger' });
        },
        success: function () {
            $('#div_alert').showAlert({ message: '{{Command executed}}: ' + cmd.name, level: 'success' });
        }
    });
});

$('#alexa_cmd_tester_input_wrap').off('keypress', '#alexa_cmd_tester_val').on('keypress', '#alexa_cmd_tester_val', function (e) {
    if (e.which === 13) {
        e.preventDefault();
        $('#btn_alexa_cmd_tester_run').click();
    }
});

$('#alexa_cmd_tester_help').off('click', '.alexa-preset-chip').on('click', '.alexa-preset-chip', function () {
    var val = $(this).data('preset-val');
    var $inp = $('#alexa_cmd_tester_val');
    if ($inp.length) {
        $inp.val(val).focus();
    }
});
