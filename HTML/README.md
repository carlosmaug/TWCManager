# HTML Web Components

This directory contains the PHP-based web components that accompany `TWCManager.py`.

## Files

- `index.php`
  Thin view/controller entry point for the main web UI. It renders the page for
  a running TWCManager instance using the data and helpers prepared under
  `HTML/lib/`.

- `lib/config.php`
  Local configuration values for the PHP UI, especially `$twcScriptDir`, which
  must point to the directory containing `TWCManager.py`.

- `lib/functions.php`
  Shared helper functions for request parsing, IPC, formatting, and repeated UI
  rendering.

- `lib/index_bootstrap.php`
  The bootstrap/controller layer for `index.php`. It loads configuration,
  connects to the SysV IPC queue, handles incoming actions, requests current
  status from the Python process, and prepares variables used by the view.

- `tesla_callback.php`
  Tesla OAuth helper. It does not control charging directly and it does not use
  the IPC queue. Its purpose is to run the Tesla OAuth authorization-code flow
  in a browser and generate a `TeslaApiTokens.json` payload that matches what
  `TWCManager.py` expects.

- `favicon.png`
  Browser icon used by the web UI.

- `refresh.png`
  Refresh button image used by `index.php`.

## What `index.php` Does

`index.php` is the operational control page, but it is now intentionally thin.
Most non-trivial PHP logic lives in `lib/`.

It:

- includes `lib/index_bootstrap.php`
- renders the prepared status data and forms
- shows discovered slave TWCs and current charge status
- allows changing:
  - `nonScheduledAmpsMax`
  - scheduled amps, start time, end time, and active days
  - `hourResumeTrackGreenEnergy`
  - the 24-hour `chargeNow` override
- exposes debug routes for low-level RS-485 experimentation

The supporting PHP is split like this:

- configuration lives in `lib/config.php`
- reusable helpers live in `lib/functions.php`
- request handling and status preparation live in `lib/index_bootstrap.php`
- HTML/CSS markup stays in `index.php`

Important:

- `index.php` requires the Python process to be running
- `$twcScriptDir` inside `lib/config.php` must point to the directory containing
  `TWCManager.py`
- the web server user must be able to access the same SysV IPC queue as the
  Python process

## What `tesla_callback.php` Does

`tesla_callback.php` is a browser-facing OAuth bootstrap tool.

It:

- stores Tesla OAuth client settings in a local JSON file
- builds a Tesla authorization URL
- receives Tesla's redirect with `?code=...`
- exchanges that code for Tesla tokens using Tesla's token endpoint
- generates a `TeslaApiTokens.json` download

By default it stores helper configuration in:

- `../tesla_oauth_config.json`

You can override that with:

- `TESLA_OAUTH_CONFIG_FILE`

This helper is useful when you want a simple browser flow to generate the token
JSON that `TWCManager.py` loads from disk.

## Renewing Tesla Tokens Step By Step

Use this procedure when:

- the main UI shows `Tesla API not operative`
- the Python log says `Failed to refresh Tesla API token`
- the Python log says `Import a new token set generated with MFA`

What you need to prepare in Tesla before opening the helper:

1. Open the Tesla developer portal:

```text
https://developer.tesla.com/
```

2. Sign in and open the app dashboard / application area:

```text
https://developer.tesla.com/dashboard
```

3. If you do not yet have an approved app, start from Tesla's Fleet API onboarding guide:

```text
https://developer.tesla.com/docs/fleet-api/getting-started/what-is-fleet-api
```

4. Create or open a Tesla developer application for OAuth / Fleet API access.
5. Register the exact callback URL that will be served by your TWCManager host.
   Example:

```text
https://twcmanager.local/tesla_callback.php
```

6. Make sure the callback URL registered in Tesla exactly matches the real browser URL.
   `http` vs `https`, hostname, port, path, and trailing slash must match exactly.
7. Copy these values from the Tesla developer app:
   - `client_id`
   - `client_secret`
8. Use the audience expected by this project unless you intentionally need a different Tesla region:

```text
https://fleet-api.prd.eu.vn.cloud.tesla.com
```

9. Use scopes that allow token refresh plus vehicle data and charging commands.
   The helper defaults to:

```text
openid offline_access user_data vehicle_device_data vehicle_location vehicle_cmds vehicle_charging_cmds
```

Recommended flow:

1. Open:

```text
http://twcmanager.local/tesla_callback.php
```

2. Save the Tesla OAuth app settings from your Tesla developer app:
   - `client_id`
   - `client_secret`
   - `redirect_uri`
   - `audience`
   - `scope`

3. Verify that the `redirect_uri` stored in the form exactly matches the redirect URI registered in Tesla.
   Differences in scheme, hostname, port, path, or trailing slash will cause the callback exchange to fail.

4. Click `Abrir login Tesla`.

5. Log in to Tesla and complete MFA if Tesla requests it.

6. Wait for Tesla to redirect the browser back to `tesla_callback.php`.

7. Confirm that the page displays:

```text
TeslaApiTokens.json generado correctamente.
```

