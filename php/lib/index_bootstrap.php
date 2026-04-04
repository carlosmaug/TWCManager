<?php

require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/functions.php';

start_secure_session();
send_security_headers();
handle_login_submission('TWCManager Control Panel');
enforce_session_timeout('TWCManager Control Panel');
handle_logout_submission();

$ipcKey = ftok($twcScriptDir, "T");
$ipcQueue = msg_get_queue($ipcKey, 0660);

$pageMode = 'normal';
$flashMessage = '';
$flashError = '';
$debugResponse = '';
$debugDecoded = '';
$dumpStateResponse = '';

$requestMethod = request_method();
$submit = ($requestMethod === 'POST') ? request_post_str('submit') : request_get_str('submit');
$debugTWC = request_get_str('debugTWC');
$sendTWCMsgPage = request_get_present('sendTWCMsg');
$heartbeatPage = request_get_present('setMasterHeartbeatData');
$dumpState = request_get_str('dumpState');

$setDebugLevel = request_post_str('setDebugLevel');
$beginTest = request_post_str('beginTest');
$sendTWCMsg = request_post_str('sendTWCMsg');
$setMasterHeartbeatData = request_post_str('setMasterHeartbeatData');
$nonScheduledAmpsMaxRequest = request_post_str('nonScheduledAmpsMax');
$scheduledAmpsMaxRequest = request_post_str('scheduledAmpsMax');
$scheduledAmpStartTimeRequest = request_post_str('scheduledAmpStartTime');
$scheduledAmpsEndTimeRequest = request_post_str('scheduledAmpsEndTime');
$resumeTrackGreenEnergyTimeRequest = request_post_str('resumeTrackGreenEnergyTime');
$scheduledAmpsDayRequest = request_post_array('scheduledAmpsDay');
$csrfToken = ensure_csrf_token();

if(!$webEnableDebugTools && ($debugTWC !== '' || $sendTWCMsgPage || $heartbeatPage || $dumpState !== '')) {
    security_log('debug_access_blocked');
    $flashError = 'Debug tools are disabled in this deployment.';
}

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

