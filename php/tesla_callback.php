<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/load_config.php';
require_once __DIR__ . '/lib/functions.php';

function is_tesla_oauth_callback_request(): bool
{
    if (request_method() !== 'GET') {
        return false;
    }

    $hasCode = trim((string) ($_GET['code'] ?? '')) !== '';
    $hasError = trim((string) ($_GET['error'] ?? '')) !== '';
    return $hasCode || $hasError;
}

start_secure_session();
send_security_headers();
if (!is_tesla_oauth_callback_request()) {
    $requireHelperAuth = isset($webRequireAuthTeslaHelper)
        ? (bool) $webRequireAuthTeslaHelper
        : (bool) ($webRequireAuth ?? true);
    if (!$requireHelperAuth) {
        $_SESSION['authenticated'] = true;
        $_SESSION['session_started_at'] = time();
        $_SESSION['last_activity_at'] = time();
    }
    handle_login_submission('Tesla OAuth Helper');
    enforce_session_timeout('Tesla OAuth Helper');
}
handle_logout_submission('tesla_callback.php');

if(empty($webEnableTeslaHelper)) {
    security_log('tesla_helper_blocked');
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Tesla OAuth helper is disabled.\n";
    exit;
}

/**
 * Tesla OAuth callback helper for Apache/PHP deployments.
 *
 * Features:
 * - Stores OAuth app settings locally on the server.
 * - Accepts Tesla's callback with ?code=...&state=...
 * - Exchanges the authorization code for Tesla tokens.
 * - Generates the exact TeslaApiTokens.json payload expected by TWCManager.
 * - Stores TeslaApiTokens.json on the server for TWCManager automatically.
 *
 * Security note:
 * Place the config file outside the public document root when possible.
 * You can override its path with the TESLA_OAUTH_CONFIG_FILE environment variable.
 */

const TOKEN_URL = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';
const DEFAULT_AUDIENCE = 'https://fleet-api.prd.eu.vn.cloud.tesla.com';
const DEFAULT_SCOPE = 'openid offline_access user_data vehicle_device_data vehicle_location vehicle_cmds vehicle_charging_cmds';
const DOWNLOAD_FILE_NAME = 'TeslaApiTokens.json';
const REGISTER_RETRY_SECONDS = 600;
const PUBLIC_KEY_RELATIVE_PATH = '/.well-known/appspecific/com.tesla.3p.public-key.pem';

function app_root_path(): string
{
    return dirname(__DIR__);
}

function public_root_path(): string
{
    return app_root_path() . '/public';
}

function default_private_key_path(): string
{
    return app_root_path() . '/tesla_partner_private_key.pem';
}

function default_public_key_path(): string
{
    return public_root_path() . PUBLIC_KEY_RELATIVE_PATH;
}

function default_token_output_path(): string
{
    $envPath = getenv('TESLA_API_TOKEN_FILE');
    if (is_string($envPath) && trim($envPath) !== '') {
        return trim($envPath);
    }

    return app_root_path() . '/' . DOWNLOAD_FILE_NAME;
}

function ensure_parent_directory(string $path): void
{
    $dir = dirname($path);
    if (is_dir($dir)) {
        return;
    }

    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio: ' . $dir);
    }
}

function write_text_file(string $path, string $contents, int $chmod): void
{
    ensure_parent_directory($path);
    $written = file_put_contents($path, $contents, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('No se pudo escribir el fichero: ' . $path);
    }

    @chmod($path, $chmod);
}

function file_has_content(string $path): bool
{
    return is_file($path) && filesize($path) > 0;
}

function infer_partner_domain(array $config): string
{
    $candidate = trim((string) ($config['partner_domain'] ?? ''));
    if ($candidate !== '') {
        return strtolower($candidate);
    }

    $redirectHost = parse_url((string) ($config['redirect_uri'] ?? ''), PHP_URL_HOST);
    if (is_string($redirectHost) && trim($redirectHost) !== '') {
        return strtolower(trim($redirectHost));
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        return strtolower(preg_replace('/:\d+$/', '', $host));
    }

    return '';
}

function default_public_key_url(array $config): string
{
    $domain = infer_partner_domain($config);
    if ($domain === '') {
        return '';
    }

    return 'https://' . $domain . PUBLIC_KEY_RELATIVE_PATH;
}

