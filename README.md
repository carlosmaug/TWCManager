# TWCManager

`TWCManager.py` is a charge manager for **Tesla Wall Connector Gen2** units connected over **RS-485**. In normal operation it emulates a Wall Connector master (`fakeMaster=1`) and controls one or more slave chargers by adjusting the current they are allowed to offer.

It does not support Tesla Gen3 Wall Connectors and it does not work with the older HPWC hardware.

## Authors And Project Lineage

The original TWCManager project and the underlying Tesla Wall Connector Gen2
protocol reverse-engineering work are by **Chris Dragon**.

This repository has also been substantially modified and modernized by
**Carlos Martin Ugalde** (GitHub: `carlosmaug`). That work includes deep code
updates, structural cleanup, documentation improvements, Tesla token web
tooling in `php/tesla_callback.php`, PHP compatibility work, and broader
ongoing maintenance beyond the original codebase.

## What The Program Actually Does

The current code is more than a simple current limiter. It combines several decision sources:

- installation-wide and per-charger wiring limits
- scheduled charging windows
- a temporary `chargeNow` override
- solar / green-energy tracking
- optional Tesla API vehicle control when charger-side current limiting is not enough

In practice, the program:

- listens for Gen2 Wall Connector linkready and heartbeat frames
- acts as the RS-485 master for slave Wall Connectors
- decides how many amps may be divided among connected chargers
- persists mutable settings to `TWCManagerSettings.txt`
- stores Tesla API tokens separately in `TeslaApiTokens.json` or `TESLA_API_*` environment variables
- maintains a persistent delivered-energy counter in kWh
- exposes an IPC interface used by the bundled PHP web UI in `php/`

## Current Behavior In This Repository

With the `TWCManagerSettings.txt` currently checked into this repo, the program starts with:

- RS-485 adapter: `/dev/ttyUSB0`
- baud rate: `9600`
- total wiring limit: `32A`
- per-TWC wiring limit: `32A`
- configured minimum charging current: `1A`
- green-energy offset: `-3A`
- debug level: `2`
- operating mode: fake master (`fakeMaster=1`)

The charging policy currently configured is:

- charge at up to `25A` from `00:54` to `07:00`
- apply that schedule every day (`scheduledAmpsDaysBitmap=127`)
- outside the scheduled window, do not apply a fixed non-scheduled cap because `nonScheduledAmpsMax=-1`
- outside the scheduled window, fall back to green-energy tracking behavior
- only use Tesla API commands for multiple vehicles when the vehicle is considered at home

## Green-Energy Tracking

Green-energy tracking is present, but in the current code it is **not a generic energy integration layer**.

`GreenEnergyMonitor` uses a hard-coded Solax Cloud URL inside `TWCManager.py`, reads `feedinpower` from that response, converts it to amps assuming `240V`, and then applies `greenEnergyAmpsOffset`.

That means, as the code exists today:

- solar tracking depends on one specific external endpoint
- the solar source is not configured in `TWCManagerSettings.txt`
- changing inverter vendor, API, or conversion logic requires code changes

## Tesla API Support

Tesla API support is optional, but the code does include active support for:

- importing tokens from `TeslaApiTokens.json`
- importing tokens from `TESLA_API_ACCESS_TOKEN`, `TESLA_API_REFRESH_TOKEN`, and `TESLA_API_EXPIRES_AT`
- refreshing access tokens
- discovering vehicles
- waking vehicles
- sending `charge_start` and `charge_stop`

This path is used when changing the charger-side amp offer is not enough to produce the desired vehicle behavior.

## Web UI And IPC

The `php/` directory contains a PHP web interface that communicates with `TWCManager.py` through **SysV IPC message queues**.

From that interface, the running process can:

- return status
- set non-scheduled current limits
- set scheduled charging parameters
- set the automatic green-energy resume time
- trigger or cancel `chargeNow`
- import Tesla API tokens
- send low-level debug commands on the RS-485 bus

`chargeNow` forces charging at the installation maximum for 24 hours unless cancelled sooner.

## PHP Directory

The `php/` directory currently contains:

- `index.php`
- `tesla_callback.php`
- `favicon.png`
- `refresh.png`

### `php/index.php`

`index.php` is the main browser UI for a running `TWCManager.py` process.

