<?php
///////////////////////////////////////////////////////////////////////////
// Configuration parameters

$debugLevel = 0;

// Point this to the directory containing TWCManager.py.
$twcScriptDir = "/srv/TWCManager/";

// End configuration parameters
///////////////////////////////////////////////////////////////////////////

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate("D, d M Y H:i:s") . 'GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_str($key, $default = '')
{
    return isset($_REQUEST[$key]) ? (string)$_REQUEST[$key] : $default;
}

function request_array($key)
{
    $value = $_REQUEST[$key] ?? array();
    return is_array($value) ? $value : array();
}

function page_url($params = array())
{
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

$ipcKey = ftok($twcScriptDir, "T");
$ipcQueue = msg_get_queue($ipcKey, 0666);

function ipc_send($ipcMsgTime, $ipcMsgID, $ipcMsg, $ipcMsgType = 2)
{
    global $ipcQueue, $debugLevel;

    if($debugLevel >= 10) {
        print "ipcQuery sending '" . h($ipcMsg) . "', id " . $ipcMsgID
            . ", time " . $ipcMsgTime . "<p>";
    }

    $ipcErrorCode = 0;
    if(msg_send($ipcQueue, $ipcMsgType, pack("LSa*", $ipcMsgTime, $ipcMsgID, $ipcMsg),
                false, false, $ipcErrorCode) == false
    ) {
        return false;
    }
    return true;
}

function ipc_command($command)
{
    return ipc_send(time(), 0, $command, 2);
}

function ipc_query($command, $usePackets = false)
{
    global $ipcQueue, $debugLevel;

    $ipcMsgID = rand(1, 65535);
    $ipcMsgTime = time();
    if(ipc_send($ipcMsgTime, $ipcMsgID, $command, 2) == false) {
        return '';
    }

    $ipcMsgType = 0;
    $ipcMsgRecv = '';
    $ipcMaxMsgSize = 300;
    $maxRetries = 50;
    $numPackets = 0;
    $msgResult = '';

    for($i = 0; $i < $maxRetries; $i++) {
        $ipcErrorCode = 0;
        if(msg_receive($ipcQueue, 1, $ipcMsgType, $ipcMaxMsgSize, $ipcMsgRecv, false,
                       MSG_IPC_NOWAIT | MSG_NOERROR, $ipcErrorCode) == false
        ) {
            if($ipcErrorCode != 42 && $debugLevel >= 1) {
                print("Message receive failed with error code $ipcErrorCode<br>");
            }
        }
        else {
            $aryMsg = unpack("Ltime/SID/a*msg", $ipcMsgRecv);
            if($debugLevel >= 10) {
                print "ipcQuery received '" . h($aryMsg['msg']) . "', id " . $aryMsg['ID']
                    . ", time " . $aryMsg['time'] . "<p>";
            }

            if($aryMsg['ID'] == $ipcMsgID) {
                if($usePackets) {
                    if($numPackets == 0) {
                        $numPackets = ord($aryMsg['msg']);
                    }
                    else {
                        $msgResult .= $aryMsg['msg'];
                        $numPackets--;
                        if($numPackets == 0) {
                            return $msgResult;
                        }
                    }
                    continue;
                }

                return $aryMsg['msg'];
            }

            if(time() - $aryMsg['time'] < 30) {
                ipc_send($aryMsg['time'], $aryMsg['ID'], $aryMsg['msg'], 1);
            }
        }

        usleep(100000);
    }

    return '';
}

function format_time_label($time)
{
    if(!preg_match('/^-?\d{1,2}:\d{2}$/', (string)$time)) {
        return 'Not set';
    }
    return $time;
}

function format_day_bitmap($bitmap)
{
    $labels = array('Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su');
    $active = array();
    for($i = 0; $i < 7; $i++) {
        if($bitmap & (1 << $i)) {
            $active[] = $labels[$i];
        }
    }
    return $active ? implode(', ', $active) : 'None';
}

function build_hour_options($use24HourTime)
{
    $hours = array();
    for($hour = 0; $hour < 24; $hour++) {
        $value = sprintf("%02d:00", $hour);
        if($use24HourTime) {
            $label = $value;
        }
        else {
            if($hour == 0) {
                $label = '12:00am';
            }
            elseif($hour < 12) {
                $label = sprintf("%d:00am", $hour);
            }
            elseif($hour == 12) {
                $label = '12:00pm';
            }
            else {
                $label = sprintf("%d:00pm", $hour - 12);
            }
        }
        $hours[$label] = $value;
    }
    return $hours;
}

function build_standard_amps($twcModelMaxAmps, $wiringMaxAmpsAllTWCs, $minAmpsPerTWC)
{
    if($twcModelMaxAmps < 40) {
        $amps = array(
            '6A' => '6',
            '8A' => '8',
            '10A' => '10',
            '13A' => '13',
            '17A' => '17',
            '21A' => '21',
            '25A' => '25',
            '32A' => '32',
        );
    }
    else {
        $amps = array(
            '6A' => '6',
            '8A' => '8',
            '12A' => '12',
            '16A' => '16',
            '20A' => '20',
            '24A' => '24',
            '28A' => '28',
            '32A' => '32',
            '36A' => '36',
            '40A' => '40',
            '48A' => '48',
            '56A' => '56',
            '64A' => '64',
            '72A' => '72',
            '80A' => '80',
        );
    }

    foreach($amps as $label => $value) {
        if((float)$value > (float)$wiringMaxAmpsAllTWCs || (float)$value < (float)$minAmpsPerTWC) {
            unset($amps[$label]);
        }
    }

    return $amps;
}

function render_select($name, $options, $currentValue, $extraAttrs = '')
{
    print '<select name="' . h($name) . '" id="' . h($name) . '"' . $extraAttrs . '>';
    foreach($options as $label => $value) {
        $selected = ((string)$currentValue === (string)$value) ? ' selected' : '';
        print '<option value="' . h($value) . '"' . $selected . '>' . h($label) . '</option>';
    }
    print '</select>';
}

function render_checkbox($name, $checked)
{
    print '<input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
}

function describe_twc_state($twc, $availableAmps, $minAmpsPerTWC)
{
    $actual = (float)$twc['actual_amps'];
    $offered = (float)$twc['offered_amps'];

    if($actual < 1.0) {
        if($offered < 5.0) {
            if($availableAmps > 0 && $availableAmps < $minAmpsPerTWC) {
                return 'Power available is below the configured minimum charge current.';
            }
            return 'No charging power is currently available.';
        }

        return 'Not actively charging. Vehicle may be finished, unplugged, asleep, or waking.';
    }

    if($offered - $actual > 1.0) {
        return 'Charging below the offered limit.';
    }

    return 'Charging normally.';
}

function parse_status_response($response)
{
    $defaults = array(
        'raw' => $response,
        'valid' => false,
        'available_amps' => 0,
        'wiring_max_amps' => 80,
        'min_amps_per_twc' => 6,
        'charge_now_amps' => 0,
        'non_scheduled_amps' => '-1',
        'scheduled_amps' => '-1',
        'scheduled_start' => '00:00',
        'scheduled_end' => '00:00',
        'scheduled_days_bitmap' => 0,
        'resume_track_green_energy_time' => '-1:00',
        'need_tesla_tokens' => false,
        'twcs' => array(),
        'num_twcs' => 0,
    );

    if($response === '') {
        return $defaults;
    }

    $parts = explode('`', $response);
    if(count($parts) < 12) {
        return $defaults;
    }

    $idx = 0;
    $status = $defaults;
    $status['available_amps'] = (float)$parts[$idx++];
    $status['wiring_max_amps'] = (float)$parts[$idx++];
    $status['min_amps_per_twc'] = (float)$parts[$idx++];
    $status['charge_now_amps'] = (float)$parts[$idx++];
    $status['non_scheduled_amps'] = (string)$parts[$idx++];
    $status['scheduled_amps'] = (string)$parts[$idx++];
    $status['scheduled_start'] = (string)$parts[$idx++];
    $status['scheduled_end'] = (string)$parts[$idx++];
    $status['scheduled_days_bitmap'] = (int)$parts[$idx++];
    $status['resume_track_green_energy_time'] = (string)$parts[$idx++];
    $status['need_tesla_tokens'] = ($parts[$idx++] === '1');
    $status['num_twcs'] = isset($parts[$idx]) ? (int)$parts[$idx] : 0;
    $idx++;

    for($i = 0; $i < $status['num_twcs']; $i++) {
        if(!isset($parts[$idx])) {
            break;
        }
        $sub = explode('~', $parts[$idx++]);
        $status['twcs'][] = array(
            'id' => (string)($sub[0] ?? '????'),
            'max_amps' => (float)($sub[1] ?? 0),
            'actual_amps' => (float)($sub[2] ?? 0),
            'offered_amps' => (float)($sub[3] ?? 0),
            'state' => (string)($sub[4] ?? ''),
        );
    }

    $status['valid'] = true;
    return $status;
}

function decode_debug_response($response)
{
    if(strpos($response, 'FD 19') === 0) {
        $serialHexAry = explode(' ', substr($response, 6, strlen($response) - 9));
        $stsn = '';
        foreach($serialHexAry as $value) {
            $ascii = hexdec($value);
            if($ascii > 0 && $ascii < 0xFF) {
                $stsn .= chr($ascii);
            }
        }
        return '(S)TSN: ' . $stsn;
    }

    if(strpos($response, 'FD 1A') === 0) {
        $serialHexAry = explode(' ', substr($response, 6, strlen($response) - 9));
        $model = '';
        foreach($serialHexAry as $value) {
            $ascii = hexdec($value);
            if($ascii > 0 && $ascii < 0xFF) {
                $model .= chr($ascii);
            }
        }
        return 'Model: ' . $model;
    }

    if(strpos($response, 'FD 1B') === 0) {
        return 'Firmware version: '
            . hexdec(substr($response, 6, 2)) . '.'
            . hexdec(substr($response, 9, 2)) . '.'
            . hexdec(substr($response, 12, 2));
    }

    return '';
}

$pageMode = 'normal';
$flashMessage = '';
$flashError = '';
$debugResponse = '';
$debugDecoded = '';
$dumpStateResponse = '';

$request = $_REQUEST;
$submit = request_str('submit');
$debugTWC = request_str('debugTWC');
$setDebugLevel = request_str('setDebugLevel');
$beginTest = request_str('beginTest');
$sendTWCMsg = request_str('sendTWCMsg');
$setMasterHeartbeatData = request_str('setMasterHeartbeatData');
$dumpState = request_str('dumpState');
$nonScheduledAmpsMaxRequest = request_str('nonScheduledAmpsMax');
$scheduledAmpsMaxRequest = request_str('scheduledAmpsMax');
$scheduledAmpStartTimeRequest = request_str('scheduledAmpStartTime');
$scheduledAmpsEndTimeRequest = request_str('scheduledAmpsEndTime');
$resumeTrackGreenEnergyTimeRequest = request_str('resumeTrackGreenEnergyTime');
$scheduledAmpsDayRequest = request_array('scheduledAmpsDay');

if($debugTWC !== '') {
    $pageMode = 'debug';
    if($submit !== '') {
        if((int)$setDebugLevel > 0) {
            if(ipc_command('setDebugLevel=' . intval($setDebugLevel))) {
                $flashMessage = 'Debug level updated.';
            }
            else {
                $flashError = 'Unable to send debug level command.';
            }
        }
        elseif(array_key_exists('beginTest', $request)) {
            $cmd = ($beginTest === '') ? 'beginTest' : ('beginTest=' . $beginTest);
            if(ipc_command($cmd)) {
                $flashMessage = 'Debug test command sent.';
            }
            else {
                $flashError = 'Unable to send debug test command.';
            }
        }
    }
}
elseif(array_key_exists('sendTWCMsg', $request)) {
    $pageMode = 'send';
    if($submit !== '' && $sendTWCMsg !== '') {
        if(ipc_command('sendTWCMsg=' . preg_replace('/[ \r\n\t]/', '', $sendTWCMsg))) {
            sleep(3);
            if(substr($sendTWCMsg, 0, 4) === 'FCA1') {
                sleep(5);
            }
            $debugResponse = ipc_query('getLastTWCMsgResponse');
            $debugDecoded = decode_debug_response($debugResponse);
            $flashMessage = 'RS-485 debug message sent.';
        }
        else {
            $flashError = 'Unable to send RS-485 debug message.';
        }
    }
}
elseif(array_key_exists('setMasterHeartbeatData', $request)) {
    $pageMode = 'heartbeat';
    if($submit !== '') {
        if(ipc_command('setMasterHeartbeatData=' . preg_replace('/[ \r\n\t]/', '', $setMasterHeartbeatData))) {
            $flashMessage = ($setMasterHeartbeatData === '')
                ? 'Master heartbeat override cleared.'
                : 'Master heartbeat override updated.';
        }
        else {
            $flashError = 'Unable to send master heartbeat override.';
        }
    }
}
elseif($dumpState !== '') {
    $pageMode = 'dump';
    $dumpStateResponse = ipc_query('dumpState', true);
    if($dumpStateResponse === '') {
        $flashError = 'No response from TWCManager.';
    }
}
else {
    if($nonScheduledAmpsMaxRequest !== '') {
        if(ipc_command('setNonScheduledAmps=' . $nonScheduledAmpsMaxRequest)) {
            $flashMessage = 'Non-scheduled charging limit updated.';
        }
        else {
            $flashError = 'Unable to update non-scheduled charging limit.';
        }
    }

    if($scheduledAmpsMaxRequest !== '') {
        $daysBitmap = 0;
        for($i = 0; $i < 7; $i++) {
            if(!empty($scheduledAmpsDayRequest[$i])) {
                $daysBitmap |= (1 << $i);
            }
        }

        $cmd = 'setScheduledAmps=' . $scheduledAmpsMaxRequest
            . "\nstartTime=" . $scheduledAmpStartTimeRequest
            . "\nendTime=" . $scheduledAmpsEndTimeRequest
            . "\ndays=" . $daysBitmap;
        if(ipc_command($cmd)) {
            $flashMessage = 'Scheduled charging settings updated.';
        }
        else {
            $flashError = 'Unable to update scheduled charging settings.';
        }
    }

    if($resumeTrackGreenEnergyTimeRequest !== '') {
        if(ipc_command('setResumeTrackGreenEnergyTime=' . $resumeTrackGreenEnergyTimeRequest)) {
            $flashMessage = 'Green-energy resume time updated.';
        }
        else {
            $flashError = 'Unable to update green-energy resume time.';
        }
    }

    if(preg_match('/^1-day charge/', $submit)) {
        if(ipc_command('chargeNow')) {
            $flashMessage = '24-hour charge override enabled.';
        }
        else {
            $flashError = 'Unable to enable 24-hour charge override.';
        }
    }
    elseif($submit === 'Cancel 1-day charge') {
        if(ipc_command('chargeNowCancel')) {
            $flashMessage = '24-hour charge override cancelled.';
        }
        else {
            $flashError = 'Unable to cancel 24-hour charge override.';
        }
    }
}

$status = parse_status_response(ipc_query('getStatus'));
$twcModelMaxAmps = 80;
foreach($status['twcs'] as $twc) {
    $twcModelMaxAmps = (float)$twc['max_amps'];
}
$use24HourTime = ($twcModelMaxAmps < 40);
$standardAmps = build_standard_amps($twcModelMaxAmps, $status['wiring_max_amps'], $status['min_amps_per_twc']);
$hourOptions = build_hour_options($use24HourTime);
$scheduledAmpDays = array();
for($i = 0; $i < 7; $i++) {
    $scheduledAmpDays[$i] = (($status['scheduled_days_bitmap'] & (1 << $i)) !== 0);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?=($pageMode === 'debug' ? 'TWCDebug' : 'TWCManager')?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f2efe8;
            --panel: #fcfaf5;
            --panel-strong: #fffdf8;
            --ink: #203038;
            --muted: #61737d;
            --line: #d6cec1;
            --accent: #9e3d1f;
            --accent-soft: #ead2c8;
            --ok-bg: #edf7ef;
            --ok-ink: #24533b;
            --warn-bg: #fff2e8;
            --warn-ink: #8c3a17;
            --shadow: 0 14px 36px rgba(48, 37, 20, 0.10);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(210, 168, 131, 0.30), transparent 36%),
                linear-gradient(160deg, #efe7da 0%, var(--bg) 100%);
            font-family: Georgia, "Times New Roman", serif;
        }
        a {
            color: var(--accent);
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 36px;
        }
        .hero {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            margin-bottom: 18px;
        }
        .hero h1 {
            margin: 0 0 8px;
            font-size: 2rem;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            max-width: 760px;
            line-height: 1.5;
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }
        .button,
        button,
        input[type="submit"],
        input[type="image"] {
            font: inherit;
        }
        .button,
        button,
        input[type="submit"] {
            border: 0;
            background: var(--accent);
            color: #fff;
            padding: 11px 16px;
            cursor: pointer;
            box-shadow: var(--shadow);
        }
        .button.secondary,
        .secondary-button {
            background: #43515a;
        }
        .button.ghost {
            background: transparent;
            color: var(--accent);
            border: 1px solid var(--line);
            box-shadow: none;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 18px;
        }
        .panel h2, .panel h3 {
            margin-top: 0;
        }
        .grid {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.2fr 1fr;
        }
        .summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 14px;
        }
        .metric {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            padding: 14px;
        }
        .metric .label {
            display: block;
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        .metric .value {
            font-size: 1.45rem;
            font-weight: 700;
        }
        .flash {
            padding: 13px 15px;
            margin-bottom: 18px;
            border: 1px solid var(--line);
        }
        .flash.ok {
            background: var(--ok-bg);
            color: var(--ok-ink);
        }
        .flash.error {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }
        .twc-list {
            display: grid;
            gap: 14px;
        }
        .twc-card {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            padding: 16px;
        }
        .twc-card header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 10px;
        }
        .twc-card h3 {
            margin: 0;
        }
        .twc-state {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .twc-stats {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 10px;
        }
        .stat {
            padding: 10px;
            border: 1px solid var(--line);
            background: #f8f3eb;
        }
        .stat .label {
            display: block;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .stat .value {
            display: block;
            margin-top: 5px;
            font-weight: 700;
        }
        .muted {
            color: var(--muted);
        }
        form {
            margin: 0;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .field {
            margin-bottom: 14px;
        }
        .field.full {
            grid-column: 1 / -1;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }
        select,
        input[type="text"],
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink);
        }
        textarea {
            min-height: 220px;
            font-family: "Courier New", monospace;
            font-size: 14px;
        }
        .days {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            margin-top: 8px;
        }
        .days label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            font-weight: 400;
        }
        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .callout {
            border: 1px solid var(--line);
            background: #f8f2e8;
            padding: 14px;
            margin-top: 14px;
        }
        .subnav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .codebox {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .debug-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        @media (max-width: 920px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
                display: grid;
            }
            .summary-grid,
            .twc-stats,
            .field-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .wrap {
                padding: 18px 12px 28px;
            }
            .summary-grid,
            .twc-stats,
            .field-grid {
                grid-template-columns: 1fr;
            }
            .hero-actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <h1>TWCManager</h1>
            <p>Control Tesla Wall Connector Gen2 charging, review live RS-485 status, and adjust schedules and green-energy policy from one page.</p>
        </div>
        <div class="hero-actions">
            <a class="button ghost" href="<?=h(page_url())?>">Main View</a>
            <a class="button ghost" href="<?=h(page_url(array('debugTWC' => 1)))?>">Debug Menu</a>
            <a class="button ghost" href="tesla_callback.php">Tesla Token Helper</a>
        </div>
    </div>

    <?php if($flashMessage !== ''): ?>
    <div class="flash ok"><?=h($flashMessage)?></div>
    <?php endif; ?>

    <?php if($flashError !== ''): ?>
    <div class="flash error"><?=h($flashError)?></div>
    <?php endif; ?>

    <?php if(!$status['valid']): ?>
    <div class="flash error">No valid status response was received from TWCManager. Verify that the Python process is running, that <code>$twcScriptDir</code> is correct, and that PHP can access the SysV IPC queue.</div>
    <?php endif; ?>

    <?php if($pageMode === 'debug'): ?>
    <div class="panel">
        <h2>Debug Menu</h2>
        <p class="muted">Use these tools only for protocol diagnostics. Some commands can affect charger behavior directly.</p>

        <div class="field-grid">
            <form class="field" action="index.php" method="get">
                <input type="hidden" name="debugTWC" value="1">
                <label for="setDebugLevel">Debug Level</label>
                <input id="setDebugLevel" type="text" name="setDebugLevel" value="<?=h($setDebugLevel)?>">
                <div class="inline-actions">
                    <input type="submit" name="submit" value="Set">
                </div>
            </form>

            <form class="field" action="index.php" method="get">
                <input type="hidden" name="debugTWC" value="1">
                <label for="beginTest">Debug Test</label>
                <input id="beginTest" type="text" name="beginTest" value="<?=h($beginTest)?>">
                <div class="inline-actions">
                    <input type="submit" name="submit" value="Begin">
                </div>
            </form>
        </div>

        <div class="debug-links">
            <a class="button ghost" href="<?=h(page_url(array('sendTWCMsg' => '', 'submit' => 1)))?>">Send RS-485 Message</a>
            <a class="button ghost" href="<?=h(page_url(array('setMasterHeartbeatData' => '', 'submit' => 1)))?>">Override Master Heartbeat</a>
            <a class="button ghost" href="<?=h(page_url(array('dumpState' => 1, 'submit' => 1)))?>">Dump Internal State</a>
        </div>
    </div>
    <?php elseif($pageMode === 'send'): ?>
    <div class="panel">
        <h2>Send RS-485 Message</h2>
        <p class="muted">This tool is for protocol debugging. TWCManager blocks some dangerous message types, but sending arbitrary frames still carries risk.</p>

        <form action="index.php" method="get">
            <div class="field">
                <label for="sendTWCMsg">Hex Payload</label>
                <input id="sendTWCMsg" type="text" name="sendTWCMsg" value="<?=h($sendTWCMsg)?>">
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Submit">
                <a class="button ghost" href="<?=h(page_url(array('debugTWC' => 1)))?>">Back To Debug Menu</a>
            </div>
        </form>

        <div class="subnav">
            <a href="<?=h(page_url(array('sendTWCMsg' => 'FB1B', 'submit' => 1)))?>">Get firmware version</a>
            <a href="<?=h(page_url(array('sendTWCMsg' => 'FB19', 'submit' => 1)))?>">Get serial number</a>
            <a href="<?=h(page_url(array('sendTWCMsg' => 'FB1A', 'submit' => 1)))?>">Get model</a>
            <a href="<?=h(page_url(array('sendTWCMsg' => 'FCE1777766', 'submit' => 1)))?>">Master Linkready1</a>
            <a href="<?=h(page_url(array('sendTWCMsg' => 'FBE2777766', 'submit' => 1)))?>">Master Linkready2</a>
        </div>

        <?php if($debugResponse !== ''): ?>
        <div class="callout">
            <strong>Response:</strong>
            <div class="codebox"><?=h($debugResponse)?></div>
            <?php if($debugDecoded !== ''): ?>
            <p><strong>Decoded:</strong> <?=h($debugDecoded)?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif($pageMode === 'heartbeat'): ?>
    <div class="panel">
        <h2>Override Master Heartbeat</h2>
        <p class="muted">This tool forces custom master heartbeat payload data for debugging and protocol experiments.</p>
        <form action="index.php" method="get">
            <div class="field">
                <label for="setMasterHeartbeatData">Heartbeat Data</label>
                <input id="setMasterHeartbeatData" type="text" name="setMasterHeartbeatData" value="<?=h($setMasterHeartbeatData)?>">
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Submit">
                <a class="button ghost" href="<?=h(page_url(array('setMasterHeartbeatData' => '', 'submit' => 1)))?>">Clear Override</a>
                <a class="button ghost" href="<?=h(page_url(array('debugTWC' => 1)))?>">Back To Debug Menu</a>
            </div>
        </form>
        <div class="subnav">
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '05', 'submit' => 1)))?>">Charge 0A</a>
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '050258', 'submit' => 1)))?>">Charge 6A</a>
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '050834', 'submit' => 1)))?>">Charge 21A</a>
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '050FA0', 'submit' => 1)))?>">Charge 40A</a>
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '093200', 'submit' => 1)))?>">Charge 128A</a>
            <a href="<?=h(page_url(array('setMasterHeartbeatData' => '0201', 'submit' => 1)))?>">Error 1</a>
        </div>
    </div>
    <?php elseif($pageMode === 'dump'): ?>
    <div class="panel">
        <h2>Internal State Dump</h2>
        <p class="muted">Raw state returned by the Python process.</p>
        <form action="index.php" method="get">
            <input type="hidden" name="dumpState" value="1">
            <div class="field">
                <textarea readonly><?=h($dumpStateResponse)?></textarea>
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Refresh">
                <a class="button ghost" href="<?=h(page_url(array('debugTWC' => 1)))?>">Back To Debug Menu</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Live Status</h2>
        <p class="muted">Current charger state from the running TWCManager process.</p>
        <div class="summary-grid">
            <div class="metric">
                <span class="label">Power Available</span>
                <span class="value"><?=($status['available_amps'] > 0 ? h(number_format($status['available_amps'], 2)) . 'A' : 'None')?></span>
            </div>
            <div class="metric">
                <span class="label">Wiring Limit</span>
                <span class="value"><?=h(number_format($status['wiring_max_amps'], 0))?>A</span>
            </div>
            <div class="metric">
                <span class="label">Minimum Charge</span>
                <span class="value"><?=h(number_format($status['min_amps_per_twc'], 0))?>A</span>
            </div>
            <div class="metric">
                <span class="label">24-Hour Override</span>
                <span class="value"><?=($status['charge_now_amps'] > 0 ? h(number_format($status['charge_now_amps'], 2)) . 'A' : 'Off')?></span>
            </div>
        </div>

        <?php if($status['need_tesla_tokens']): ?>
        <div class="callout">
            <strong>Tesla token action needed.</strong>
            A connected charger appears to need Tesla API access, but no tokens are loaded.
            Use <a href="tesla_callback.php">Tesla Token Helper</a> to generate `TeslaApiTokens.json`.
        </div>
        <?php endif; ?>
    </div>

    <div class="grid">
        <div>
            <div class="panel">
                <h2>Managed Wall Connectors</h2>
                <?php if(count($status['twcs']) === 0): ?>
                <p class="muted">No slave TWCs were reported on the RS-485 network.</p>
                <?php else: ?>
                <div class="twc-list">
                    <?php foreach($status['twcs'] as $twc): ?>
                    <article class="twc-card">
                        <header>
                            <h3>TWC <?=h($twc['id'])?></h3>
                            <span class="twc-state">State <?=h($twc['state'])?></span>
                        </header>
                        <div class="twc-stats">
                            <div class="stat">
                                <span class="label">Actual</span>
                                <span class="value"><?=h(number_format($twc['actual_amps'], 2))?>A</span>
                            </div>
                            <div class="stat">
                                <span class="label">Offered</span>
                                <span class="value"><?=h(number_format($twc['offered_amps'], 2))?>A</span>
                            </div>
                            <div class="stat">
                                <span class="label">Model Max</span>
                                <span class="value"><?=h(number_format($twc['max_amps'], 0))?>A</span>
                            </div>
                            <div class="stat">
                                <span class="label">Headroom</span>
                                <span class="value"><?=h(number_format(max(0, $twc['offered_amps'] - $twc['actual_amps']), 2))?>A</span>
                            </div>
                        </div>
                        <div class="muted"><?=h(describe_twc_state($twc, $status['available_amps'], $status['min_amps_per_twc']))?></div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="panel">
                <h2>Charging Policy</h2>
                <div class="field-grid">
                    <div class="field">
                        <label>Scheduled Power</label>
                        <div class="callout">
                            <strong><?=($status['scheduled_amps'] === '-1' ? 'Disabled' : h($status['scheduled_amps']) . 'A')?></strong><br>
                            <?=h(format_time_label($status['scheduled_start']))?> to <?=h(format_time_label($status['scheduled_end']))?><br>
                            <?=h(format_day_bitmap($status['scheduled_days_bitmap']))?>
                        </div>
                    </div>
                    <div class="field">
                        <label>Non-Scheduled Power</label>
                        <div class="callout">
                            <?php if($status['non_scheduled_amps'] === '-1'): ?>
                            <strong>Track green energy</strong>
                            <?php else: ?>
                            <strong><?=h($status['non_scheduled_amps'])?>A</strong>
                            <?php endif; ?>
                            <br>
                            Resume green energy at <?=h(format_time_label($status['resume_track_green_energy_time']))?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>Adjust Settings</h2>
                <form action="index.php" method="get">
                    <div class="field-grid">
                        <div class="field full">
                            <label for="scheduledAmpsMax">Scheduled Power</label>
                            <?php render_select(
                                'scheduledAmpsMax',
                                array_merge(array('Disabled' => '-1'), $standardAmps),
                                $status['scheduled_amps'],
                                ' onchange="toggleScheduleFields()"'
                            ); ?>
                        </div>

                        <div class="field" id="scheduledStartField">
                            <label for="scheduledAmpStartTime">Start Time</label>
                            <?php render_select('scheduledAmpStartTime', $hourOptions, $status['scheduled_start']); ?>
                        </div>

                        <div class="field" id="scheduledEndField">
                            <label for="scheduledAmpsEndTime">End Time</label>
                            <?php render_select('scheduledAmpsEndTime', $hourOptions, $status['scheduled_end']); ?>
                        </div>

                        <div class="field full" id="scheduledDaysField">
                            <label>Scheduled Days</label>
                            <div class="days">
                                <?php
                                $dayLabels = array('Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su');
                                for($i = 0; $i < 7; $i++):
                                ?>
                                <label><?php render_checkbox("scheduledAmpsDay[$i]", $scheduledAmpDays[$i]); ?> <?=$dayLabels[$i]?></label>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="field full">
                            <label for="nonScheduledAmpsMax">Non-Scheduled Power</label>
                            <?php render_select(
                                'nonScheduledAmpsMax',
                                array_merge(array('Track green energy' => '-1', 'Do not charge' => '0'), $standardAmps),
                                $status['non_scheduled_amps'],
                                ' onchange="toggleResumeField()"'
                            ); ?>
                        </div>

                        <div class="field full" id="resumeGreenField">
                            <label for="resumeTrackGreenEnergyTime">Resume "Track green energy" At</label>
                            <?php render_select(
                                'resumeTrackGreenEnergyTime',
                                array_merge(array('Never' => '-1:00'), $hourOptions),
                                $status['resume_track_green_energy_time']
                            ); ?>
                        </div>
                    </div>

                    <div class="inline-actions">
                        <input type="submit" name="submit" value="Save">
                        <?php if($status['charge_now_amps'] > 0): ?>
                        <input type="submit" name="submit" value="Cancel 1-day charge">
                        <?php else: ?>
                        <input type="submit" name="submit" value="1-day charge, <?=h(number_format($status['wiring_max_amps'], 0))?>A">
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleResumeField() {
    const nonScheduled = document.getElementById('nonScheduledAmpsMax');
    const resume = document.getElementById('resumeGreenField');
    if (!nonScheduled || !resume) return;
    resume.style.display = (nonScheduled.value === '-1') ? 'none' : 'block';
}

function toggleScheduleFields() {
    const scheduled = document.getElementById('scheduledAmpsMax');
    const visible = scheduled && scheduled.value !== '-1';
    ['scheduledStartField', 'scheduledEndField', 'scheduledDaysField'].forEach(function(id) {
        const node = document.getElementById(id);
        if (node) {
            node.style.display = visible ? 'block' : 'none';
        }
    });
}

toggleResumeField();
toggleScheduleFields();
</script>
</body>
</html>
