<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

$plugin = plugin::byId('alexadevices');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <!-- SIDEBAR / DEVICE LIST -->
    <div class="col-xs-12 col-md-3 col-lg-2">
        <div class="bs-sidebar">
            <ul id="ul_eqLogic" class="nav nav-list bs-sidenav">
                <li class="filter" style="margin-bottom: 5px;">
                    <input class="filter form-control input-sm" placeholder="{{Search...}}" style="width: 100%"/>
                </li>
                <?php
                foreach ($eqLogics as $eqLogic) {
                    $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                    echo '<li class="cursor li_eqLogic ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                    echo '<a>' . $eqLogic->getHumanName(true) . '</a>';
                    echo '</li>';
                }
                ?>
            </ul>
        </div>
    </div>

    <!-- MAIN PANEL -->
    <div class="col-xs-12 col-md-9 col-lg-10 eqLogicThumbnailDisplay" style="border-left: solid 1px #EEE; padding-left: 25px;">
        <legend><i class="fas fa-cog"></i> {{Alexa Devices Management}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" id="bt_syncAlexaDevices">
                <i class="fas fa-sync"></i>
                <br>
                <span>{{Synchronize Devices}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" onclick="window.location.href = 'index.php?v=d&p=plugin&id=alexadevices';">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" id="bt_showPluginLog">
                <i class="fas fa-file-alt"></i>
                <br>
                <span>{{Logs}}</span>
            </div>
        </div>

        <legend><i class="fas fa-volume-up"></i> {{My Echo & Alexa Devices}}</legend>
        <div class="input-group" style="margin-bottom:5px;">
            <input class="form-control roundedLeft" placeholder="{{Filter devices...}}" id="in_searchEqlogic"/>
            <div class="input-group-btn">
                <a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
            </div>
        </div>

        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                $online = $eqLogic->getCmd(null, 'status');
                $isOnline = (is_object($online) && $online->execCmd() == 1);
                $badge = $isOnline ? '<span class="label label-success">{{Online}}</span>' : '<span class="label label-danger">{{Offline}}</span>';

                $vol = $eqLogic->getCmd(null, 'volume');
                $volText = (is_object($vol)) ? '<span class="label label-info" style="font-size:9px;"><i class="fas fa-volume-up"></i> ' . intval($vol->execCmd()) . '%</span>' : '';

                $visual = alexadevices::resolveDeviceVisual($eqLogic);

                echo '<div class="eqLogicDisplayCard cursor alexa-device-card ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<div class="alexa-card-icon-wrapper">';
                echo '<img src="' . htmlspecialchars($visual['image'], ENT_QUOTES, 'UTF-8') . '" class="alexa-device-img" alt="" />';
                echo '</div>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '<div class="alexa-card-model">' . htmlspecialchars($visual['label'], ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="alexa-card-badges">';
                echo $badge . ' ' . $volText;
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- DEVICE EDIT PANEL -->
    <div class="col-xs-12 col-md-9 col-lg-10 eqLogic" style="border-left: solid 1px #EEE; padding-left: 25px; display: none;">
        <a class="btn btn-default eqLogicAction pull-right" data-action="configure"><i class="fas fa-cogs"></i> {{Advanced Configuration}}</a>
        <a class="btn btn-default eqLogicAction pull-right" data-action="copy"><i class="fas fa-copy"></i> {{Duplicate}}</a>
        <a class="btn btn-sm btn-success eqLogicAction pull-right" data-action="save"><i class="fas fa-check-circle"></i> {{Save}}</a>
        <a class="btn btn-danger eqLogicAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i> {{Delete}}</a>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Device}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commands}}</a></li>
        </ul>

        <div class="tab-content" style="height:calc(100% - 50px); overflow:auto; overflow-x: hidden;">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br/>
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Device Name}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Echo Name}}" />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Parent Object}}</label>
                            <div class="col-sm-4">
                                <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{None}}</option>
                                    <?php
                                    $options = '';
                                    foreach ((jeeObject::buildTree(null, false)) as $object) {
                                        $options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                    }
                                    echo $options;
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Category}}</label>
                            <div class="col-sm-8">
                                <?php
                                foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                    echo '<label class="checkbox-inline">';
                                    echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                                    echo '</label>';
                                }
                                ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{State}}</label>
                            <div class="col-sm-9">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Enable}}</label>
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Serial Number (Logical ID)}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="logicalId" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Model}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Hardware / Generation}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="hardware_version" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Manufacturer}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="manufacturer" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Device Family}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_family" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Device Type}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_type" readonly />
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Software Version}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="software_version" readonly />
                            </div>
                        </div>
                    </fieldset>
                </form>

                <hr style="margin: 22px 0 18px 0;"/>
                <div class="alexa-tester-box">
                    <h4 class="alexa-capabilities-title">
                        <i class="fas fa-vial" style="color:#00CAFA;"></i> {{Test available commands}}
                    </h4>
                    <p class="alexa-capabilities-subtitle">
                        {{Select any command from the list to see what it does and test it directly on this device.}}
                    </p>
                    <div class="row" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:0;">
                        <div style="flex:1 1 240px; max-width:340px;">
                            <select id="alexa_cmd_tester_select" class="form-control input-sm"></select>
                        </div>
                        <div id="alexa_cmd_tester_input_wrap" style="flex:2 1 260px; display:none;"></div>
                        <div>
                            <button type="button" id="btn_alexa_cmd_tester_run" class="btn btn-primary btn-sm">
                                <i class="fas fa-play"></i> {{Test now}}
                            </button>
                        </div>
                    </div>
                    <div id="alexa_cmd_tester_help" class="alexa-tester-help"></div>
                </div>

                <div class="alexa-capabilities-section">
                    <h4 class="alexa-capabilities-title">
                        <i class="fas fa-compass" style="color:#00CAFA;"></i> {{What can you do with this Alexa device?}}
                    </h4>
                    <p class="alexa-capabilities-subtitle">
                        {{Quick guide to all available commands and automations. You can test or trigger any of them from the Commands tab or inside Jeedom Scenarios.}}
                    </p>

                    <div class="row">
                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-voice">
                                <div class="alexa-cap-header"><i class="fas fa-comment-dots"></i> {{Voice &amp; Announcements}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{Speak (TTS)}}</strong> — {{Reads any text aloud. Supports SSML tags for pauses, speed, or pitch.}}</li>
                                    <li><strong>{{Whisper}}</strong> — {{Speaks softly in a whisper (can stay active during night silence).}}</li>
                                    <li><strong>{{Announcement}}</strong> — {{Plays a chime and broadcasts an announcement.}}</li>
                                    <li><strong>{{Voice Command}}</strong> — {{Runs any phrase as if you said "Alexa, ..." — ideal for Smart Home actions (e.g. "turn on living room light", "set thermostat to 22 degrees", "turn off workshop plug").}}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-media">
                                <div class="alexa-cap-header"><i class="fas fa-music"></i> {{Audio, Music &amp; Volume}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{Play / Pause / Next / Previous}}</strong> — {{Controls media playback and updates the current track/state.}}</li>
                                    <li><strong>{{Set Volume}}</strong> — {{Sets speaker volume (0–100). Respects the night volume cap if enabled.}}</li>
                                    <li><strong>{{Play Sound}}</strong> — {{Plays built-in Alexa sound effects (chimes, bell, doorbell, etc.).}}</li>
                                    <li><strong>{{Play Music / Radio}}</strong> — {{Starts music or TuneIn radio stations directly on the device.}}</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-routines">
                                <div class="alexa-cap-header"><i class="fas fa-magic"></i> {{Routines, Alarms &amp; Focus}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{Run Routine (List / Text)}}</strong> — {{Triggers any of your Amazon Alexa routines from a dropdown or scenario.}}</li>
                                    <li><strong>{{Do Not Disturb (On / Off)}}</strong> — {{Blocks calls, drop-ins, and notifications when you need quiet.}}</li>
                                    <li><strong>{{Create Alarm / Timer}}</strong> — {{Schedules alarms or countdown timers automatically from Jeedom.}}</li>
                                    <li><strong>{{Night Silence}}</strong> — {{Automatically mutes spoken alerts during configured sleeping hours.}}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-sensors">
                                <div class="alexa-cap-header"><i class="fas fa-microchip"></i> {{Device State &amp; Sensors}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{Online Status &amp; Volume}}</strong> — {{Real-time availability and current volume level.}}</li>
                                    <li><strong>{{Temperature &amp; Bluetooth}}</strong> — {{Built-in room temperature (on supported Echo models) and Bluetooth state.}}</li>
                                    <li><strong>{{Last Command &amp; Next Alarm}}</strong> — {{Displays the latest voice interaction and upcoming alarms/timers.}}</li>
                                    <li><strong>{{Refresh}}</strong> — {{Forces an immediate state update from Amazon servers.}}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-voice">
                                <div class="alexa-cap-header"><i class="fas fa-exchange-alt"></i> {{Jeedom → Alexa: Run Amazon Routines}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{From Scenarios}}</strong> — {{Select the command "Run Routine (List)" (routine_select) and pick any synced routine from the dropdown without typing its name.}}</li>
                                    <li><strong>{{From Dashboard / Tester}}</strong> — {{Mark "Run Routine (List)" as Visible to get the routine dropdown right on the Dashboard widget, or test it above.}}</li>
                                    <li><strong>{{Example}}</strong> — <code>#[Salon][Echo Salón][Run Routine (List)]# → "Buenas noches"</code></li>
                                </ul>
                            </div>
                        </div>

                        <div class="col-xs-12 col-md-6">
                            <div class="alexa-cap-card alexa-cap-routines">
                                <div class="alexa-cap-header"><i class="fas fa-bolt"></i> {{Alexa → Jeedom: Trigger Jeedom Scenarios}}</div>
                                <ul class="alexa-cap-list">
                                    <li><strong>{{By Voice (real-time)}}</strong> — {{Create a short routine in the Alexa app (e.g. "Alexa, cinema mode") and use this device's "Last Voice Command" (last_command) as a triggered Scenario event.}}</li>
                                    <li><strong>{{Example Trigger}}</strong> — <code>#[Salon][Echo Salón][Last Voice Command]#</code></li>
                                    <li><strong>{{Example Condition}}</strong> — <code>#[Salon][Echo Salón][Last Voice Command]# matches "/cinema mode/i"</code></li>
                                    <li><strong>{{Scheduled / Automatic Alexa Routines}}</strong> — {{Amazon does not push internal routine completions; expose a Jeedom virtual switch to Alexa or trigger on a monitored Echo state (e.g. dnd_status or volume).}}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br/>
                <div class="panel panel-info">
                    <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#alexa_ssml_help">
                        <h4 class="panel-title" style="font-size:13px;">
                            <i class="fas fa-question-circle"></i> {{SSML Tags Help for Speak (TTS)}}
                            <i class="fas fa-chevron-down pull-right"></i>
                        </h4>
                    </div>
                    <div id="alexa_ssml_help" class="panel-collapse collapse">
                        <div class="panel-body" style="font-size:12px;">
                            <p>{{You can use SSML tags directly inside Speak (TTS) (they are auto-wrapped in &lt;speak&gt;...&lt;/speak&gt; if omitted), or use the Whisper command directly:}}</p>
                            <table class="table table-condensed table-bordered" style="margin-bottom:0;">
                                <thead>
                                    <tr>
                                        <th>{{Effect}}</th>
                                        <th>{{SSML Tag Example}}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>{{Whispered voice}}</td>
                                        <td><code>&lt;speak&gt;&lt;amazon:effect name="whispered"&gt;{{Hello}}&lt;/amazon:effect&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Pause (max 10s per tag)}}</td>
                                        <td><code>{{One}} &lt;break time="5s"/&gt; {{Two}}</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Volume (x-soft, soft, medium, loud, x-loud)}}</td>
                                        <td><code>&lt;speak&gt;&lt;prosody volume="soft"&gt;{{Hello}}&lt;/prosody&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Speed (x-slow, slow, medium, fast, x-fast)}}</td>
                                        <td><code>&lt;speak&gt;&lt;prosody rate="slow"&gt;{{Hello}}&lt;/prosody&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Pitch (x-low, low, medium, high, x-high)}}</td>
                                        <td><code>&lt;speak&gt;&lt;prosody pitch="low"&gt;{{Hello}}&lt;/prosody&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Emphasis (strong, moderate, reduced)}}</td>
                                        <td><code>&lt;speak&gt;&lt;emphasis level="strong"&gt;{{Attention}}&lt;/emphasis&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                    <tr>
                                        <td>{{Read as digits / date / time}}</td>
                                        <td><code>&lt;speak&gt;&lt;say-as interpret-as="digits"&gt;1234&lt;/say-as&gt;&lt;/speak&gt;</code></td>
                                    </tr>
                                </tbody>
                            </table>
                            <hr style="margin:10px 0;"/>
                            <p style="margin-bottom:6px;"><strong>{{Other commands reference:}}</strong></p>
                            <ul style="margin-bottom:0; padding-left:20px;">
                                <li><strong>{{Play Sound}}</strong>: <code>amzn_sfx_doorbell_chime_01</code>, <code>amzn_sfx_siren_01</code>, <code>bell_02</code>, <code>christmas_01</code></li>
                                <li><strong>{{Play Music}}</strong>: {{Write a song/artist/station name, or specify the provider in Title or with}} <code>PROVEEDOR|búsqueda</code> (<code>SPOTIFY|Queen</code>, <code>TUNEIN|Los 40</code>, <code>AMAZON_MUSIC|Jazz</code>)</li>
                                <li><strong>{{Info Skill}}</strong>: {{Select Weather, Traffic, News, Time, Joke, Good Morning, Good Night, etc.}}</li>
                                <li><strong>{{Run Routine}}</strong>: {{Enter the exact name of your Alexa routine as configured in the Alexa app.}}</li>
                            </ul>
                            <hr style="margin:10px 0;"/>
                            <p style="margin-bottom:6px;"><strong>{{Command limitations &amp; restrictions:}}</strong></p>
                            <ul style="margin-bottom:0; padding-left:20px;">
                                <li><strong>&lt;break time="..."/&gt;</strong>: {{Maximum 10s (10000ms) per tag (Amazon SSML limit). To wait longer or repeat a phrase (e.g. 120s), use a Jeedom Scenario DANS / WAIT block.}}</li>
                                <li><strong>{{Speak / Whisper / Announcement}}</strong>: {{Amazon limits each message to ~500 characters (~240s audio). Blocked during Night Silence if enabled (except Whisper if allowed).}}</li>
                                <li><strong>{{Set Volume}}</strong>: {{Accepts 0–100; automatically capped to the Night Max Volume during night hours when configured.}}</li>
                                <li><strong>{{Speaker Groups / Smart TVs}}</strong>: {{Multi-room groups (WHA) and some third-party TVs do not support Speak or Whisper (Amazon restriction).}}</li>
                                <li><strong>{{Alexa Smart Home devices}}</strong>: {{You can control third-party lights, plugs, blinds or thermostats via Voice Command (text_command) or Amazon Routines, but this plugin does not import them as Jeedom devices or read their state.}}</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th style="width: 250px;">{{Name}}</th>
                            <th style="width: 150px;">{{Type}}</th>
                            <th style="width: 200px;">{{Parameters}}</th>
                            <th style="width: 150px;">{{Options}}</th>
                            <th style="width: 100px;">{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'alexadevices', 'css', 'alexadevices'); ?>
<?php include_file('desktop', 'alexadevices', 'js', 'alexadevices'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