At startup it:

- disables browser caching
- opens the same SysV IPC queue used by `TWCManager.py`
- queries the Python process for current state with `getStatus`
- renders a simple control panel based on the returned charger state

Its normal user-facing functions are:

- showing total charging current available to all managed TWCs
- showing each detected slave TWC, its ID, actual charging amps, and offered amps
- setting `nonScheduledAmpsMax`
- setting scheduled charging amps, start time, end time, and active days
- setting `hourResumeTrackGreenEnergy`
- triggering or cancelling the 24-hour `chargeNow` override
- refreshing the page state manually

It also adapts some UI choices to the charger model it sees:

- if the discovered TWC looks like a lower-current EU model, it offers a smaller set of amp presets and uses 24-hour time labels
- otherwise it offers a larger North American-style amp list

In addition to the normal control panel, `index.php` contains several debug and maintenance routes:

- `?debugTWC=1` shows a debug menu
- `?sendTWCMsg=...&submit=1` sends arbitrary RS-485 frames through TWCManager for protocol testing
- `?setMasterHeartbeatData=...&submit=1` overrides master heartbeat payload data
- `?dumpState=1&submit=1` displays internal state returned by the Python process

Those routes are intended for protocol debugging, not normal operation. Some dangerous messages are blocked in `TWCManager.py`, but this interface still exposes low-level charger controls and should not be treated as a hardened admin surface.

There is one important mismatch between the current PHP and Python code:

- `index.php` still contains an old email/password submission flow and may show a credential form when no Tesla bearer token is available
- the current `TWCManager.py` no longer implements the old `carApiEmailPassword=...` IPC command
- the supported approach today is token-based Tesla access, either by placing `TeslaApiTokens.json`, using `TESLA_API_*` environment variables, or generating tokens with `tesla_callback.php`

### `php/tesla_callback.php`

`tesla_callback.php` is a separate OAuth helper for Tesla token generation and partner-registration preparation. It does not control charging directly and it does not talk to the IPC queue.

Its job is to simplify the token bootstrap process for the current Python code:

- stores OAuth app settings in a local JSON config file
- builds a Tesla authorization URL
- receives Tesla's OAuth callback with `code=...`
- exchanges the authorization code for Tesla tokens
- converts the result into the exact `TeslaApiTokens.json` structure expected by `TWCManager.py`
- stores that JSON file directly on the server for `TWCManager.py`
- generates Tesla partner key material automatically when missing
- publishes the public key under `public/.well-known/appspecific/com.tesla.3p.public-key.pem`
- attempts Tesla partner-domain registration automatically and shows the status in the helper page

Operational notes for this helper:

- it uses Tesla's OAuth token endpoint directly with cURL
- it validates that the current callback URL matches the saved `redirect_uri`
- it stores its helper configuration in `tesla_oauth_config.json` by default, unless overridden with `TESLA_OAUTH_CONFIG_FILE`
- it is intended for Apache/PHP-style deployments where handling Tesla OAuth and partner-registration preparation from the admin web UI is convenient

In short:

- use `index.php` to monitor and control a running TWCManager instance
- use `tesla_callback.php` to generate and save the token JSON consumed by the Python process

### How To Renew Tesla Tokens

If the web UI shows `Tesla API not operative`, or the Python log contains:

- `Failed to refresh Tesla API token`
- `Import a new token set generated with MFA`

then the stored Tesla token set is no longer usable and should be replaced.

The recommended renewal path in this repository is `php/tesla_callback.php`.

Prerequisites:

- the PHP web UI is reachable in a browser
- `tesla_callback.php` can write its helper config file
- the web server can write the Tesla partner private key path and the public `.well-known` key path
- the server can make outbound HTTPS requests to Tesla
- you have a Tesla developer app with:
  - `client_id`
  - `client_secret`
  - a `redirect_uri` registered in Tesla that matches the exact URL of `tesla_callback.php`

What you must do in Tesla first:

1. Open the Tesla developer portal:
   - `https://developer.tesla.com/`
2. Sign in and open the app dashboard / application access flow:
   - `https://developer.tesla.com/dashboard`
3. If you still do not have an approved app, follow Tesla's official Fleet API onboarding guide and submit the API request:
   - `https://developer.tesla.com/docs/fleet-api/getting-started/what-is-fleet-api`
