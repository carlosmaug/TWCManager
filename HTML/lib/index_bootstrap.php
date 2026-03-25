<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate("D, d M Y H:i:s") . 'GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$ipcKey = ftok($twcScriptDir, "T");
$ipcQueue = msg_get_queue($ipcKey, 0666);

$pageMode = 'normal';
$flashMessage = '';
$flashError = '';
$debugResponse = '';
$debugDecoded = '';
$dumpStateResponse = '';

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

$initialStatus = parse_status_response(ipc_query('getStatus'));
$initialTwcModelMaxAmps = 80;
foreach($initialStatus['twcs'] as $twc) {
    $initialTwcModelMaxAmps = (float)$twc['max_amps'];
}
$initialUse24HourTime = ($initialTwcModelMaxAmps < 40);
$allowedStandardAmps = build_standard_amps(
    $initialTwcModelMaxAmps,
    $initialStatus['wiring_max_amps'],
    $initialStatus['min_amps_per_twc']
);
$allowedStandardAmpValues = array_values($allowedStandardAmps);
$allowedScheduledAmpValues = array_merge(array('-1'), $allowedStandardAmpValues);
$allowedNonScheduledAmpValues = array_merge(array('-1', '0'), $allowedStandardAmpValues);
$allowedHourValues = array_values(build_hour_options($initialUse24HourTime));
$allowedResumeTrackValues = array_merge(array('-1:00'), $allowedHourValues);