if($webEnableDebugTools && $debugTWC !== '') {
    $pageMode = 'debug';
    security_log('debug_page_view');
    if($requestMethod === 'POST' && $submit !== '') {
        validate_post_only_action('Debug action');
        $validatedDebugLevel = validate_int_range($setDebugLevel, 0, 100);
        if($validatedDebugLevel !== null) {
            if(ipc_command('setDebugLevel=' . $validatedDebugLevel)) {
                security_log('debug_level_changed', ['level' => $validatedDebugLevel]);
                $flashMessage = 'Debug level updated.';
            }
            else {
                $flashError = 'Unable to send debug level command.';
            }
        }
        elseif(request_post_present('setDebugLevel')) {
            $flashError = 'Debug level must be an integer between 0 and 100.';
        }
        elseif(request_post_present('beginTest')) {
            $validatedBeginTest = validate_debug_token($beginTest);
            if($validatedBeginTest === null) {
                $flashError = 'Debug test contains invalid characters.';
            }
            else {
                $cmd = ($validatedBeginTest === '') ? 'beginTest' : ('beginTest=' . $validatedBeginTest);
                if(ipc_command($cmd)) {
                    security_log('debug_test_started', ['value' => $validatedBeginTest]);
                    $flashMessage = 'Debug test command sent.';
                }
                else {
                    $flashError = 'Unable to send debug test command.';
                }
            }
        }
    }
}
elseif($webEnableDebugTools && $sendTWCMsgPage) {
    $pageMode = 'send';
    security_log('debug_send_page_view');
    if($requestMethod === 'POST' && $submit !== '' && $sendTWCMsg !== '') {
        validate_post_only_action('Send RS-485 message');
        $validatedSendTWCMsg = validate_hex_payload($sendTWCMsg, false, 15);
        if($validatedSendTWCMsg === null) {
            $flashError = 'RS-485 payload must be valid hex with an even number of characters and at most 15 bytes.';
        }
        elseif(ipc_command('sendTWCMsg=' . $validatedSendTWCMsg)) {
            security_log('debug_rs485_message_sent', ['payload' => $validatedSendTWCMsg]);
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
elseif($webEnableDebugTools && $heartbeatPage) {
    $pageMode = 'heartbeat';
    security_log('debug_heartbeat_page_view');
    if($requestMethod === 'POST' && $submit !== '') {
        validate_post_only_action('Heartbeat override');
        $validatedHeartbeatData = validate_hex_payload($setMasterHeartbeatData, true, 9);
        if($validatedHeartbeatData === null) {
            $flashError = 'Master heartbeat override must be valid hex with an even number of characters and at most 9 bytes.';
        }
        elseif(ipc_command('setMasterHeartbeatData=' . $validatedHeartbeatData)) {
            security_log('debug_heartbeat_override', ['payload' => $validatedHeartbeatData]);
            $flashMessage = ($setMasterHeartbeatData === '')
                ? 'Master heartbeat override cleared.'
                : 'Master heartbeat override updated.';
        }
        else {
            $flashError = 'Unable to send master heartbeat override.';
        }
    }
}
elseif($webEnableDebugTools && $dumpState !== '') {
    $pageMode = 'dump';
    security_log('debug_dumpstate_view');
    $dumpStateResponse = ipc_query('dumpState', true);
    if($dumpStateResponse === '') {
        $flashError = 'No response from TWCManager.';
    }
}
else {
    if($requestMethod === 'POST' && $nonScheduledAmpsMaxRequest !== '') {
        validate_post_only_action('Non-scheduled charging update');
        $validatedNonScheduledAmps = validate_choice($nonScheduledAmpsMaxRequest, $allowedNonScheduledAmpValues);
        if($validatedNonScheduledAmps === null) {
            $flashError = 'Invalid non-scheduled charging limit.';
        }
        elseif(ipc_command('setNonScheduledAmps=' . $validatedNonScheduledAmps)) {
            security_log('non_scheduled_limit_changed', ['value' => $validatedNonScheduledAmps]);
            $flashMessage = 'Non-scheduled charging limit updated.';
        }
        else {
            $flashError = 'Unable to update non-scheduled charging limit.';
        }
    }

    if($requestMethod === 'POST' && $scheduledAmpsMaxRequest !== '') {
        validate_post_only_action('Scheduled charging update');
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
                security_log('scheduled_charging_changed', [
                    'amps' => $validatedScheduledAmps,
                    'start' => ($validatedScheduledStart ?? '00:00'),
                    'end' => ($validatedScheduledEnd ?? '00:00'),
                    'days' => $daysBitmap,
                ]);
                $flashMessage = 'Scheduled charging settings updated.';
            }
            else {
                $flashError = 'Unable to update scheduled charging settings.';
            }
        }
    }

    if($requestMethod === 'POST' && $resumeTrackGreenEnergyTimeRequest !== '') {
        validate_post_only_action('Green-energy resume update');
        $validatedResumeTrack = validate_choice($resumeTrackGreenEnergyTimeRequest, $allowedResumeTrackValues);
        if($validatedResumeTrack === null) {
            $flashError = 'Invalid green-energy resume time.';
        }
        elseif(ipc_command('setResumeTrackGreenEnergyTime=' . $validatedResumeTrack)) {
            security_log('green_energy_resume_changed', ['value' => $validatedResumeTrack]);
            $flashMessage = 'Green-energy resume time updated.';
        }
        else {
            $flashError = 'Unable to update green-energy resume time.';
        }
    }

    if($requestMethod === 'POST' && preg_match('/^1-day charge/', $submit)) {
        validate_post_only_action('1-day charge enable');
        if(ipc_command('chargeNow')) {
            security_log('charge_now_enabled');
            $flashMessage = '24-hour charge override enabled.';
        }
        else {
            $flashError = 'Unable to enable 24-hour charge override.';
        }
    }
    elseif($requestMethod === 'POST' && $submit === 'Cancel 1-day charge') {
        validate_post_only_action('1-day charge cancel');
        if(ipc_command('chargeNowCancel')) {
            security_log('charge_now_cancelled');
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
$debugMenuUrl = $webEnableDebugTools ? page_url(array('debugTWC' => 1)) : '';
$teslaHelperUrl = $webEnableTeslaHelper ? 'tesla_callback.php' : '';
$sendMessageUrl = $webEnableDebugTools ? page_url(array('sendTWCMsg' => '')) : '';
$heartbeatUrl = $webEnableDebugTools ? page_url(array('setMasterHeartbeatData' => '')) : '';
$dumpStateUrl = $webEnableDebugTools ? page_url(array('dumpState' => 1)) : '';
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

$teslaOAuthConfigCandidates = array();
$teslaOAuthConfigEnv = getenv('TESLA_OAUTH_CONFIG_FILE');
if(is_string($teslaOAuthConfigEnv) && trim($teslaOAuthConfigEnv) !== '') {
    $teslaOAuthConfigCandidates[] = trim($teslaOAuthConfigEnv);
}
$teslaOAuthConfigCandidates[] = dirname(__DIR__) . '/tesla_oauth_config.json';
$teslaOAuthConfigCandidates[] = dirname(dirname(__DIR__)) . '/tesla_oauth_config.json';
$teslaOAuthConfigPath = first_readable_file($teslaOAuthConfigCandidates);
$teslaConfigAvailable = ($teslaOAuthConfigPath !== '');

$teslaTokenCandidates = array(
    rtrim($twcScriptDir, '/\\') . '/TeslaApiTokens.json',
    dirname(__DIR__) . '/TeslaApiTokens.json',
    dirname(dirname(__DIR__)) . '/TeslaApiTokens.json',
);
$teslaTokenPath = first_readable_file($teslaTokenCandidates);
$teslaTokensAvailable = ($teslaTokenPath !== '');

if($status['tesla_api_operational']) {
    $teslaConnectionClass = 'good';
    $teslaConnectionText = 'Tesla API operative';
    $teslaConnectionDetail = 'The backend has a valid in-memory Tesla API session and no recent Tesla API errors.';
}
elseif($status['tesla_api_state'] === 'error') {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'The backend reported a recent Tesla API error, such as a failed token refresh or rejected Tesla API request.';
}
elseif($status['need_tesla_tokens'] || $status['tesla_api_state'] === 'tokens_required') {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'The backend reports that Tesla API access is needed, but no usable tokens are loaded.';
}
elseif($status['tesla_api_state'] === 'not_operational') {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'Tesla tokens are loaded, but there is no currently valid access token in memory.';
}
elseif($teslaTokensAvailable) {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'A TeslaApiTokens.json file exists, but the backend has not confirmed an operational Tesla API session.';
}
elseif($teslaConfigAvailable) {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'OAuth is configured, but no readable TeslaApiTokens.json file was found yet.';
}
else {
    $teslaConnectionClass = '';
    $teslaConnectionText = 'Tesla API not operative';
    $teslaConnectionDetail = 'No OAuth helper configuration or Tesla token file was found on this host.';
}