4. Create or open a Tesla developer application for Fleet API / OAuth usage.
5. In that Tesla app, configure an OAuth redirect URI that points to your hosted helper page.
   Example:
   - `https://your-host/tesla_callback.php`
6. Make sure the redirect URI in Tesla is exactly identical to the URL that will receive the callback in your browser.
   It must match scheme, host, port, path, and trailing slash behavior exactly.
7. Add the helper host root domain to Tesla `allowed_origins`.
   Example:
   - if the helper is at `https://admin.example.com/tesla_callback.php`, the allowed origin should include `https://admin.example.com`
8. Make sure that host is reachable publicly over HTTPS, because Tesla requires the partner public key to stay published at:
   - `https://your-host/.well-known/appspecific/com.tesla.3p.public-key.pem`
9. Copy the Tesla app credentials you will later paste into `tesla_callback.php`:
   - `client_id`
   - `client_secret`
10. Use the Fleet API audience expected by this project unless you intentionally run against a different Tesla region:
   - Europe default: `https://fleet-api.prd.eu.vn.cloud.tesla.com`
11. Use scopes that allow reading vehicle data, locating the vehicle, and sending charging commands.
   The helper defaults to:
   - `openid offline_access user_data vehicle_device_data vehicle_location vehicle_cmds vehicle_charging_cmds`
12. Save the Tesla app changes in Tesla's developer portal before starting the browser login flow.

Step by step:

1. Open `http://your-host/tesla_callback.php`.
2. Fill in and save the values from your Tesla developer app:
   - `client_id`
   - `client_secret`
   - `redirect_uri`
   - `audience`
   - `scope`
3. Make sure the `redirect_uri` saved in the page is exactly the same as the redirect URI registered in Tesla.
   Even small differences such as `http` vs `https`, hostname, port, trailing slash, or reverse-proxy URL will break the flow.
4. After saving, the helper automatically:
   - creates Tesla partner key files if they do not already exist
   - publishes the public key under the `.well-known` path inside `public/`
   - attempts Tesla partner-domain registration against the configured audience
5. In the helper page, review the `Estado Tesla Partner` card.
   If Tesla registration is still pending, follow the remaining external steps shown there.
6. Click `Abrir login Tesla`.
7. Sign in to Tesla and complete MFA if prompted.
8. Let Tesla redirect the browser back to `tesla_callback.php`.
9. Confirm that the page says `TeslaApiTokens.json generado correctamente y guardado en el servidor ...`
10. Ensure the running Python process can read that file.
   In a typical deployment from this repository that means:
   - `/srv/TWCManager/TeslaApiTokens.json`
11. Restart `TWCManager.py`.
12. Re-open the main web UI and confirm the Tesla status indicator is green.

Expected result after a successful renewal:

- the Python log should show `Tesla API tokens imported`
- when charging logic needs Tesla API help, it should no longer fail on token refresh
- the web UI should show the Tesla API status as operative

Common mistakes:

- the helper saved `TeslaApiTokens.json`, but the Python process is using a different installation path
- the web server could write the token file, but the Python process cannot read it
- `redirect_uri` in Tesla does not exactly match the URL that handled the callback
- a reverse proxy, Basic Auth layer, or SSO gateway challenged `tesla_callback.php` before Tesla could reach the real callback endpoint
- the PHP session cookie was configured with `SameSite=Strict`, so the OAuth callback lost the saved session state
- the token set was generated from an incomplete or wrong OAuth app configuration
- the helper could not create or publish the Tesla partner key files
- the Tesla app is missing the correct `allowed_origins` entry for the helper domain
- the `.well-known` public-key URL is not reachable over public HTTPS
- the file permissions prevent the Python process from reading `TeslaApiTokens.json`

Quick validation checklist:

- `TeslaApiTokens.json` exists next to `TWCManager.py`
- `public/.well-known/appspecific/com.tesla.3p.public-key.pem` exists and is served publicly
- it contains at least:
  - `access_token`
  - `refresh_token`
  - `expires_at`
  - `client_id`
- `tesla_callback.php` shows partner registration as completed, or a clear pending/error reason
- after restart, the log no longer prints `Failed to refresh Tesla API token`
- the Tesla API status badge in the web UI is green instead of red

