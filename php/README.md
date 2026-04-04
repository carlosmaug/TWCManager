# PHP Web UI Layout

This project now separates browser-accessible files from private PHP code.

## Directory Layout

Public document root:

- `public/index.php`
- `public/tesla_callback.php`
- `public/favicon.png`
- `public/refresh.png`

Private application code, not meant to be served directly:

- `php/index.php`
- `php/tesla_callback.php`
- `php/lib/config.php.example`
- `php/lib/load_config.php`
- `php/lib/functions.php`
- `php/lib/index_bootstrap.php`

Recommended rule:

- point your web server `DocumentRoot` or `root` to `public/`
- do not expose `php/` directly over HTTP
- keep OAuth config and Tesla token JSON files outside the public document root

## Why This Matters

`php/` contains private PHP code and configuration. If you serve that
directory directly, a bad web-server or PHP-FPM configuration can expose files
that should never be public.

`public/` contains only the entry points and static assets that a browser
should reach.

## Runtime Files

These files should remain outside `public/`:

- `TWCManagerSettings.txt`
- `TeslaApiTokens.json`
- `tesla_oauth_config.json`
- energy-history JSON files

Typical safe locations:

- `/srv/TWCManager/TeslaApiTokens.json`
- `/etc/twcmanager/tesla_oauth_config.json`

## Application Configuration

Versioned defaults live in [`php/lib/config.php.example`](./lib/config.php.example).
Local runtime settings should live in:

- `php/lib/config.php`, or
- the path pointed to by `TWC_WEB_CONFIG_FILE`

`php/lib/config.php` is intended to stay out of version control.

Important options:

- `$twcScriptDir`
  Path to the directory containing `TWCManager.py`
- `$webRequireAuth`
  Enables the built-in authenticated session gate
- `$webUsername`
  Login username
- `$webPasswordHash`
  Password hash generated with `password_hash`
- `$webEnableDebugTools`
  Enables dangerous low-level debug routes
- `$webEnableTeslaHelper`
  Enables or disables `tesla_callback.php`
- `$webBaseUrl`
  Canonical public base URL, for example `https://twcmanager.local`
- `$webTrustProxyHeaders`
  Set to `true` only behind a trusted reverse proxy that rewrites forwarding
  headers
- `$webSessionIdleTimeoutSeconds`
  Idle timeout for authenticated sessions
- `$webSessionAbsoluteTimeoutSeconds`
  Maximum total session lifetime
- `$webSecurityLogEnabled`
  Enables a separate security-event log
- `$webSecurityLogFile`
  Path to the security-event log file

Generate a password hash like this:

```bash
php -r 'echo password_hash("change-me", PASSWORD_DEFAULT), PHP_EOL;'
```

## Web-Server Requirements

The web server user must:

- execute PHP
- access the same SysV IPC namespace as `TWCManager.py`
- be able to talk to the SysV message queue created by `TWCManager.py`

`TWCManager.py` now creates the queue with mode `0660`, not `0666`.

If the Python service and the web server run as different users, put them in a
shared dedicated group.

## Apache Example

Repository layout:

- code at `/srv/TWCManager`
- public web root at `/srv/TWCManager/public`

Example virtual host:

```apache
<VirtualHost *:80>
    ServerName twcmanager.local
    DocumentRoot /srv/TWCManager/public

    <Directory /srv/TWCManager/public>
        AllowOverride None
        Options FollowSymLinks
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <Directory /srv/TWCManager/php>
        Require all denied
    </Directory>

    <Location />
        Require ip 192.168.1.0/24 100.64.0.0/10
    </Location>

    SetEnv TESLA_OAUTH_CONFIG_FILE /etc/twcmanager/tesla_oauth_config.json
    SetEnv TWC_WEB_CONFIG_FILE /etc/twcmanager/web-config.php

    ErrorLog ${APACHE_LOG_DIR}/twcmanager-error.log
    CustomLog ${APACHE_LOG_DIR}/twcmanager-access.log combined
</VirtualHost>
```

If you use PHP-FPM with Apache, make sure the PHP handler applies to files in
`public/`.

Recommended extra controls in Apache:

