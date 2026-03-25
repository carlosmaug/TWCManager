<?php
declare(strict_types=1);

/**
 * Tesla OAuth callback helper for Apache/PHP deployments.
 *
 * Features:
 * - Stores OAuth app settings locally on the server.
 * - Accepts Tesla's callback with ?code=...&state=...
 * - Exchanges the authorization code for Tesla tokens.
 * - Generates the exact TeslaApiTokens.json payload expected by TWCManager.
 * - Triggers a browser download of TeslaApiTokens.json automatically.
 *
 * Security note:
 * Place the config file outside the public document root when possible.
 * You can override its path with the TESLA_OAUTH_CONFIG_FILE environment variable.
 */

const TOKEN_URL = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';
const DEFAULT_AUDIENCE = 'https://fleet-api.prd.na.vn.cloud.tesla.com';
const DEFAULT_SCOPE = 'openid offline_access user_data vehicle_device_data vehicle_location vehicle_cmds vehicle_charging_cmds';
const DOWNLOAD_FILE_NAME = 'TeslaApiTokens.json';

function config_file_path(): string
{
    $envPath = getenv('TESLA_OAUTH_CONFIG_FILE');
    if (is_string($envPath) && trim($envPath) !== '') {
        return $envPath;
    }

    return dirname(__DIR__) . '/tesla_oauth_config.json';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return request_scheme() . '://' . $host . $path;
}

function load_config(): array
{
    $path = config_file_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('No se pudo leer el fichero de configuracion: ' . $path);
    }

    $config = json_decode($raw, true);
    if (!is_array($config)) {
        throw new RuntimeException('El fichero de configuracion contiene JSON invalido: ' . $path);
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
    ];
}

function render_page(array $state): void
{
    $config = $state['config'];
    $message = $state['message'] ?? '';
    $error = $state['error'] ?? '';
    $downloadJson = $state['download_json'] ?? '';
    $configPath = config_file_path();
    $defaultRedirect = current_url_base();
    $hasSavedConfig = is_array($config) && ($config['client_id'] ?? '') !== '' &&
        ($config['client_secret'] ?? '') !== '' && ($config['redirect_uri'] ?? '') !== '';
    $showConfigPath = (bool) ($state['show_config_path'] ?? false);

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
    <p class="muted">Esta pagina guarda la configuracion OAuth, recibe la callback de Tesla y genera <code><?php echo h(DOWNLOAD_FILE_NAME); ?></code> listo para usar con TWCManager.</p>
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
              'state' => bin2hex(random_bytes(12)),
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

  <?php if ($downloadJson !== ''): ?>
  <div class="card">
    <h2>Descarga generada</h2>
    <p>Se ha generado <code><?php echo h(DOWNLOAD_FILE_NAME); ?></code>. La descarga deberia iniciarse automaticamente.</p>
    <textarea id="download_json" readonly><?php echo h($downloadJson); ?></textarea>
    <div class="actions">
      <button type="button" onclick="downloadJson()">Descargar otra vez</button>
      <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('download_json').value)">Copiar JSON</button>
    </div>
  </div>
  <script>
  function downloadJson() {
    const text = document.getElementById('download_json').value;
    const blob = new Blob([text], {type: 'application/json'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '<?php echo h(DOWNLOAD_FILE_NAME); ?>';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  }
  downloadJson();
  </script>
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
    'download_json' => '',
    'show_config_path' => false,
];

try {
    $configExistedAtStart = is_file(config_file_path());
    $state['config'] = load_config();
    $state['show_config_path'] = !$configExistedAtStart;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
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

        save_config($config);
        $state['config'] = $config;
        $state['message'] = 'Configuracion guardada correctamente.';
        $state['show_config_path'] = !$configExistedAtStart;
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

        if (current_url_base() !== $state['config']['redirect_uri']) {
            throw new RuntimeException(
                'La URL actual no coincide con redirect_uri.' . "\n" .
                'URL actual: ' . current_url_base() . "\n" .
                'redirect_uri guardada: ' . $state['config']['redirect_uri']
            );
        }

        $tokenPayload = build_token_payload($state['config'], trim((string) $_GET['code']));
        $state['download_json'] = json_encode($tokenPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $state['message'] = 'TeslaApiTokens.json generado correctamente.';
    }
} catch (Throwable $e) {
    $state['error'] = $e->getMessage();
}

render_page($state);
