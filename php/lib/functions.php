<?php

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function security_log($event, $details = array())
{
    global $webSecurityLogEnabled, $webSecurityLogFile;

    if(empty($webSecurityLogEnabled) || !is_string($webSecurityLogFile) || trim($webSecurityLogFile) === '') {
        return;
    }

    $payload = [
        'ts' => gmdate('c'),
        'event' => (string)$event,
        'client' => get_request_client_address(),
        'path' => current_request_path(),
        'authenticated' => is_authenticated(),
    ];

    if(is_array($details)) {
        foreach($details as $key => $value) {
            $payload[(string)$key] = $value;
        }
    }

    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if(is_string($line)) {
        @file_put_contents($webSecurityLogFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

function start_secure_session()
{
    if(session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function send_security_headers()
{
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate("D, d M Y H:i:s") . ' GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self' https://auth.tesla.com; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
}

function request_method()
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ['GET', 'POST'], true) ? $method : 'GET';
}

function request_get_str($key, $default = '')
{
    return isset($_GET[$key]) ? (string)$_GET[$key] : $default;
}

function request_post_str($key, $default = '')
{
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

function request_post_array($key)
{
    $value = $_POST[$key] ?? array();
    return is_array($value) ? $value : array();
}

function request_get_present($key)
{
    return array_key_exists($key, $_GET);
}

function request_post_present($key)
{
    return array_key_exists($key, $_POST);
}

function ensure_csrf_token()
{
    start_secure_session();
    if(empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input()
{
    return '<input type="hidden" name="csrf_token" value="' . h(ensure_csrf_token()) . '">';
}

function verify_csrf_or_throw()
{
    start_secure_session();
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        security_log('csrf_failed');
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function is_authenticated()
{
    start_secure_session();
    return !empty($_SESSION['authenticated']);
}

function destroy_session_state()
{
    start_secure_session();
    $_SESSION = [];
    if(ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function enforce_session_timeout($title = 'TWCManager Login')
{
    global $webSessionIdleTimeoutSeconds, $webSessionAbsoluteTimeoutSeconds;

    start_secure_session();
    if(!is_authenticated()) {
        return;
    }

    $now = time();
    $startedAt = (int)($_SESSION['session_started_at'] ?? 0);
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    if($startedAt <= 0) {
        $startedAt = $now;
    }
    if($lastActivity <= 0) {
        $lastActivity = $now;
    }

    $idleTimeout = max(0, (int)$webSessionIdleTimeoutSeconds);
    $absoluteTimeout = max(0, (int)$webSessionAbsoluteTimeoutSeconds);
    $reason = '';

    if($idleTimeout > 0 && ($now - $lastActivity) > $idleTimeout) {
        $reason = 'idle_timeout';
    }
    elseif($absoluteTimeout > 0 && ($now - $startedAt) > $absoluteTimeout) {
        $reason = 'absolute_timeout';
    }

    if($reason !== '') {
        security_log('session_expired', ['reason' => $reason]);
        destroy_session_state();
        render_login_page($title, 'Session expired. Please sign in again.');
        exit;
    }

    $_SESSION['session_started_at'] = $startedAt;
    $_SESSION['last_activity_at'] = $now;
}

function login_attempt_allowed()
{
    start_secure_session();
    $attempts = $_SESSION['login_attempts'] ?? [];
    $now = time();
    $recent = [];
    foreach($attempts as $ts) {
        if(is_int($ts) && ($now - $ts) < 900) {
            $recent[] = $ts;
        }
    }
    $_SESSION['login_attempts'] = $recent;
    return count($recent) < 10;
}

function record_login_attempt()
{
    start_secure_session();
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts[] = time();
    $_SESSION['login_attempts'] = $attempts;
}

function handle_login_submission($title = 'TWCManager Login')
{
    global $webRequireAuth, $webUsername, $webPasswordHash;

    start_secure_session();
    if(!$webRequireAuth) {
        $_SESSION['authenticated'] = true;
        $_SESSION['session_started_at'] = time();
        $_SESSION['last_activity_at'] = time();
        return;
    }

    if(trim((string)$webUsername) === '' || trim((string)$webPasswordHash) === '') {
        render_login_page($title, 'Web authentication is enabled but username/password hash are not configured.');
        exit;
    }

    if(is_authenticated()) {
        return;
    }

    $error = '';
    if(request_method() === 'POST' && request_post_str('action') === 'login') {
        verify_csrf_or_throw();
        if(!login_attempt_allowed()) {
            security_log('login_rate_limited');
            $error = 'Too many failed login attempts. Please wait and try again.';
        }
        else {
            $username = trim(request_post_str('username'));
            $password = request_post_str('password');
            $usernameValid = ($webUsername !== '' && hash_equals($webUsername, $username));
            $passwordValid = ($webPasswordHash !== '' && password_verify($password, $webPasswordHash));
            if($usernameValid && $passwordValid) {
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['login_attempts'] = [];
                $_SESSION['session_started_at'] = time();
                $_SESSION['last_activity_at'] = time();
                security_log('login_success', ['username' => $username]);
                header('Location: ' . current_request_uri());
                exit;
            }
            record_login_attempt();
            security_log('login_failed', ['username' => $username]);
            $error = 'Invalid username or password.';
        }
    }

    render_login_page($title, $error);
    exit;
}

function current_request_path()
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if($path === '') {
        $path = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
    return $path;
}

function current_request_uri()
{
    $uri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
    if($uri !== '') {
        return $uri;
    }

    return current_request_path();
}

function handle_logout_submission($redirectPath = '')
{
    if(request_method() !== 'POST' || request_post_str('action') !== 'logout') {
        return;
    }

    verify_csrf_or_throw();
    security_log('logout');
    destroy_session_state();
    if(!is_string($redirectPath) || $redirectPath === '') {
        $redirectPath = current_request_path();
    }
    header('Location: ' . $redirectPath);
    exit;
}

function render_login_page($title, $error = '')
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($title); ?></title>
<style>
body{margin:0;font-family:Georgia,serif;background:#efe8dc;color:#18313c}
.wrap{max-width:420px;margin:80px auto;padding:0 18px}
.card{background:#fffdf9;border:1px solid #d8c9b5;padding:24px;box-shadow:0 12px 30px rgba(54,39,20,.08)}
label{display:block;margin:12px 0 6px;font-weight:700}
input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d8c9b5}
button{margin-top:16px;border:0;background:#b13f24;color:#fff;padding:11px 16px;cursor:pointer}
.error{color:#8f220d}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1><?php echo h($title); ?></h1>
    <?php if($error !== ''): ?><p class="error"><?php echo h($error); ?></p><?php endif; ?>
    <form method="post">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="login">
      <label for="username">Username</label>
      <input id="username" name="username" autocomplete="username" required>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
<?php
}

function validate_post_only_action($actionName)
{
    if(request_method() !== 'POST') {
        security_log('invalid_method', ['action' => $actionName, 'method' => request_method()]);
        throw new RuntimeException($actionName . ' must be sent via POST.');
    }
    verify_csrf_or_throw();
}

function first_readable_file($paths)
{
    foreach($paths as $path) {
        if(!is_string($path) || $path === '') {
            continue;
        }
        if(is_file($path) && is_readable($path)) {
            return $path;
        }
    }

    return '';
}

function page_url($params = array())
{
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function clamp_int($value, $min, $max)
{
    $value = (int)$value;
    if($value < $min) {
        return $min;
    }
    if($value > $max) {
        return $max;
    }
    return $value;
}

function validate_int_range($value, $min, $max)
{
    if(!preg_match('/^-?\d+$/', (string)$value)) {
        return null;
    }

    $intValue = (int)$value;
    if($intValue < $min || $intValue > $max) {
        return null;
    }

    return $intValue;
}

function validate_time_value($value, $allowNever = false)
{
    $value = trim((string)$value);

    if($allowNever && $value === '-1:00') {
        return $value;
    }

    if(!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
        return null;
    }

    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if($hour < 0 || $hour > 23 || $minute !== 0) {
        return null;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function validate_choice($value, $allowedValues)
{
    $value = (string)$value;
    foreach($allowedValues as $allowedValue) {
        if($value === (string)$allowedValue) {
            return $value;
        }
    }

    return null;
}

function checked_days_bitmap($selectedDays)
{
    $daysBitmap = 0;
    for($i = 0; $i < 7; $i++) {
        if(!empty($selectedDays[$i])) {
            $daysBitmap |= (1 << $i);
        }
    }

    return $daysBitmap;
}

function validate_hex_payload($value, $allowEmpty = false, $maxBytes = null)
{
    $value = strtoupper(preg_replace('/\s+/', '', (string)$value));

    if($value === '') {
        return $allowEmpty ? '' : null;
    }

    if(!preg_match('/^[0-9A-F]+$/', $value)) {
        return null;
    }

    if((strlen($value) % 2) !== 0) {
        return null;
    }

    if($maxBytes !== null && (strlen($value) / 2) > $maxBytes) {
        return null;
    }

    return $value;
}

function validate_debug_token($value, $maxLength = 64)
{
    $value = trim((string)$value);
    if($value === '') {
        return '';
    }

    if(strlen($value) > $maxLength) {
        return null;
    }

    if(!preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
        return null;
    }

    return $value;
}

function get_request_client_address()
{
    global $webTrustProxyHeaders;

    $useProxyHeaders = !empty($webTrustProxyHeaders);
    foreach([
        'REMOTE_ADDR',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
    ] as $key) {
        if(($key === 'HTTP_X_REAL_IP' || $key === 'HTTP_X_FORWARDED_FOR') && !$useProxyHeaders) {
            continue;
        }
        if(empty($_SERVER[$key])) {
            continue;
        }

        $value = trim((string)$_SERVER[$key]);
        if($value === '') {
            continue;
        }

        if($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim((string)$parts[0]);
            if($value === '') {
                continue;
            }
        }

        return $value;
    }

    return 'unknown';
}

function build_ipc_message($command)
{
    $metadata = [
        'client' => get_request_client_address(),
    ];

    return '__meta__=' . json_encode($metadata) . "\n" . $command;
}

function canonical_base_url()
{
    global $webBaseUrl;

    $configured = trim((string)$webBaseUrl);
    if($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function set_ipc_error($message)
{
    $GLOBALS['ipcLastError'] = trim((string)$message);
}

function get_ipc_error()
{
    return trim((string)($GLOBALS['ipcLastError'] ?? ''));
}

function ipc_send($ipcMsgTime, $ipcMsgID, $ipcMsg, $ipcMsgType = 2)
{
    global $ipcQueue, $debugLevel;

    if(!is_resource($ipcQueue) && !($ipcQueue instanceof \SysvMessageQueue)) {
        if(get_ipc_error() === '') {
            set_ipc_error('SysV IPC queue is not initialized.');
        }
        return false;
    }

    if($debugLevel >= 10) {
        print "ipcQuery sending '" . h($ipcMsg) . "', id " . $ipcMsgID
            . ", time " . $ipcMsgTime . "<p>";
    }

    $ipcErrorCode = 0;
    if(msg_send($ipcQueue, $ipcMsgType, pack("LSa*", $ipcMsgTime, $ipcMsgID, $ipcMsg),
                false, false, $ipcErrorCode) == false
    ) {
        set_ipc_error('msg_send failed with error code ' . $ipcErrorCode . '.');
        return false;
    }
    set_ipc_error('');
    return true;
}

function ipc_command($command)
{
    return ipc_send(time(), 0, build_ipc_message($command), 2);
}

function ipc_query($command, $usePackets = false)
{
    global $ipcQueue, $debugLevel;

    $ipcMsgID = rand(1, 65535);
    $ipcMsgTime = time();
    if(ipc_send($ipcMsgTime, $ipcMsgID, build_ipc_message($command), 2) == false) {
        return '';
    }

    $ipcMsgType = 0;
    $ipcMsgRecv = '';
    $ipcMaxMsgSize = 300;
    $maxRetries = 50;
    $numPackets = 0;
    $msgResult = '';
    $receiveErrorCode = 0;

    for($i = 0; $i < $maxRetries; $i++) {
        $ipcErrorCode = 0;
        if(msg_receive($ipcQueue, 1, $ipcMsgType, $ipcMaxMsgSize, $ipcMsgRecv, false,
                       MSG_IPC_NOWAIT | MSG_NOERROR, $ipcErrorCode) == false
        ) {
            if($ipcErrorCode != 42 && $debugLevel >= 1) {
                print("Message receive failed with error code $ipcErrorCode<br>");
            }
            if($ipcErrorCode != 42) {
                $receiveErrorCode = $ipcErrorCode;
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
                            set_ipc_error('');
                            return $msgResult;
                        }
                    }
                    continue;
                }

                set_ipc_error('');
                return $aryMsg['msg'];
            }

            if(time() - $aryMsg['time'] < 30) {
                ipc_send($aryMsg['time'], $aryMsg['ID'], $aryMsg['msg'], 1);
            }
        }

        usleep(100000);
    }

    if($receiveErrorCode > 0) {
        set_ipc_error('Timed out waiting for IPC response after msg_receive error code ' . $receiveErrorCode . '.');
    }
    elseif(get_ipc_error() === '') {
        set_ipc_error('Timed out waiting for IPC response.');
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
        'tesla_api_operational' => false,
        'tesla_api_state' => 'unknown',
        'charge_now_remaining_seconds' => 0,
        'kwh_delivered' => 0,
        'energy_today_kwh' => 0,
        'energy_week_kwh' => 0,
        'energy_month_kwh' => 0,
        'energy_year_kwh' => 0,
        'twcs' => array(),
        'num_twcs' => 0,
    );

    if($response === '') {
        return $defaults;
    }

    $parts = explode('`', $response);
    if(count($parts) < 20) {
        return $defaults;
    }

    $idx = 0;
    $status = $defaults;
    $status['available_amps'] = max(0, (float)$parts[$idx++]);
    $status['wiring_max_amps'] = max(0, (float)$parts[$idx++]);
    $status['min_amps_per_twc'] = max(0, (float)$parts[$idx++]);
    $status['charge_now_amps'] = max(0, (float)$parts[$idx++]);
    $status['non_scheduled_amps'] = preg_match('/^-?\d+$/', (string)$parts[$idx]) ? (string)$parts[$idx] : '-1';
    $idx++;
    $status['scheduled_amps'] = preg_match('/^-?\d+$/', (string)$parts[$idx]) ? (string)$parts[$idx] : '-1';
    $idx++;
    $status['scheduled_start'] = validate_time_value((string)$parts[$idx++]) ?? '00:00';
    $status['scheduled_end'] = validate_time_value((string)$parts[$idx++]) ?? '00:00';
    $status['scheduled_days_bitmap'] = clamp_int((int)$parts[$idx++], 0, 127);
    $status['resume_track_green_energy_time'] = validate_time_value((string)$parts[$idx++], true) ?? '-1:00';
    $status['need_tesla_tokens'] = ($parts[$idx++] === '1');
    $status['tesla_api_operational'] = ($parts[$idx++] === '1');
    $status['tesla_api_state'] = preg_replace('/[^a-z_]/', '', strtolower((string)$parts[$idx++])) ?: 'unknown';
    $status['charge_now_remaining_seconds'] = max(0, (int)$parts[$idx++]);
    $status['kwh_delivered'] = max(0, (float)$parts[$idx++]);
    $status['energy_today_kwh'] = max(0, (float)$parts[$idx++]);
    $status['energy_week_kwh'] = max(0, (float)$parts[$idx++]);
    $status['energy_month_kwh'] = max(0, (float)$parts[$idx++]);
    $status['energy_year_kwh'] = max(0, (float)$parts[$idx++]);
    $status['num_twcs'] = isset($parts[$idx]) ? clamp_int((int)$parts[$idx], 0, 16) : 0;
    $idx++;

    for($i = 0; $i < $status['num_twcs']; $i++) {
        if(!isset($parts[$idx])) {
            break;
        }
        $sub = explode('~', $parts[$idx++]);
        $twcId = preg_replace('/[^0-9A-Fa-f]/', '', (string)($sub[0] ?? '????'));
        $twcState = preg_replace('/[^0-9A-Fa-fx]/', '', (string)($sub[4] ?? ''));
        $status['twcs'][] = array(
            'id' => ($twcId !== '' ? strtoupper($twcId) : '????'),
            'max_amps' => max(0, (float)($sub[1] ?? 0)),
            'actual_amps' => max(0, (float)($sub[2] ?? 0)),
            'offered_amps' => max(0, (float)($sub[3] ?? 0)),
            'state' => ($twcState !== '' ? $twcState : 'unknown'),
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

function parse_energy_history_response($response)
{
    $defaults = array(
        'today' => array('labels' => array(), 'solar' => array(), 'grid' => array()),
        'week' => array('labels' => array(), 'solar' => array(), 'grid' => array()),
        'month' => array('labels' => array(), 'solar' => array(), 'grid' => array()),
        'year' => array('labels' => array(), 'solar' => array(), 'grid' => array()),
    );

    if($response === '') {
        return $defaults;
    }

    $decoded = json_decode($response, true);
    if(!is_array($decoded)) {
        return $defaults;
    }

    foreach(array('today', 'week', 'month', 'year') as $key) {
        if(!isset($decoded[$key]) || !is_array($decoded[$key])) {
            continue;
        }

        $labels = $decoded[$key]['labels'] ?? array();
        $solar = $decoded[$key]['solar'] ?? array();
        $grid = $decoded[$key]['grid'] ?? array();
        if(!is_array($labels) || !is_array($solar) || !is_array($grid)) {
            continue;
        }

        $count = min(count($labels), count($solar), count($grid));
        $chart = array('labels' => array(), 'solar' => array(), 'grid' => array());
        for($i = 0; $i < $count; $i++) {
            $chart['labels'][] = (string)$labels[$i];
            $chart['solar'][] = max(0, (float)$solar[$i]);
            $chart['grid'][] = max(0, (float)$grid[$i]);
        }
        $defaults[$key] = $chart;
    }

    return $defaults;
}

function amp_display($amps, $decimals = 2)
{
    return number_format((float)$amps, $decimals) . 'A';
}

function charge_now_display($chargeNowAmps)
{
    if((float)$chargeNowAmps <= 0) {
        return 'Off';
    }

    return amp_display($chargeNowAmps);
}

function available_power_display($availableAmps)
{
    if((float)$availableAmps <= 0) {
        return 'None';
    }

    return amp_display($availableAmps);
}

function schedule_power_display($scheduledAmps)
{
    if((string)$scheduledAmps === '-1') {
        return 'Disabled';
    }

    return (string)$scheduledAmps . 'A';
}

function non_scheduled_power_display($nonScheduledAmps)
{
    if((string)$nonScheduledAmps === '-1') {
        return 'Track green energy';
    }

    return (string)$nonScheduledAmps . 'A';
}

function day_labels()
{
    return array('Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su');
}

function format_duration_compact($seconds)
{
    $seconds = max(0, (int)$seconds);
    if($seconds <= 0) {
        return 'less than 1 minute';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if($hours > 0) {
        return sprintf('%dh %02dm', $hours, $minutes);
    }

    return sprintf('%dm', $minutes);
}

function render_metric($label, $value)
{
    ?>
    <div class="metric">
        <span class="label"><?=h($label)?></span>
        <span class="value"><?=h($value)?></span>
    </div>
    <?php
}

function render_twc_card($twc, $availableAmps, $minAmpsPerTWC)
{
    ?>
    <article class="twc-card">
        <header>
            <h3>TWC <?=h($twc['id'])?></h3>
            <span class="twc-state">State <?=h($twc['state'])?></span>
        </header>
        <div class="twc-stats">
            <div class="stat">
                <span class="label">Actual</span>
                <span class="value"><?=h(amp_display($twc['actual_amps']))?></span>
            </div>
            <div class="stat">
                <span class="label">Offered</span>
                <span class="value"><?=h(amp_display($twc['offered_amps']))?></span>
            </div>
            <div class="stat">
                <span class="label">Model Max</span>
                <span class="value"><?=h(amp_display($twc['max_amps'], 0))?></span>
            </div>
            <div class="stat">
                <span class="label">Headroom</span>
                <span class="value"><?=h(amp_display(max(0, $twc['offered_amps'] - $twc['actual_amps'])))?></span>
            </div>
        </div>
        <div class="muted"><?=h(describe_twc_state($twc, $availableAmps, $minAmpsPerTWC))?></div>
    </article>
    <?php
}

function render_policy_card($eyebrow, $main, $details)
{
    ?>
    <div class="policy-card">
        <span class="eyebrow"><?=h($eyebrow)?></span>
        <div class="main"><?=h($main)?></div>
        <div class="muted"><?=$details?></div>
    </div>
    <?php
}

function render_day_checkboxes($scheduledAmpDays)
{
    $dayLabels = day_labels();

    for($i = 0; $i < 7; $i++) {
        ?>
        <label><?php render_checkbox("scheduledAmpsDay[$i]", $scheduledAmpDays[$i]); ?> <?=h($dayLabels[$i])?></label>
        <?php
    }
}

function render_energy_chart($periods)
{
    $maxValue = 0.0;
    foreach($periods as $period) {
        $maxValue = max($maxValue, (float)$period['value']);
    }
    if($maxValue <= 0) {
        $maxValue = 1.0;
    }

    ?>
    <div class="energy-chart">
        <?php foreach($periods as $period): ?>
        <?php $height = max(12, (int)round(((float)$period['value'] / $maxValue) * 100)); ?>
        <div class="energy-bar-card">
            <div class="energy-bar-wrap">
                <div class="energy-bar" style="height: <?=$height?>%;">
                    <span class="energy-bar-value"><?=h(number_format((float)$period['value'], 2))?> kWh</span>
                </div>
            </div>
            <div class="energy-bar-label"><?=h($period['label'])?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function build_svg_line_path($points)
{
    if(count($points) === 0) {
        return '';
    }

    if(count($points) === 1) {
        return sprintf('M %.2f %.2f', $points[0][0], $points[0][1]);
    }

    $path = sprintf('M %.2f %.2f', $points[0][0], $points[0][1]);
    for($i = 0; $i < count($points) - 1; $i++) {
        $current = $points[$i];
        $next = $points[$i + 1];
        $midX = ($current[0] + $next[0]) / 2;
        $midY = ($current[1] + $next[1]) / 2;
        $path .= sprintf(' Q %.2f %.2f %.2f %.2f', $current[0], $current[1], $midX, $midY);
    }
    $last = $points[count($points) - 1];
    $path .= sprintf(' T %.2f %.2f', $last[0], $last[1]);

    return $path;
}

function render_energy_line_chart($title, $subtitle, $chart)
{
    $labels = $chart['labels'] ?? array();
    $solar = $chart['solar'] ?? array();
    $grid = $chart['grid'] ?? array();
    $count = min(count($labels), count($solar), count($grid));

    if($count === 0) {
        ?>
        <div class="chart-card">
            <h3><?=h($title)?></h3>
            <p class="muted"><?=h($subtitle)?></p>
            <p class="muted">No energy history available yet.</p>
        </div>
        <?php
        return;
    }

    $width = 520;
    $height = 190;
    $paddingTop = 18;
    $paddingRight = 12;
    $paddingBottom = 34;
    $paddingLeft = 12;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;
    $maxValue = 0.0;
    $totalValue = 0.0;
    for($i = 0; $i < $count; $i++) {
        $maxValue = max($maxValue, (float)$solar[$i], (float)$grid[$i]);
        $totalValue += max(0, (float)$solar[$i]) + max(0, (float)$grid[$i]);
    }

    ?>
    <div class="chart-card">
        <div class="chart-head">
            <div>
                <h3><?=h($title)?></h3>
                <p class="muted"><?=h($subtitle)?></p>
            </div>
            <div class="chart-legend">
                <span class="legend-item"><span class="legend-swatch solar"></span>Solar</span>
                <span class="legend-item"><span class="legend-swatch grid"></span>Grid</span>
            </div>
        </div>
        <?php if($totalValue <= 0): ?>
        <div class="chart-empty">No hourly energy recorded yet for this period.</div>
        <?php else: ?>
        <?php
        $ticks = array(0, $maxValue / 2, $maxValue);
        $groupWidth = $plotWidth / max($count, 1);
        $innerGroupWidth = min(44, max(12, $groupWidth * 0.9));
        $barGap = min(6, max(2, $innerGroupWidth * 0.08));
        $barWidth = max(8, ($innerGroupWidth - $barGap) / 2);
        $labelStep = max(1, (int)ceil($count / 8));
        ?>
        <svg class="line-chart" viewBox="0 0 <?=$width?> <?=$height?>" preserveAspectRatio="none" aria-hidden="true">
            <?php foreach($ticks as $tick): ?>
            <?php $y = $paddingTop + $plotHeight - (($tick / $maxValue) * $plotHeight); ?>
            <line x1="<?=$paddingLeft?>" y1="<?=round($y, 2)?>" x2="<?=$width - $paddingRight?>" y2="<?=round($y, 2)?>" class="chart-grid-line"></line>
            <text x="<?=$paddingLeft?>" y="<?=round($y - 4, 2)?>" class="chart-axis-label"><?=h(number_format($tick, 1))?></text>
            <?php endforeach; ?>
            <line x1="<?=$paddingLeft?>" y1="<?=$paddingTop + $plotHeight?>" x2="<?=$width - $paddingRight?>" y2="<?=$paddingTop + $plotHeight?>" class="chart-axis-base"></line>
            <?php foreach($labels as $idx => $label): ?>
            <?php
            $groupX = $paddingLeft + ($groupWidth * $idx);
            $barStartX = $groupX + (($groupWidth - (($barWidth * 2) + $barGap)) / 2);
            $solarHeight = (((float)$solar[$idx] / $maxValue) * $plotHeight);
            $gridHeight = (((float)$grid[$idx] / $maxValue) * $plotHeight);
            if((float)$solar[$idx] > 0 && $solarHeight < 4) {
                $solarHeight = 4;
            }
            if((float)$grid[$idx] > 0 && $gridHeight < 4) {
                $gridHeight = 4;
            }
            $solarY = $paddingTop + $plotHeight - $solarHeight;
            $gridY = $paddingTop + $plotHeight - $gridHeight;
            $labelX = $groupX + ($groupWidth / 2);
            ?>
            <rect
                x="<?=round($barStartX, 2)?>"
                y="<?=round($solarY, 2)?>"
                width="<?=round($barWidth, 2)?>"
                height="<?=round($solarHeight, 2)?>"
                rx="3"
                class="chart-bar solar"
            ></rect>
            <rect
                x="<?=round($barStartX + $barWidth + $barGap, 2)?>"
                y="<?=round($gridY, 2)?>"
                width="<?=round($barWidth, 2)?>"
                height="<?=round($gridHeight, 2)?>"
                rx="3"
                class="chart-bar grid"
            ></rect>
            <?php if($idx % $labelStep === 0 || $idx === $count - 1): ?>
            <text x="<?=round($labelX, 2)?>" y="<?=$height - 8?>" text-anchor="middle" class="chart-axis-label"><?=h($label)?></text>
            <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <?php endif; ?>
    </div>
    <?php
}