function ensure_partner_config_defaults(array &$config): bool
{
    $changed = false;

    $partnerDomain = infer_partner_domain($config);
    if (($config['partner_domain'] ?? '') !== $partnerDomain && $partnerDomain !== '') {
        $config['partner_domain'] = $partnerDomain;
        $changed = true;
    }

    $defaults = [
        'private_key_path' => default_private_key_path(),
        'public_key_path' => default_public_key_path(),
        'public_key_url' => default_public_key_url($config),
        'token_output_path' => default_token_output_path(),
    ];

    foreach ($defaults as $key => $value) {
        if (trim((string) ($config[$key] ?? '')) === '' && $value !== '') {
            $config[$key] = $value;
            $changed = true;
        }
    }

    return $changed;
}

function ensure_partner_key_material(array &$config): array
{
    $messages = [];
    $changed = ensure_partner_config_defaults($config);
    $privateKeyPath = (string) ($config['private_key_path'] ?? '');
    $publicKeyPath = (string) ($config['public_key_path'] ?? '');
    $config['key_material_status'] = 'pending';
    $config['key_material_detail'] = '';

    if ($privateKeyPath === '' || $publicKeyPath === '') {
        $config['key_material_status'] = 'error';
        $config['key_material_detail'] = 'No se pudieron resolver las rutas de las claves partner.';
        throw new RuntimeException('No se pudieron resolver las rutas de las claves partner.');
    }

    if (file_has_content($privateKeyPath) && file_has_content($publicKeyPath)) {
        $config['key_material_status'] = 'ready';
        $config['key_material_detail'] = 'Las claves Tesla partner ya existen en el servidor.';
        if ($changed) {
            $messages[] = 'Rutas de claves Tesla partner preparadas en la configuracion.';
        }
        return $messages;
    }

    if (!function_exists('openssl_pkey_new')) {
        $config['key_material_status'] = 'error';
        $config['key_material_detail'] = 'La extension OpenSSL de PHP no esta disponible para generar claves Tesla partner.';
        throw new RuntimeException('La extension OpenSSL de PHP no esta disponible para generar claves Tesla partner.');
    }

    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false) {
        $config['key_material_status'] = 'error';
        $config['key_material_detail'] = 'OpenSSL no pudo generar una clave EC prime256v1.';
        throw new RuntimeException('OpenSSL no pudo generar una clave EC prime256v1.');
    }

    $privateKeyPem = '';
    if (!openssl_pkey_export($key, $privateKeyPem)) {
        $config['key_material_status'] = 'error';
        $config['key_material_detail'] = 'OpenSSL no pudo exportar la clave privada Tesla partner.';
        throw new RuntimeException('OpenSSL no pudo exportar la clave privada Tesla partner.');
    }

    $details = openssl_pkey_get_details($key);
    $publicKeyPem = (string) ($details['key'] ?? '');
    if ($publicKeyPem === '') {
        $config['key_material_status'] = 'error';
        $config['key_material_detail'] = 'OpenSSL no devolvio la clave publica Tesla partner.';
        throw new RuntimeException('OpenSSL no devolvio la clave publica Tesla partner.');
    }

    write_text_file($privateKeyPath, $privateKeyPem, 0600);
    write_text_file($publicKeyPath, $publicKeyPem, 0644);
    $config['key_material_status'] = 'ready';
    $config['key_material_detail'] = 'Se han generado automaticamente las claves Tesla partner.';
    $messages[] = 'Se han generado automaticamente las claves Tesla partner y la clave publica ya esta publicada en ' . $publicKeyPath . '.';

    return $messages;
}

function request_json(string $method, string $url, ?array $payload = null, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL de PHP no esta disponible. Instala o habilita php-curl para que el helper Tesla pueda comunicarse con Tesla.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar cURL para Tesla.');
    }

    $httpHeaders = array_merge([
        'Accept: application/json',
    ], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $httpHeaders,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
    }
    elseif ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Error de red al llamar a Tesla: ' . $curlError);
    }

    $json = json_decode($body, true);
    return [
        'status' => $httpCode,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
    ];
}

