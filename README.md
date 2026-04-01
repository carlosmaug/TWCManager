# TWCManager

`TWCManager.py` is a charge manager for **Tesla Wall Connector Gen2** units connected over **RS-485**. In normal operation it emulates a Wall Connector master (`fakeMaster=1`) and controls one or more slave chargers by adjusting the current they are allowed to offer.

It does not support Tesla Gen3 Wall Connectors and it does not work with the older HPWC hardware.

## Authors And Project Lineage

The original TWCManager project and the underlying Tesla Wall Connector Gen2
protocol reverse-engineering work are by **Chris Dragon**.

This repository has also been substantially modified and modernized by
**Carlos Martin Ugalde** (GitHub: `carlosmaug`). That work includes deep code
updates, structural cleanup, documentation improvements, Tesla token web
tooling in `HTML/tesla_callback.php`, PHP compatibility work, and broader
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
- exposes an IPC interface used by the bundled PHP web UI in `HTML/`

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

The `HTML/` directory contains a PHP web interface that communicates with `TWCManager.py` through **SysV IPC message queues**.

From that interface, the running process can:

- return status
- set non-scheduled current limits
- set scheduled charging parameters
- set the automatic green-energy resume time
- trigger or cancel `chargeNow`
- import Tesla API tokens
- send low-level debug commands on the RS-485 bus

`chargeNow` forces charging at the installation maximum for 24 hours unless cancelled sooner.

## HTML Directory

The `HTML/` directory currently contains:

- `index.php`
- `tesla_callback.php`
- `favicon.png`
- `refresh.png`

### `HTML/index.php`

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

### `HTML/tesla_callback.php`

`tesla_callback.php` is a separate OAuth helper for Tesla token generation. It does not control charging directly and it does not talk to the IPC queue.

Its job is to simplify the token bootstrap process for the current Python code:

- stores OAuth app settings in a local JSON config file
- builds a Tesla authorization URL
- receives Tesla's OAuth callback with `code=...`
- exchanges the authorization code for Tesla tokens
- converts the result into the exact `TeslaApiTokens.json` structure expected by `TWCManager.py`
- triggers a browser download of that JSON file

Operational notes for this helper:

- it uses Tesla's OAuth token endpoint directly with cURL
- it validates that the current callback URL matches the saved `redirect_uri`
- it stores its helper configuration in `tesla_oauth_config.json` by default, unless overridden with `TESLA_OAUTH_CONFIG_FILE`
- it is intended for Apache/PHP-style deployments where generating `TeslaApiTokens.json` in a browser is convenient

In short:

- use `index.php` to monitor and control a running TWCManager instance
- use `tesla_callback.php` to generate the token JSON consumed by the Python process

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
- `HTML/index.php`: main web UI, scheduler form, and low-level debug interface
- `HTML/tesla_callback.php`: Tesla OAuth helper that generates `TeslaApiTokens.json`
- `TWCManager Installation.pdf`: original installation guide

## Limitations And Safety Notes

- The code is aimed at **Gen2 Wall Connectors**.
- It works directly against Tesla's low-level RS-485 Wall Connector protocol.
- Incorrect amp settings can trip breakers or create real electrical risk.
- The debug web interface blocks some known-dangerous messages, but this is still charger-control software and should be used with care.