8. Download the generated `TeslaApiTokens.json`.

9. Copy that file to the directory that contains `TWCManager.py`.
   Typical path:

```text
/srv/TWCManager/TeslaApiTokens.json
```

10. Make sure the user running `TWCManager.py` can read the file.

11. Restart `TWCManager.py`.

12. Open the main web UI again and confirm the Tesla status badge is green.

What success looks like:

- the Python process logs `Tesla API tokens imported`
- charging commands no longer fail on token refresh
- the Tesla status badge changes from red to green

Common failure cases:

- the token JSON was generated on your browser but never placed on the server
- the file was copied to the wrong TWCManager installation directory
- the callback URL does not match the Tesla app configuration exactly
- the Python service cannot read the file because of permissions
- the OAuth app configuration is incomplete or incorrect

## Dependencies

For `index.php`:

- PHP 8.x recommended
- PHP SysV message queue support: `sysvmsg`
- filesystem access to this directory
- access to the same SysV IPC namespace used by `TWCManager.py`

For `tesla_callback.php`:

- PHP 8.x recommended
- PHP cURL extension: `curl`
- outbound HTTPS access from the web server to Tesla OAuth endpoints
- write access to the OAuth helper config file location

For the Python backend used by `index.php`:

- `TWCManager.py` running
- Python package `sysv_ipc`

Typical Debian/Ubuntu packages:

```bash
sudo apt install php php-cli php-fpm php-curl php-sysvmsg
```

If you are using Apache with mod_php instead of PHP-FPM:

```bash
sudo apt install apache2 libapache2-mod-php php-curl php-sysvmsg
```

## Apache Example

Example document root:

- TWCManager code: `/srv/TWCManager`
- web files served from: `/srv/TWCManager/HTML`

Because `index.php` defaults to:

```php
$twcScriptDir = "/srv/TWCManager/";
```

that layout works without changing the file.

Example Apache virtual host:

```apache
<VirtualHost *:80>
    ServerName twcmanager.local
    DocumentRoot /srv/TWCManager/HTML

    <Directory /srv/TWCManager/HTML>
        AllowOverride None
        Require all granted
        Options FollowSymLinks
    </Directory>

    DirectoryIndex index.php

    ErrorLog ${APACHE_LOG_DIR}/twcmanager-error.log
    CustomLog ${APACHE_LOG_DIR}/twcmanager-access.log combined
</VirtualHost>
```

If you want `tesla_callback.php` to store its helper config outside the web
root, set an Apache environment variable:

```apache
SetEnv TESLA_OAUTH_CONFIG_FILE /etc/twcmanager/tesla_oauth_config.json
```

Example first steps with Apache:

1. Put the repository at `/srv/TWCManager`.
2. Enable the site with `DocumentRoot /srv/TWCManager/HTML`.
3. Make sure `TWCManager.py` is running.
4. Open `http://twcmanager.local/index.php`.
5. Open `http://twcmanager.local/tesla_callback.php` to generate
   `TeslaApiTokens.json` if needed.

## Nginx Example

Example Nginx server block with PHP-FPM:

```nginx
server {
    listen 80;
    server_name twcmanager.local;

    root /srv/TWCManager/HTML;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param TESLA_OAUTH_CONFIG_FILE /etc/twcmanager/tesla_oauth_config.json;
    }

    location ~ /\. {
        deny all;
    }
}
```

Example first steps with Nginx:

1. Put the repository at `/srv/TWCManager`.
2. Serve `/srv/TWCManager/HTML` as the document root.
3. Adjust `fastcgi_pass` to match your installed PHP-FPM socket or port.
4. Make sure `TWCManager.py` is running.
5. Browse to `/index.php` for charger control.
6. Browse to `/tesla_callback.php` for Tesla token generation.

## Permissions And Runtime Notes

- The web server user and the Python process must be able to interact with the
  same SysV message queue.
- If `index.php` times out waiting for TWCManager, check:
  - that the Python process is running
  - that `$twcScriptDir` is correct
  - that PHP has `sysvmsg` support
  - that the web server user is not isolated from the IPC namespace
- If `tesla_callback.php` cannot save config, check:
  - the target directory exists
  - the web server user has write permissions
  - `TESLA_OAUTH_CONFIG_FILE` points to a valid writable path if used

## Usage Examples

Open the main control page:

```text
http://twcmanager.local/index.php
```

Open the Tesla OAuth helper:

```text
http://twcmanager.local/tesla_callback.php
```

Open the debug menu:

```text
http://twcmanager.local/index.php?debugTWC=1
```

Dump internal Python state:

```text
http://twcmanager.local/index.php?dumpState=1&submit=1
```

Send a debug RS-485 message:

```text
http://twcmanager.local/index.php?sendTWCMsg=FB1B&submit=1
```

Override master heartbeat data for testing:

```text
http://twcmanager.local/index.php?setMasterHeartbeatData=050FA0&submit=1
```

Use the debug routes carefully. They are for protocol diagnostics and can send
low-level charger commands.