if($debugTWC !== '') {
    $pageMode = 'debug';
    if($submit !== '') {
        $validatedDebugLevel = validate_int_range($setDebugLevel, 0, 100);
        if($validatedDebugLevel !== null) {
            if(ipc_command('setDebugLevel=' . $validatedDebugLevel)) {
                $flashMessage = 'Debug level updated.';
            }
            else {
                $flashError = 'Unable to send debug level command.';
            }
        }
        elseif(request_present('setDebugLevel')) {
            $flashError = 'Debug level must be an integer between 0 and 100.';
        }
        elseif(request_present('beginTest')) {
            $validatedBeginTest = validate_debug_token($beginTest);
            if($validatedBeginTest === null) {
                $flashError = 'Debug test contains invalid characters.';
            }
            else {
                $cmd = ($validatedBeginTest === '') ? 'beginTest' : ('beginTest=' . $validatedBeginTest);
                if(ipc_command($cmd)) {
                    $flashMessage = 'Debug test command sent.';
                }
                else {
                    $flashError = 'Unable to send debug test command.';
                }
            }
        }
    }
}
elseif(request_present('sendTWCMsg')) {
    $pageMode = 'send';
    if($submit !== '' && $sendTWCMsg !== '') {
        $validatedSendTWCMsg = validate_hex_payload($sendTWCMsg, false, 15);
        if($validatedSendTWCMsg === null) {
            $flashError = 'RS-485 payload must be valid hex with an even number of characters and at most 15 bytes.';
        }
        elseif(ipc_command('sendTWCMsg=' . $validatedSendTWCMsg)) {
            sleep(3);
            if(substr($validatedSendTWCMsg, 0, 4) === 'FCA1') {
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
elseif(request_present('setMasterHeartbeatData')) {
    $pageMode = 'heartbeat';
    if($submit !== '') {
        $validatedHeartbeatData = validate_hex_payload($setMasterHeartbeatData, true, 9);
        if($validatedHeartbeatData === null) {
            $flashError = 'Master heartbeat override must be valid hex with an even number of characters and at most 9 bytes.';
        }
        elseif(ipc_command('setMasterHeartbeatData=' . $validatedHeartbeatData)) {
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
        $validatedNonScheduledAmps = validate_choice($nonScheduledAmpsMaxRequest, $allowedNonScheduledAmpValues);
        if($validatedNonScheduledAmps === null) {
            $flashError = 'Invalid non-scheduled charging limit.';
        }
        elseif(ipc_command('setNonScheduledAmps=' . $validatedNonScheduledAmps)) {
            $flashMessage = 'Non-scheduled charging limit updated.';
        }
        else {
            $flashError = 'Unable to update non-scheduled charging limit.';
        }
    }

    if($scheduledAmpsMaxRequest !== '') {
        $validatedScheduledAmps = validate_choice($scheduledAmpsMaxRequest, $allowedScheduledAmpValues);
        $validatedScheduledStart = validate_choice($scheduledAmpStartTimeRequest, $allowedHourValues);
        $validatedScheduledEnd = validate_choice($scheduledAmpsEndTimeRequest, $allowedHourValues);
        $daysBitmap = checked_days_bitmap($scheduledAmpsDayRequest);

        if($validatedScheduledAmps === null) {
            $flashError = 'Invalid scheduled charging limit.';
        }
        elseif($validatedScheduledAmps !== '-1'
               && ($validatedScheduledStart === null || $validatedScheduledEnd === null)
        ) {
            $flashError = 'Scheduled charging times are invalid.';
        }
        else {
            $cmd = 'setScheduledAmps=' . $validatedScheduledAmps
                . "\nstartTime=" . ($validatedScheduledStart ?? '00:00')
                . "\nendTime=" . ($validatedScheduledEnd ?? '00:00')
                . "\ndays=" . $daysBitmap;
            if(ipc_command($cmd)) {
                $flashMessage = 'Scheduled charging settings updated.';
            }
            else {
                $flashError = 'Unable to update scheduled charging settings.';
            }
        }
    }

    if($resumeTrackGreenEnergyTimeRequest !== '') {
        $validatedResumeTrack = validate_choice($resumeTrackGreenEnergyTimeRequest, $allowedResumeTrackValues);
        if($validatedResumeTrack === null) {
            $flashError = 'Invalid green-energy resume time.';
        }
        elseif(ipc_command('setResumeTrackGreenEnergyTime=' . $validatedResumeTrack)) {
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
$energyHistoryCharts = parse_energy_history_response(ipc_query('getEnergyHistory', true));
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

$pageTitle = ($pageMode === 'debug') ? 'TWCDebug | TWCManager Control Panel' : 'TWCManager Control Panel';
$mainViewUrl = page_url();
$debugMenuUrl = page_url(array('debugTWC' => 1));
$teslaHelperUrl = 'tesla_callback.php';
$sendMessageUrl = page_url(array('sendTWCMsg' => '', 'submit' => 1));
$heartbeatUrl = page_url(array('setMasterHeartbeatData' => '', 'submit' => 1));
$dumpStateUrl = page_url(array('dumpState' => 1, 'submit' => 1));
$statusValid = $status['valid'];
$backendBadgeClass = $statusValid ? 'good' : '';
$backendBadgeText = $statusValid ? 'Backend reachable' : 'Backend unavailable';
$availableAmpsDisplay = available_power_display($status['available_amps']);
$wiringLimitDisplay = amp_display($status['wiring_max_amps'], 0);
$minChargeDisplay = amp_display($status['min_amps_per_twc'], 0);
$chargeNowDisplay = charge_now_display($status['charge_now_amps']);
$detectedTwcCount = count($status['twcs']);
$detectedTwcLabel = $detectedTwcCount . ' detected';
$scheduledPowerDisplay = schedule_power_display($status['scheduled_amps']);
$scheduledTimeDisplay = h(format_time_label($status['scheduled_start'])) . ' to ' . h(format_time_label($status['scheduled_end']));
$scheduledDaysDisplay = h(format_day_bitmap($status['scheduled_days_bitmap']));
$nonScheduledPowerDisplay = non_scheduled_power_display($status['non_scheduled_amps']);
$resumeGreenDisplay = h(format_time_label($status['resume_track_green_energy_time']));
$chargeNowActive = ((float)$status['charge_now_amps'] > 0);
$chargeNowRemainingDisplay = format_duration_compact($status['charge_now_remaining_seconds']);
$chargeNowButtonLabel = '1-day charge, ' . amp_display($status['wiring_max_amps'], 0);
$totalDeliveredDisplay = number_format($status['kwh_delivered'], 2) . ' kWh';