## Configuration File

`TWCManagerSettings.txt` is the main persistent configuration file. It uses a simple `key=value` format, ignores blank lines, and ignores lines beginning with `#`.

The file mixes two kinds of values:

- operator-controlled settings, such as current limits and scheduling
- persistent runtime state, such as `kWhDelivered`

The settings currently supported by the code are:

### Serial And Bus Settings

- `rs485Adapter`
  Path to the serial device connected to the Wall Connector RS-485 bus.
- `baud`
  RS-485 baud rate. Gen2 Wall Connectors normally use `9600`.
- `fakeMaster`
  Bus mode. `1` is the normal master-emulation mode, `0` emulates a slave, and `2` is a listen/debug mode.
- `fakeTWCID`
  Hex-encoded 2-byte identifier used by the emulated local TWC.
- `masterSign`
  Hex-encoded 1-byte sign used in master frames.
- `slaveSign`
  Hex-encoded 1-byte sign used in slave frames.

### Electrical Limits

- `wiringMaxAmpsAllTWCs`
  Maximum continuous current the whole installation may offer across all connected Wall Connectors.
- `wiringMaxAmpsPerTWC`
  Maximum continuous current a single Wall Connector may offer.
- `minAmpsPerTWC`
  Policy minimum charging current TWCManager tries to respect when deciding whether charging should happen.
- `minAmpsTWCSupports`
  Minimum current assumed to be supported by the hardware / protocol.
- `greenEnergyAmpsOffset`
  Offset added to the computed solar surplus in amps. Negative values reserve current for household base load.

### Scheduling And Charge Policy

- `nonScheduledAmpsMax`
  Maximum charge current outside the scheduled charging window. `-1` disables this fixed cap and allows other logic, including green-energy tracking, to decide.
- `scheduledAmpsMax`
  Maximum charge current during the scheduled charging window. `-1` disables scheduled charging.
- `scheduledAmpsStartHour`
  Start of the scheduled window in local decimal hours.
- `scheduledAmpsEndHour`
  End of the scheduled window in local decimal hours.
- `scheduledAmpsDaysBitmap`
  Day bitmap for the schedule. Bit 0 is Monday through bit 6 for Sunday.
- `hourResumeTrackGreenEnergy`
  Local hour when fixed non-scheduled charging should be cleared and green-energy tracking resumed automatically. `-1` disables the feature.

### Tesla API And Home Detection

- `onlyChargeMultiCarsAtHome`
  When multiple Tesla vehicles exist in the account, only send Tesla API start/stop commands to vehicles determined to be at home.
- `homeLat`
  Home latitude used for vehicle-at-home detection.
- `homeLon`
  Home longitude used for vehicle-at-home detection.

### Logging And Persistent State

- `debugLevel`
  Log verbosity.
- `displayMilliseconds`
  Whether timestamps include milliseconds.
- `kWhDelivered`
  Persistent delivered-energy counter stored in kWh and updated over time by the running process.

### Notes About The Configuration File

- Tesla API secrets are intentionally not stored in `TWCManagerSettings.txt`.
- The program rewrites this file when settings or persistent state are saved.
- Unknown keys are logged as unknown settings.
- Invalid values are logged and ignored.
- Some values in the file are operational state rather than tuning parameters.

## Dependencies

The Python script uses at least:

- Python 3
- `pyserial`
- `requests`
- `sysv_ipc`

If you use the bundled web UI, you also need:

- PHP with SysV message queue support

## Relevant Files

- `TWCManager.py`: main application logic
- `TWCManagerSettings.txt`: persistent configuration and saved runtime state
- `TeslaApiTokens.json`: Tesla API tokens
- `php/index.php`: main web UI, scheduler form, and low-level debug interface
- `php/tesla_callback.php`: Tesla OAuth helper that prepares partner registration and saves `TeslaApiTokens.json`
- `TWCManager Installation.pdf`: original installation guide

## Limitations And Safety Notes

- The code is aimed at **Gen2 Wall Connectors**.
- It works directly against Tesla's low-level RS-485 Wall Connector protocol.
- Incorrect amp settings can trip breakers or create real electrical risk.
- The debug web interface blocks some known-dangerous messages, but this is still charger-control software and should be used with care.