function partner_token_payload(array $config): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL de PHP no esta disponible. Instala o habilita php-curl para que el helper Tesla pueda pedir el partner token.');
    }

    $fields = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => (string) ($config['client_id'] ?? ''),
        'client_secret' => (string) ($config['client_secret'] ?? ''),
        'audience' => (string) ($config['audience'] ?? DEFAULT_AUDIENCE),
        'scope' => (string) ($config['scope'] ?? DEFAULT_SCOPE),
    ]);

    $ch = curl_init(TOKEN_URL);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar cURL para el partner token.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Error de red al pedir el partner token de Tesla: ' . $curlError);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Tesla no devolvio JSON valido al pedir el partner token. HTTP ' . $httpCode . ': ' . $body);
    }

    if ($httpCode >= 400 || empty($payload['access_token'])) {
        throw new RuntimeException(
            'Tesla rechazo el partner token. HTTP ' . $httpCode . ': ' .
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return $payload;
}

function fetch_registered_public_key(string $audience, string $partnerToken, string $domain): ?string
{
    $result = request_json(
        'GET',
        rtrim($audience, '/') . '/api/1/partner_accounts/public_key?domain=' . rawurlencode($domain),
        null,
        [
            'Authorization: Bearer ' . $partnerToken,
        ]
    );

    if ($result['status'] >= 400) {
        return null;
    }

    if (is_array($result['json'])) {
        $payload = $result['json'];
        if (isset($payload['response']['public_key']) && is_string($payload['response']['public_key'])) {
            return trim($payload['response']['public_key']);
        }
        if (isset($payload['public_key']) && is_string($payload['public_key'])) {
            return trim($payload['public_key']);
        }
        if (isset($payload['response']) && is_string($payload['response'])) {
            return trim($payload['response']);
        }
    }

    $body = trim((string) $result['body']);
    return $body !== '' ? $body : null;
}

function register_partner_domain(array &$config, bool $force = false): array
{
    $messages = [];
    $changed = ensure_partner_config_defaults($config);
    $domain = infer_partner_domain($config);
    if ($domain === '') {
        throw new RuntimeException('No se pudo determinar el dominio partner. Revisa redirect_uri o partner_domain.');
    }
    if (($config['partner_domain'] ?? '') !== $domain) {
        $config['partner_domain'] = $domain;
        $changed = true;
    }
    $publicKeyUrl = default_public_key_url($config);
    if (($config['public_key_url'] ?? '') !== $publicKeyUrl) {
        $config['public_key_url'] = $publicKeyUrl;
        $changed = true;
    }

    $now = time();
    $status = (string) ($config['partner_registration_status'] ?? '');
    $nextRetryAt = (int) ($config['partner_registration_next_retry_at'] ?? 0);
    if (!$force && $status === 'registered') {
        if ($changed) {
            $messages[] = 'Estado de registro Tesla partner ya confirmado para ' . $domain . '.';
        }
        return $messages;
    }
    if (!$force && $nextRetryAt > $now) {
        return $messages;
    }

    $localPublicKey = @file_get_contents((string) ($config['public_key_path'] ?? ''));
    if (!is_string($localPublicKey) || trim($localPublicKey) === '') {
        throw new RuntimeException('La clave publica Tesla partner no esta disponible en el servidor.');
    }

    $config['partner_registration_last_attempt_at'] = $now;
    $partnerTokenPayload = partner_token_payload($config);
    $partnerToken = (string) $partnerTokenPayload['access_token'];
    $messages[] = 'Tesla partner token obtenido correctamente.';

    $registerResult = request_json(
        'POST',
        rtrim((string) ($config['audience'] ?? DEFAULT_AUDIENCE), '/') . '/api/1/partner_accounts',
        ['domain' => $domain],
        [
            'Authorization: Bearer ' . $partnerToken,
            'Content-Type: application/json',
        ]
    );

    $registeredPublicKey = fetch_registered_public_key(
        (string) ($config['audience'] ?? DEFAULT_AUDIENCE),
        $partnerToken,
        $domain
    );

    $keyMatches = is_string($registeredPublicKey) && trim($registeredPublicKey) === trim($localPublicKey);
    if ($keyMatches) {
        $config['partner_registration_status'] = 'registered';
        $config['partner_registration_detail'] = 'Tesla confirma la clave publica registrada para ' . $domain . '.';
        $config['partner_registration_registered_at'] = $now;
        $config['partner_registration_next_retry_at'] = 0;
        $messages[] = $config['partner_registration_detail'];
        return $messages;
    }

    if ($registerResult['status'] < 400) {
        $config['partner_registration_status'] = 'pending';
        $config['partner_registration_detail'] = 'Tesla acepto la solicitud de registro para ' . $domain .
            ', pero la clave publica aun no se puede verificar desde el endpoint partner_accounts/public_key.';
        $config['partner_registration_next_retry_at'] = $now + REGISTER_RETRY_SECONDS;
        $messages[] = $config['partner_registration_detail'];
        return $messages;
    }

    $detail = is_array($registerResult['json'])
        ? json_encode($registerResult['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : trim((string) $registerResult['body']);
    $config['partner_registration_status'] = 'error';
    $config['partner_registration_detail'] = 'Tesla rechazo el registro partner para ' . $domain .
        '. HTTP ' . $registerResult['status'] . ': ' . $detail;
    $config['partner_registration_next_retry_at'] = $now + REGISTER_RETRY_SECONDS;
    throw new RuntimeException($config['partner_registration_detail']);
}

function save_server_token_file(array &$config, array $tokenPayload): string
{
    $changed = ensure_partner_config_defaults($config);
    $path = trim((string) ($config['token_output_path'] ?? ''));
    if ($path === '') {
        $path = default_token_output_path();
        $config['token_output_path'] = $path;
        $changed = true;
    }

    $json = json_encode($tokenPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('No se pudo serializar el token Tesla para guardarlo en el servidor.');
    }

    write_text_file($path, $json . "\n", 0600);
    if ($changed) {
        save_config($config);
    }
    return $path;
}

function check_public_key_url_status(array &$config): void
{
    $url = trim((string) ($config['public_key_url'] ?? ''));
    if ($url === '') {
        $config['public_key_url_status'] = 'pending';
        $config['public_key_url_detail'] = 'La URL publica de la clave aun no esta definida.';
        return;
    }

    $config['public_key_url_last_checked_at'] = time();

    if (!function_exists('curl_init')) {
        $config['public_key_url_status'] = 'error';
        $config['public_key_url_detail'] = 'La extension cURL de PHP no esta disponible para verificar la URL publica de la clave.';
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        $config['public_key_url_status'] = 'error';
        $config['public_key_url_detail'] = 'No se pudo inicializar cURL para comprobar la URL publica de la clave.';
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/x-pem-file,text/plain,*/*'],
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        $config['public_key_url_status'] = 'error';
        $config['public_key_url_detail'] = 'Error de red al comprobar la URL publica de la clave: ' . $curlError;
        return;
    }

    $trimmedBody = trim((string) $body);
    if ($httpCode === 200 && str_contains($trimmedBody, 'BEGIN PUBLIC KEY')) {
        $config['public_key_url_status'] = 'ok';
        $config['public_key_url_detail'] = 'La URL publica de la clave responde HTTP 200 y contiene una clave publica PEM.';
        return;
    }

    $snippet = substr(preg_replace('/\s+/', ' ', $trimmedBody), 0, 180);
    $config['public_key_url_status'] = 'error';
    $config['public_key_url_detail'] = 'La URL publica de la clave no es valida para Tesla. HTTP ' . $httpCode .
        ', Content-Type=' . ($contentType !== '' ? $contentType : 'desconocido') .
        ($snippet !== '' ? ', cuerpo=' . $snippet : '');
}

function config_file_path(): string
{
    $envPath = getenv('TESLA_OAUTH_CONFIG_FILE');
    if (is_string($envPath) && trim($envPath) !== '') {
        return $envPath;
    }

    return dirname(__DIR__) . '/tesla_oauth_config.json';
}

function request_scheme(): string
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        return trim($parts[0]) === 'https' ? 'https' : 'http';
    }
    return 'http';
}

function current_url_base(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return canonical_base_url() . $path;
}

function oauth_state_token(): string
{
    start_secure_session();
    if(empty($_SESSION['tesla_oauth_state']) || !is_string($_SESSION['tesla_oauth_state'])) {
        $_SESSION['tesla_oauth_state'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['tesla_oauth_state'];
}

function consume_oauth_state_token(): string
{
    start_secure_session();
    $state = (string)($_SESSION['tesla_oauth_state'] ?? '');
    unset($_SESSION['tesla_oauth_state']);
    return $state;
}

function load_config(?string &$resetReason = null): array
{
    $path = config_file_path();
    if (!is_file($path)) {
        save_config([]);
        $resetReason = 'Se creo un nuevo fichero de configuracion porque no existia.';
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        save_config([]);
        $resetReason = 'Se creo un nuevo fichero de configuracion porque el anterior no se podia leer.';
        return [];
    }

    $config = json_decode($raw, true);
    if (!is_array($config)) {
        @rename($path, $path . '.invalid-' . date('YmdHis'));
        save_config([]);
        $resetReason = 'El fichero de configuracion contenia JSON invalido y se creo uno nuevo vacio.';
        return [];
    }

    return $config;
}

function save_config(array $config): void
{
    $path = config_file_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException('El directorio de configuracion no existe: ' . $dir);
    }

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('No se pudo serializar la configuracion.');
    }

    $written = file_put_contents($path, $json . "\n", LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('No se pudo guardar la configuracion en: ' . $path);
    }

    @chmod($path, 0600);
}

function build_token_payload(array $config, string $code): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL de PHP no esta disponible. Instala o habilita php-curl para que el helper Tesla pueda intercambiar el codigo OAuth.');
    }

    $fields = http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
        'audience' => $config['audience'],
        'redirect_uri' => $config['redirect_uri'],
        'scope' => $config['scope'],
    ]);

    $ch = curl_init(TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Error de red al llamar a Tesla: ' . $curlError);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Tesla no devolvio JSON valido. HTTP ' . $httpCode . ': ' . $body);
    }

    if ($httpCode >= 400) {
        throw new RuntimeException(
            'Tesla devolvio HTTP ' . $httpCode . ': ' .
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    if (!isset($payload['access_token'])) {
        throw new RuntimeException(
            'La respuesta de Tesla no contiene access_token: ' .
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return [
        'access_token' => (string) ($payload['access_token'] ?? ''),
        'refresh_token' => (string) ($payload['refresh_token'] ?? ''),
        'expires_at' => time() + (float) ($payload['expires_in'] ?? 0),
        'audience' => (string) ($config['audience'] ?? DEFAULT_AUDIENCE),
        'client_id' => (string) ($config['client_id'] ?? ''),
        'fleet_api_base_url' => rtrim((string) ($config['audience'] ?? DEFAULT_AUDIENCE), '/') . '/api/1',
    ];
}

function render_page(array $state): void
{
    $config = $state['config'];
    $message = $state['message'] ?? '';
    $error = $state['error'] ?? '';
    $configPath = config_file_path();
    $defaultRedirect = current_url_base();
    $hasSavedConfig = is_array($config) && ($config['client_id'] ?? '') !== '' &&
        ($config['client_secret'] ?? '') !== '' && ($config['redirect_uri'] ?? '') !== '';
    $showConfigPath = (bool) ($state['show_config_path'] ?? false);
    $privateKeyPath = trim((string) ($config['private_key_path'] ?? ''));
    $publicKeyPath = trim((string) ($config['public_key_path'] ?? ''));
    $publicKeyUrl = trim((string) ($config['public_key_url'] ?? ''));
    $tokenOutputPath = trim((string) ($config['token_output_path'] ?? ''));
    $partnerDomain = trim((string) ($config['partner_domain'] ?? infer_partner_domain($config)));
    $partnerStatus = trim((string) ($config['partner_registration_status'] ?? 'pending'));
    $partnerDetail = trim((string) ($config['partner_registration_detail'] ?? ''));
    $publicKeyUrlStatus = trim((string) ($config['public_key_url_status'] ?? 'pending'));
    $publicKeyUrlDetail = trim((string) ($config['public_key_url_detail'] ?? ''));
    $publicKeyUrlLastCheckedAt = (int) ($config['public_key_url_last_checked_at'] ?? 0);
    $publicKeyUrlLastCheckedLabel = ($publicKeyUrlLastCheckedAt > 0)
        ? date('Y-m-d H:i:s', $publicKeyUrlLastCheckedAt)
        : '';
    $privateKeyReady = ($privateKeyPath !== '' && file_has_content($privateKeyPath));
    $publicKeyReady = ($publicKeyPath !== '' && file_has_content($publicKeyPath));
    $keyMaterialReady = $privateKeyReady && $publicKeyReady;
    $keyMaterialStatus = trim((string) ($config['key_material_status'] ?? ($keyMaterialReady ? 'ready' : 'pending')));
    $keyMaterialDetail = trim((string) ($config['key_material_detail'] ?? ''));
    $partnerRegistered = ($partnerStatus === 'registered');
    $partnerNextRetryAt = (int) ($config['partner_registration_next_retry_at'] ?? 0);
    $partnerNextRetryLabel = ($partnerNextRetryAt > time())
        ? date('Y-m-d H:i:s', $partnerNextRetryAt)
        : '';
    $partnerLastAttemptAt = (int) ($config['partner_registration_last_attempt_at'] ?? 0);
    $partnerLastAttemptLabel = ($partnerLastAttemptAt > 0)
        ? date('Y-m-d H:i:s', $partnerLastAttemptAt)
        : '';

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Tesla OAuth Helper</title>
<style>
:root {
  color-scheme: light;
  --bg: #f5f1e8;
  --card: #fffdf8;
  --ink: #1f2a2e;
  --muted: #5b676d;
  --accent: #b13f24;
  --line: #d8c9b5;
}
body {
  margin: 0;
  background:
    radial-gradient(circle at top left, #f4d6b8 0, rgba(244, 214, 184, 0.2) 30%, transparent 50%),
    linear-gradient(160deg, #efe6d8 0%, var(--bg) 100%);
  color: var(--ink);
  font-family: Georgia, "Times New Roman", serif;
}
.wrap {
  max-width: 920px;
  margin: 32px auto;
  padding: 0 20px;
}
.card {
  background: var(--card);
  border: 1px solid var(--line);
  box-shadow: 0 12px 30px rgba(54, 39, 20, 0.08);
  padding: 24px;
  margin-bottom: 20px;
}
h1, h2 {
  margin-top: 0;
}
label {
  display: block;
  margin: 12px 0 6px;
  font-weight: 700;
}
input, textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--line);
  background: #fff;
  padding: 10px 12px;
  font: inherit;
}
textarea {
  min-height: 220px;
  font-family: "Courier New", monospace;
  font-size: 14px;
}
.actions {
  margin-top: 16px;
}
button, .button {
  display: inline-block;
  border: 0;
  background: var(--accent);
  color: #fff;
  padding: 11px 16px;
  text-decoration: none;
  font: inherit;
  cursor: pointer;
  margin-right: 8px;
}
.muted {
  color: var(--muted);
}
.error {
  color: #8f220d;
  white-space: pre-wrap;
}
.ok {
  color: #2f5f35;
  white-space: pre-wrap;
}
code {
  background: #f7efe4;
  padding: 2px 5px;
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Tesla OAuth Helper</h1>
    <p class="muted">Esta pagina guarda la configuracion OAuth, prepara automaticamente las claves Tesla partner, intenta registrar el dominio en Tesla y guarda <code><?php echo h(DOWNLOAD_FILE_NAME); ?></code> en el servidor listo para usar con TWCManager.</p>
    <form method="post">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="logout">
      <div class="actions"><button type="submit">Cerrar sesion</button></div>
    </form>
    <?php if ($showConfigPath): ?>
    <p class="muted">Fichero de configuracion: <code><?php echo h($configPath); ?></code></p>
    <?php endif; ?>
    <?php if ($hasSavedConfig): ?>
    <p class="muted">La configuracion OAuth ya esta guardada y permanece oculta. Para volver a mostrar el formulario, borra ese fichero JSON del servidor.</p>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
    <p class="ok"><?php echo h($message); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <p class="error"><?php echo h($error); ?></p>
    <?php endif; ?>
  </div>

  <?php if (!$hasSavedConfig): ?>
  <div class="card">
    <h2>Configuracion OAuth</h2>
    <form method="post">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="save_config">
      <label for="client_id">Client ID</label>
      <input id="client_id" name="client_id" required value="<?php echo h((string) ($config['client_id'] ?? '')); ?>">

      <label for="client_secret">Client Secret</label>
      <input id="client_secret" name="client_secret" required value="<?php echo h((string) ($config['client_secret'] ?? '')); ?>">

      <label for="redirect_uri">Redirect URI</label>
      <input id="redirect_uri" name="redirect_uri" required value="<?php echo h((string) ($config['redirect_uri'] ?? $defaultRedirect)); ?>">

      <label for="audience">Audience</label>
      <input id="audience" name="audience" required value="<?php echo h((string) ($config['audience'] ?? DEFAULT_AUDIENCE)); ?>">

      <label for="scope">Scope</label>
      <input id="scope" name="scope" required value="<?php echo h((string) ($config['scope'] ?? DEFAULT_SCOPE)); ?>">

      <div class="actions">
        <button type="submit">Guardar configuracion</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Inicio del flujo</h2>
    <p class="muted">Registra en Tesla exactamente la misma <code>redirect_uri</code> guardada en el servidor y usa el boton para iniciar el login.</p>
    <?php
      if (($config['client_id'] ?? '') !== '') {
          $authorizeUrl = 'https://auth.tesla.com/oauth2/v3/authorize?' . http_build_query([
              'client_id' => $config['client_id'],
              'locale' => 'en-US',
              'prompt' => 'login',
              'prompt_missing_scopes' => 'true',
              'redirect_uri' => $config['redirect_uri'] ?? $defaultRedirect,
              'response_type' => 'code',
              'scope' => $config['scope'] ?? DEFAULT_SCOPE,
              'state' => oauth_state_token(),
              'nonce' => bin2hex(random_bytes(12)),
          ]);
    ?>
    <div class="actions">
      <a class="button" href="<?php echo h($authorizeUrl); ?>">Abrir login Tesla</a>
    </div>
    <?php } else { ?>
    <p class="muted">Guarda antes la configuracion para que se genere la URL de autorizacion.</p>
    <?php } ?>
  </div>

  <?php if ($hasSavedConfig): ?>
  <div class="card">
    <h2>Estado Tesla Partner</h2>
    <p><strong>Dominio:</strong> <code><?php echo h($partnerDomain !== '' ? $partnerDomain : 'pendiente'); ?></code></p>
    <?php if (!$keyMaterialReady): ?>
    <p><strong>Ficheros Tesla partner:</strong> <?php echo h($keyMaterialStatus === 'error' ? 'error' : 'pendiente'); ?></p>
    <?php if ($keyMaterialDetail !== ''): ?>
    <p class="error"><?php echo h($keyMaterialDetail); ?></p>
    <?php endif; ?>
    <?php if ($privateKeyPath !== '' || $publicKeyPath !== ''): ?>
    <p class="muted">Rutas esperadas:
      <?php if ($privateKeyPath !== ''): ?><code><?php echo h($privateKeyPath); ?></code><?php endif; ?>
      <?php if ($publicKeyPath !== ''): ?> y <code><?php echo h($publicKeyPath); ?></code><?php endif; ?>
    </p>
    <?php endif; ?>
    <?php endif; ?>
    <p><strong>URL publica requerida por Tesla:</strong> <?php if ($publicKeyUrl !== ''): ?><code><?php echo h($publicKeyUrl); ?></code><?php else: ?>pendiente<?php endif; ?></p>
    <p><strong>Comprobacion de la URL publica:</strong> <?php echo h(
        $publicKeyUrlStatus === 'ok' ? 'correcta' : ($publicKeyUrlStatus === 'error' ? 'error' : 'pendiente')
    ); ?></p>
    <?php if ($publicKeyUrlDetail !== ''): ?>
    <p class="<?php echo $publicKeyUrlStatus === 'ok' ? 'ok' : 'error'; ?>"><?php echo h($publicKeyUrlDetail); ?></p>
    <?php endif; ?>
    <?php if ($publicKeyUrlLastCheckedLabel !== ''): ?>
    <p class="muted"><strong>Ultima comprobacion de la URL publica:</strong> <code><?php echo h($publicKeyUrlLastCheckedLabel); ?></code></p>
    <?php endif; ?>
    <p><strong>Registro partner:</strong> <?php echo h($partnerRegistered ? 'completado' : 'pendiente'); ?></p>
    <p><strong>Ultimo intento de registro:</strong> <?php echo h($partnerLastAttemptLabel !== '' ? $partnerLastAttemptLabel : 'aun no realizado'); ?></p>
    <?php if ($partnerDetail !== ''): ?>
    <p class="<?php echo $partnerRegistered ? 'ok' : 'muted'; ?>"><?php echo h($partnerDetail); ?></p>
    <?php endif; ?>
    <?php if (!$partnerRegistered && $partnerNextRetryLabel !== ''): ?>
    <p class="muted">El helper esperara hasta <code><?php echo h($partnerNextRetryLabel); ?></code> antes del siguiente reintento automatico, salvo que fuerces uno manualmente.</p>
    <?php endif; ?>
    <?php if ($tokenOutputPath !== ''): ?>
    <p><strong>Destino automatico de tokens:</strong> <code><?php echo h($tokenOutputPath); ?></code></p>
    <?php endif; ?>
    <form method="post">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="update_partner_domain">
      <label for="partner_domain">Dominio que Tesla debe registrar</label>
      <input id="partner_domain" name="partner_domain" required value="<?php echo h($partnerDomain); ?>">
      <div class="actions">
        <button type="submit">Guardar dominio y reintentar registro</button>
      </div>
    </form>
    <?php if (!$partnerRegistered): ?>
    <p class="muted">Pasos externos que no puede completar esta pagina.</p>
    <ol class="muted">
      <li>Añadir este dominio raiz en <code>allowed_origins</code> de la app en developer.tesla.com.</li>
      <li>Verificar que la URL publica de la clave responde por HTTPS desde fuera de tu red.</li>
      <li>Volver aqui para que el helper reintente el registro automaticamente o con el boton.</li>
    </ol>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
<?php
}

$state = [
    'config' => [],
    'message' => '',
    'error' => '',
    'show_config_path' => false,
];

try {
    security_log('tesla_helper_view');
    $configExistedAtStart = is_file(config_file_path());
    $configResetReason = null;
    $state['config'] = load_config($configResetReason);
    $state['show_config_path'] = !$configExistedAtStart;
    if ($configResetReason !== null) {
        $state['message'] = $configResetReason;
        $state['show_config_path'] = true;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
        verify_csrf_or_throw();
        $config = [
            'client_id' => trim((string) ($_POST['client_id'] ?? '')),
            'client_secret' => trim((string) ($_POST['client_secret'] ?? '')),
            'redirect_uri' => trim((string) ($_POST['redirect_uri'] ?? '')),
            'audience' => trim((string) ($_POST['audience'] ?? DEFAULT_AUDIENCE)),
            'scope' => trim((string) ($_POST['scope'] ?? DEFAULT_SCOPE)),
        ];

        if ($config['client_id'] === '' || $config['client_secret'] === '' || $config['redirect_uri'] === '') {
            throw new RuntimeException('client_id, client_secret y redirect_uri son obligatorios.');
        }

        $partnerDomain = strtolower(trim((string) parse_url($config['redirect_uri'], PHP_URL_HOST)));
        if ($partnerDomain !== '') {
            $config['partner_domain'] = $partnerDomain;
        }

        save_config($config);
        security_log('tesla_helper_config_saved', ['redirect_uri' => $config['redirect_uri']]);
        $state['config'] = $config;
        $state['message'] = 'Configuracion guardada correctamente.';
        $state['show_config_path'] = !$configExistedAtStart;

        $prepMessages = ensure_partner_key_material($state['config']);
        check_public_key_url_status($state['config']);
        $registerMessages = register_partner_domain($state['config'], true);
        save_config($state['config']);
        $combinedMessages = array_merge([$state['message']], $prepMessages, $registerMessages);
        $state['message'] = implode("\n", array_filter($combinedMessages));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_partner_domain') {
        verify_csrf_or_throw();
        if (($state['config']['client_id'] ?? '') === '') {
            throw new RuntimeException('Guarda primero la configuracion OAuth antes de registrar el dominio partner.');
        }

        $state['config']['partner_domain'] = strtolower(trim((string) ($_POST['partner_domain'] ?? '')));
        if ($state['config']['partner_domain'] === '') {
            throw new RuntimeException('partner_domain es obligatorio.');
        }

        $state['config']['public_key_url'] = default_public_key_url($state['config']);
        $prepMessages = ensure_partner_key_material($state['config']);
        check_public_key_url_status($state['config']);
        $registerMessages = register_partner_domain($state['config'], true);
        save_config($state['config']);
        $state['message'] = implode("\n", array_filter(array_merge(
            ['Reintento manual de registro ejecutado. Dominio Tesla partner actualizado.'],
            $prepMessages,
            $registerMessages
        )));
    }

    if (($state['config']['client_id'] ?? '') !== '') {
        $prepMessages = ensure_partner_key_material($state['config']);
        check_public_key_url_status($state['config']);
        $registerMessages = register_partner_domain($state['config'], false);
        if (!empty($prepMessages) || !empty($registerMessages)) {
            save_config($state['config']);
            $state['message'] = trim(implode("\n", array_filter([$state['message'], implode("\n", array_merge($prepMessages, $registerMessages))])));
        }
    }

    if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
        throw new RuntimeException(
            'Tesla devolvio error=' . (string) $_GET['error'] .
            ' description=' . (string) ($_GET['error_description'] ?? '')
        );
    }

    if (isset($_GET['code']) && trim((string) $_GET['code']) !== '') {
        if (($state['config']['redirect_uri'] ?? '') === '') {
            throw new RuntimeException('No hay configuracion guardada. Guarda primero client_id, client_secret y redirect_uri.');
        }

        $expectedState = consume_oauth_state_token();
        $receivedState = trim((string) ($_GET['state'] ?? ''));
        if ($expectedState === '' || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
            throw new RuntimeException('OAuth state validation failed.');
        }

        if (current_url_base() !== $state['config']['redirect_uri']) {
            throw new RuntimeException(
                'La URL actual no coincide con redirect_uri.' . "\n" .
                'URL actual: ' . current_url_base() . "\n" .
                'redirect_uri guardada: ' . $state['config']['redirect_uri']
            );
        }

        $tokenPayload = build_token_payload($state['config'], trim((string) $_GET['code']));
        $tokenOutputPath = save_server_token_file($state['config'], $tokenPayload);
        save_config($state['config']);
        security_log('tesla_helper_token_generated');
        $state['message'] = 'TeslaApiTokens.json generado correctamente y guardado en el servidor en ' . $tokenOutputPath . '.';
    }
} catch (Throwable $e) {
    if (!empty($state['config']) && is_array($state['config'])) {
        try {
            save_config($state['config']);
        } catch (Throwable $ignored) {
        }
    }
    security_log('tesla_helper_error', ['message' => $e->getMessage()]);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_partner_domain') {
        $state['error'] = "El reintento manual si se ejecuto.\n" . $e->getMessage();
    } else {
        $state['error'] = $e->getMessage();
    }
}

render_page($state);