- put the helper on a separate virtual host if possible
- add Basic Auth or your preferred front-door auth
- add IP allowlists on both the main UI and helper vhost
- use `mod_evasive` or an upstream reverse proxy for request rate limiting

## Nginx Example

Example server block:

```nginx
server {
    listen 80;
    server_name twcmanager.local;

    root /srv/TWCManager/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    allow 192.168.1.0/24;
    allow 100.64.0.0/10;
    deny all;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param TESLA_OAUTH_CONFIG_FILE /etc/twcmanager/tesla_oauth_config.json;
        fastcgi_param TWC_WEB_CONFIG_FILE /etc/twcmanager/web-config.php;
    }

    location ~ /\. {
        deny all;
    }
}
```

Do not set `root` to `/srv/TWCManager/php`.

Example rate limiting in nginx:

```nginx
limit_req_zone $binary_remote_addr zone=twc_ui:10m rate=10r/m;
limit_req_zone $binary_remote_addr zone=twc_login:10m rate=5r/m;

location = /index.php {
    limit_req zone=twc_ui burst=10 nodelay;
}
```

If you want a stricter helper deployment, use a separate server block:

```nginx
server {
    listen 80;
    server_name twcmanager-admin.local;

    root /srv/TWCManager/public;
    index tesla_callback.php;

    allow 192.168.1.0/24;
    allow 100.64.0.0/10;
    deny all;

    location = /tesla_callback.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/tesla_callback.php;
        fastcgi_param TESLA_OAUTH_CONFIG_FILE /etc/twcmanager/tesla_oauth_config.json;
        fastcgi_param TWC_WEB_CONFIG_FILE /etc/twcmanager/web-config.php;
        limit_req zone=twc_login burst=5 nodelay;
    }

    location / {
        return 404;
    }
}
```

## Recommended Deployment Steps

1. Put the repository at `/srv/TWCManager`.
2. Configure the web server to serve `/srv/TWCManager/public`.
3. Copy `php/lib/config.php.example` to `php/lib/config.php` or create `/etc/twcmanager/web-config.php`.
4. Set `$twcScriptDir`, `$webUsername`, and `$webPasswordHash`.
5. Set `$webBaseUrl` to the real public origin.
6. Leave `$webEnableDebugTools = false` unless you explicitly need them.
7. Leave `$webEnableTeslaHelper = false` unless you need the OAuth helper on this host.
8. Put `tesla_oauth_config.json` outside the document root.
9. Put `TeslaApiTokens.json` outside the document root, typically next to `TWCManager.py`.
10. Start `TWCManager.py`.
11. Browse to `/index.php`.

## Public URLs

Main UI:

```text
https://twcmanager.local/index.php
```

Tesla OAuth helper:

```text
https://twcmanager.local/tesla_callback.php
```

## Security Notes

- Do not expose `php/` directly.
- Do not commit `php/lib/config.php`.
- Do not store secrets under `public/`.
- Prefer HTTPS.
- Keep `$webTrustProxyHeaders = false` unless you really trust the proxy in
  front of the app.
- Keep `$webEnableDebugTools = false` on production systems.
- Set a real `$webPasswordHash` before enabling authentication.
- Use LAN or VPN IP allowlists at the reverse proxy.
- Keep `tesla_callback.php` disabled unless you actively need it.
- Prefer serving `tesla_callback.php` on a separate admin-only vhost.
- Send `$webSecurityLogFile` to a protected location such as `/var/log/`.

## Troubleshooting

If the main UI cannot talk to the backend, check:

- `TWCManager.py` is running
- `$twcScriptDir` is correct
- PHP has `sysvmsg`
- the PHP user and Python user share access to the `0660` IPC queue

If the Tesla helper cannot save its config, check:

- `TESLA_OAUTH_CONFIG_FILE` points to a writable path
- the directory exists
- the web server user can write there

If login sessions end sooner than expected, check:

- `$webSessionIdleTimeoutSeconds`
- `$webSessionAbsoluteTimeoutSeconds`
- whether the browser is accepting session cookies

If Tesla redirects back but the helper rejects the callback, check:

- `$webBaseUrl` matches the real public origin
- Tesla's registered `redirect_uri` exactly matches the helper URL
- your browser session is preserved between login start and callback
