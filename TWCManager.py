#! /usr/bin/python3

"""Tesla Wall Connector load manager with optional Tesla Fleet/Owner API support.

This program behaves as a Tesla Wall Connector master on the RS-485 bus and
manages one or more slave chargers. Its main loop listens for charger
heartbeats, tracks charger state, calculates the current budget available for
charging, and sends updated current limits back to each charger.

At the protocol level it emulates the behavior expected from a master Wall
Connector so that real slave units continue to exchange heartbeat frames and
accept revised current limits. In practice that means the application is both a
charger coordinator and a policy engine: it decides when charging should be
allowed, how many amps may be offered, and when a vehicle-side Tesla API action
is needed because charger-side current control alone is not sufficient.

Current can be limited from several sources:

* fixed wiring limits for one charger or the whole installation
* scheduled charging windows
* a temporary ``charge now`` override
* dynamic solar/green-energy telemetry

That makes the application useful as a home charging coordinator: it can track
surplus solar generation, keep the installation inside wiring limits, apply
time-based charging policy, and reduce or stop charging when external
conditions change. It does not obtain battery state directly from the Wall
Connector protocol, so vehicle-side state may still require Tesla API access.

When charger-level current control is not enough, the program can also use the
Tesla API to wake vehicles and request ``charge_start`` or ``charge_stop``.
That path is used, for example, when a vehicle should begin charging after
power becomes available or when an automatically-started charge must stop again
because available solar power has fallen away.

Authentication is not performed interactively here. Tesla access and refresh
tokens are expected to exist already, typically in ``TeslaApiTokens.json``,
while mutable runtime settings are persisted in ``TWCManagerSettings.txt``.

Origin, support and license
===========================

The original code and TWC protocol reverse engineering are by Chris Dragon,
with additional logs and hints from Teslamotorsclub.com users TheNoOne,
IanAmber and twc.

This codebase has also been substantially updated and extended by Carlos Martin
Ugalde (GitHub: ``carlosmaug``), including major modernization work, web-based
Tesla token tooling, documentation improvements, code cleanup, and broader
maintenance updates beyond the original single-script baseline.

Project discussion:
``https://teslamotorsclub.com/tmc/threads/new-wall-connector-load-sharing-protocol.72830``

Issue tracker:
``https://github.com/cdragon/TWCManager/issues``

This software is released under the Unlicense:
``http://unlicense.org``

Main subsystems
===============

* ``GeneralConfig``, ``EnergyConfig`` and ``TeslaApiConfig`` hold the runtime
  configuration loaded from settings.
* ``RuntimeState`` contains mutable charger-control state shared across the
  polling loop.
* ``TWCSlave`` implements per-charger state tracking, heartbeat handling and
  charge-current decisions.
* ``TeslaCarApi`` wraps the Tesla HTTP API for token refresh, wake-up and
  charge start/stop requests.
* ``BackgroundTaskRunner`` serializes slower side effects such as Tesla API
  calls and green-energy refreshes away from the RS-485 loop.
* ``TWCManagerApp.run()`` wires everything together and drives the process.

Protocol overview
=================

Slave Wall Connectors periodically announce themselves with linkready frames.
Once discovered, the master exchanges heartbeat frames with each slave about
once per second. Those heartbeats carry the charger state, the current the car
is actually drawing, and the current limit being requested or offered. This is
the core feedback loop that lets TWCManager decide whether to raise, lower or
remove charging current on each cycle.

Maintenance notes
=================

This file is still intentionally close to the original single-script design.
Dataclasses and helper classes now exist, but they still coexist with legacy
module-level globals and helper functions. Changes should preserve both object
state and legacy global state where the code still depends on both.

The TWC protocol is binary and timing-sensitive. Heartbeats are exchanged about
once per second, linkready frames drive charger discovery, and some frame types
are intentionally blocked because they may crash or permanently disable a
charger. Any change to heartbeat timing, offered-current logic or protocol
framing should be validated against real hardware assumptions before deployment.

Disclaimer
==========

Misuse of the protocol implemented here can cause a charger to offer more
current than the wiring or protection devices were designed to handle. That may
trip a breaker and, in a fault scenario, could create equipment damage or fire
risk. This software was not produced with internal Tesla design information,
and unforeseen interactions could affect a vehicle, a Wall Connector or the
installation around it.

The software is provided "as is", without warranty of any kind, express or
implied, including but not limited to merchantability, fitness for a particular
purpose and noninfringement. Use it only if you understand the electrical and
operational risks and are prepared to accept full responsibility for them.
"""

import serial
import time
import re
import subprocess
import queue
import random
import math
import struct
import sys
import traceback
import requests
import sysv_ipc
import json
import os
from datetime import timedelta
from datetime import datetime
import threading
from dataclasses import dataclass


##########################
#
# Configuration parameters
#

# These built-in defaults are overridden at runtime by TWCManagerSettings.txt.
# For parameter descriptions, examples, and operational guidance, see:
# - TWCManagerSettings.txt
# - README.md

# RS-485 adapter device path.
rs485Adapter = '/dev/ttyUSB0'

# Installation-wide wiring limit.
wiringMaxAmpsAllTWCs = 32 

# Per-wall-connector wiring limit.
wiringMaxAmpsPerTWC = 32 

# Policy minimum charge current and hardware minimum current.
minAmpsPerTWC = 1 
minAmpsTWCSupports  = 1 

# Restrict Tesla API control to vehicles considered at home when multiple cars exist.
onlyChargeMultiCarsAtHome = True

# Offset applied to computed green-energy amps.
greenEnergyAmpsOffset = -3 

# Log verbosity.
debugLevel = 2 

# Include milliseconds in log timestamps.
displayMilliseconds = False

# Operating mode: master emulation, slave emulation, or listen/debug.
fakeMaster = 1

# RS-485 baud rate.
baud       = 9600

# Emulated local TWC ID.
fakeTWCID  = bytearray(b'\x77\x77')

# Sign bytes used in protocol frames.
masterSign  = bytearray(b'\x77')
slaveSign   = bytearray(b'\x77')

#
# End configuration parameters
#
##############################


##############################
#
# Begin functions
#

def time_now():
    global displayMilliseconds
    return(datetime.now().strftime("%H:%M:%S" + (
        ".%f" if displayMilliseconds else "")))

def hex_str(s:str):
    return " ".join("{:02X}".format(ord(c)) for c in s)

def hex_str(ba:bytearray):
    return " ".join("{:02X}".format(c) for c in ba)

def run_process(cmd):
    result = None
    try:
        result = subprocess.check_output(cmd, shell=True)
    except subprocess.CalledProcessError:
        # We reach this point if the process returns a non-zero exit code.
        result = b''

    return result


settings_store = None
tesla_token_store = None
green_energy_monitor = None
background_task_runner = None
tesla_car_api = None
rs485_transport = None
web_ipc_server = None
log_output_stream = None
original_stdout = sys.stdout
original_stderr = sys.stderr
# These module-level service references are kept for compatibility with the
# original script layout. New code should treat them as process singletons.


@dataclass
class GeneralConfig:
    """General process configuration not tied to a single subsystem."""
    debug_level: int
    display_milliseconds: bool
    log_file: str
    fake_master: int
    fake_twc_id: bytearray
    master_sign: bytearray
    slave_sign: bytearray
    settings_file_name: str


@dataclass
class TeslaApiConfig:
    """Configuration required by Tesla Owner API integration."""
    only_charge_multi_cars_at_home: bool
    home_lat: float
    home_lon: float


@dataclass
class EnergyConfig:
    """Electrical limits and green-energy thresholds used in charge decisions."""
    wiring_max_amps_all_twcs: float
    wiring_max_amps_per_twc: float
    min_amps_per_twc: float
    min_amps_twc_supports: float
    green_energy_amps_offset: float


@dataclass
class RS485Config:
    """Serial transport settings for the TWC RS-485 bus."""
    adapter: str
    baud_rate: int
    timeout: int = 0


@dataclass
class WebIPCConfig:
    """Configuration for the SysV IPC bridge used by the web UI."""
    script_path: str


def parse_bool(value):
    value = str(value).strip().lower()
    if(value in ('1', 'true', 'yes', 'on')):
        return True
    if(value in ('0', 'false', 'no', 'off')):
        return False
    raise ValueError('invalid boolean value: ' + str(value))


def format_bool(value):
    return 'true' if value else 'false'


def parse_hex_bytes(value, expected_len=None):
    cleaned = re.sub(r'[^0-9a-fA-F]', '', str(value))
    if(cleaned == ''):
        raise ValueError('empty hex byte string')
    if(len(cleaned) % 2 != 0):
        raise ValueError('hex byte string must contain an even number of digits')

    parsed = bytearray.fromhex(cleaned)
    if(expected_len != None and len(parsed) != expected_len):
        raise ValueError('expected %d bytes but found %d' % (expected_len, len(parsed)))
    return parsed


def format_hex_bytes(value):
    return ''.join('{:02X}'.format(byte) for byte in value)


class TeslaTokenStore:
    """Persist Tesla API tokens in a dedicated JSON file or environment."""
    def __init__(self, file_name):
        self.file_name = file_name

    def load(self):
        env_refresh = os.environ.get('TESLA_API_REFRESH_TOKEN', '').strip()
        env_access = os.environ.get('TESLA_API_ACCESS_TOKEN', '').strip()
        env_expires = os.environ.get('TESLA_API_EXPIRES_AT', '').strip()
        if(env_refresh != '' or env_access != ''):
            tokens = {
                'access_token': env_access,
                'refresh_token': env_refresh,
            }
            env_audience = os.environ.get('TESLA_API_AUDIENCE', '').strip()
            env_client_id = os.environ.get('TESLA_API_CLIENT_ID', '').strip()
            env_fleet_base_url = os.environ.get('TESLA_FLEET_API_BASE_URL', '').strip()
            if(env_expires != ''):
                try:
                    tokens['expires_at'] = float(env_expires)
                except ValueError:
                    print(time_now() + ': ERROR: Invalid TESLA_API_EXPIRES_AT value.')
            if(env_audience != ''):
                tokens['audience'] = env_audience
            if(env_client_id != ''):
                tokens['client_id'] = env_client_id
            if(env_fleet_base_url != ''):
                tokens['fleet_api_base_url'] = env_fleet_base_url
            return tokens

        try:
            fh = open(self.file_name, 'r')
            try:
                return json.load(fh)
            finally:
                fh.close()
        except FileNotFoundError:
            return {}
        except json.JSONDecodeError:
            print(time_now() + ': ERROR: Token file contains invalid JSON: ' + self.file_name)
            return {}

    def save(self, tokens):
        payload = {
            'access_token': tokens.get('access_token', ''),
            'refresh_token': tokens.get('refresh_token', ''),
            'expires_at': float(tokens.get('expires_at', 0)),
        }
        if(tokens.get('audience', '') != ''):
            payload['audience'] = tokens.get('audience', '')
        if(tokens.get('client_id', '') != ''):
            payload['client_id'] = tokens.get('client_id', '')
        if(tokens.get('fleet_api_base_url', '') != ''):
            payload['fleet_api_base_url'] = tokens.get('fleet_api_base_url', '')

        fh = open(self.file_name, 'w')
        try:
            json.dump(payload, fh, indent=2, sort_keys=True)
            fh.write('\n')
        finally:
            fh.close()

        try:
            os.chmod(self.file_name, 0o600)
        except OSError:
            pass


class SettingsStore:
    """Load and persist mutable settings shared across restarts."""
    def __init__(self, general_config):
        self.general_config = general_config
        self.file_name = general_config.settings_file_name
        self.parsers = (
            ('rs485Adapter', str),
            ('baud', int),
            ('wiringMaxAmpsAllTWCs', float),
            ('wiringMaxAmpsPerTWC', float),
            ('minAmpsPerTWC', float),
            ('minAmpsTWCSupports', float),
            ('greenEnergyAmpsOffset', float),
            ('debugLevel', int),
            ('displayMilliseconds', parse_bool),
            ('logFile', str),
            ('fakeMaster', int),
            ('fakeTWCID', lambda value: parse_hex_bytes(value, 2)),
            ('masterSign', lambda value: parse_hex_bytes(value, 1)),
            ('slaveSign', lambda value: parse_hex_bytes(value, 1)),
            ('onlyChargeMultiCarsAtHome', parse_bool),
            ('nonScheduledAmpsMax', int),
            ('scheduledAmpsMax', int),
            ('scheduledAmpsStartHour', float),
            ('scheduledAmpsEndHour', float),
            ('scheduledAmpsDaysBitmap', int),
            ('hourResumeTrackGreenEnergy', float),
            ('greenEnergyRateLimitedUntil', float),
            ('kWhDelivered', float),
            ('homeLat', float),
            ('homeLon', float),
        )

    def load(self):
        settings = {}

        try:
            fh = open(self.file_name, 'r')

            for line in fh:
                if(re.search(r'^\s*(#.*)?$', line)):
                    continue

                found = False
                for key, caster in self.parsers:
                    m = re.search(r'^\s*' + key + r'\s*=\s*(.+)', line, re.MULTILINE)
                    if(m):
                        try:
                            settings[key] = caster(m.group(1))
                        except (TypeError, ValueError):
                            print(time_now() + ": load_settings: Invalid value for " + key + ": " + m.group(1))
                            found = True
                            break
                        if(debugLevel >= 10):
                            print("load_settings: " + key + " set to " + str(settings[key]))
                        found = True
                        break

                if(not found):
                    print(time_now() + ": load_settings: Unknown setting " + line)

            fh.close()
        except FileNotFoundError:
            pass

        return settings

    def save(self, settings):
        fh = open(self.file_name, 'w')
        fh.write(self.render(settings))
        fh.close()

    def render(self, settings):
        return """# TWCManager settings
#
# Format:
#   key=value
# Blank lines and lines beginning with # are ignored.
#
# Serial device for the RS-485 adapter connected to the Tesla Wall Connector bus.
# Typical values:
#   /dev/ttyUSB0    USB-RS485 adapter
#   /dev/ttyUSB1    if more than one USB serial adapter is connected
#   /dev/serial0    onboard UART / HAT / GPIO serial adapter
rs485Adapter={rs485Adapter}

# RS-485 bus speed in bauds.
# Tesla Gen2 Wall Connector uses 9600. Do not change it unless your hardware
# really uses a different speed.
# Example: 9600
baud={baud}

# Maximum continuous current available for all Wall Connectors combined.
# This is the installation-wide limit that TWCManager will never intentionally
# exceed.
# Examples:
#   32  -> 40A breaker at 80%
#   24  -> 30A breaker at 80%
#   80  -> 100A breaker at 80%
wiringMaxAmpsAllTWCs={wiringMaxAmpsAllTWCs}

# Maximum continuous current that a single Wall Connector may use.
# If all chargers share one breaker, this is usually the same as
# wiringMaxAmpsAllTWCs.
# Example:
#   Two chargers on separate 40A continuous branches:
#   wiringMaxAmpsPerTWC=40
wiringMaxAmpsPerTWC={wiringMaxAmpsPerTWC}

# Minimum current that TWCManager should normally offer to a car.
# Lower values allow charging with less surplus energy, but can be inefficient.
# Examples:
#   6   -> common technical minimum
#   12  -> more efficient on many single-phase installations
minAmpsPerTWC={minAmpsPerTWC}

# Minimum current the Wall Connector hardware/protocol supports.
# Normally leave this at the real hardware minimum and use minAmpsPerTWC for
# policy decisions.
# Example: 6
minAmpsTWCSupports={minAmpsTWCSupports}

# Offset applied to surplus-solar current calculation.
# Use a negative value to reserve current for the rest of the house, or a
# positive value to make charging start earlier.
# Examples:
#   -3   -> reserve about 3A for household base load
#    0   -> use only measured surplus as-is
greenEnergyAmpsOffset={greenEnergyAmpsOffset}

# Log verbosity.
# Common values:
#   0  -> only errors
#   1  -> useful operational logs
#   2  -> normal debugging
#   9  -> includes raw RS-485 frames
debugLevel={debugLevel}

# Whether log timestamps include milliseconds.
# Allowed values: true, false
# Example: true
displayMilliseconds={displayMilliseconds}

# Log destination file. Leave blank to keep logging on the console.
# Example:
#   /var/log/twcmanager.log
logFile={logFile}

# Operating mode on the RS-485 bus.
# Values:
#   1 -> emulate a master Wall Connector and control slaves
#   0 -> emulate a slave
#   2 -> listen/debug mode, does not manage charging normally
fakeMaster={fakeMaster}

# Emulated local TWC identifier, in hexadecimal, 2 bytes.
# Use 4 hex digits.
# Example: 7777
fakeTWCID={fakeTWCID}

# Sign bytes used in linkready/heartbeat frames, in hexadecimal, 1 byte each.
# Normally you should not change them.
# Example: 77
masterSign={masterSign}
slaveSign={slaveSign}

# If there are several Tesla vehicles in the account, only send API commands
# to vehicles detected at home.
# Allowed values: true, false
# Example: true
onlyChargeMultiCarsAtHome={onlyChargeMultiCarsAtHome}

# Maximum charge current outside the scheduled charging window.
# Use -1 to disable this limit and let other rules decide.
# Examples:
#   -1  -> no extra limit outside schedule
#   16  -> cap charging at 16A outside schedule
nonScheduledAmpsMax={nonScheduledAmpsMax}

# Maximum charge current during the scheduled window.
# Use -1 to disable scheduled charging.
# Example:
#   scheduledAmpsMax=25
scheduledAmpsMax={scheduledAmpsMax}

# Scheduled window start hour in local time, using decimal hours.
# Examples:
#   0.0  -> 00:00
#   0.5  -> 00:30
#   1.5  -> 01:30
#   23.75 -> 23:45
scheduledAmpsStartHour={scheduledAmpsStartHour}

# Scheduled window end hour in local time, using decimal hours.
# If end is lower than start, the schedule crosses midnight.
# Example:
#   start=23.0 and end=7.0 means 23:00 to 07:00
scheduledAmpsEndHour={scheduledAmpsEndHour}

# Scheduled charging days bitmap.
# Bits:
#   bit0=Monday, bit1=Tuesday, ... bit6=Sunday
# Examples:
#   127 -> every day
#   31  -> Monday to Friday
#   96  -> Saturday and Sunday
scheduledAmpsDaysBitmap={scheduledAmpsDaysBitmap}

# Automatically resume green-energy tracking at this local hour.
# Use -1 to disable the automatic resume feature.
# Examples:
#   -1   -> disabled
#   8.0  -> resume at 08:00 every day
hourResumeTrackGreenEnergy={hourResumeTrackGreenEnergy}

# Unix timestamp until which solar telemetry requests are locally suppressed
# after the upstream API asks us to back off. This is runtime state that may be
# updated automatically.
greenEnergyRateLimitedUntil={greenEnergyRateLimitedUntil}

# Accumulated delivered energy counter in kWh.
# This is persistent state, not a tuning parameter. TWCManager updates it over
# time so the value survives restarts.
# Example: 119.0
kWhDelivered={kWhDelivered}

# Home latitude used to decide whether a vehicle is considered "at home".
# It is mainly used when onlyChargeMultiCarsAtHome=true.
# Example for Madrid area: 40.488275
homeLat={homeLat}

# Home longitude used to decide whether a vehicle is considered "at home".
# Example for Madrid area: -3.666271
homeLon={homeLon}

# Tesla API tokens are intentionally not stored in this file.
# Use TeslaApiTokens.json or TESLA_API_* environment variables for secrets.
""".format(
            rs485Adapter=settings['rs485Adapter'],
            baud=int(settings['baud']),
            wiringMaxAmpsAllTWCs=settings['wiringMaxAmpsAllTWCs'],
            wiringMaxAmpsPerTWC=settings['wiringMaxAmpsPerTWC'],
            minAmpsPerTWC=settings['minAmpsPerTWC'],
            minAmpsTWCSupports=settings['minAmpsTWCSupports'],
            greenEnergyAmpsOffset=settings['greenEnergyAmpsOffset'],
            debugLevel=int(settings['debugLevel']),
            displayMilliseconds=format_bool(settings['displayMilliseconds']),
            logFile=settings['logFile'],
            fakeMaster=int(settings['fakeMaster']),
            fakeTWCID=format_hex_bytes(settings['fakeTWCID']),
            masterSign=format_hex_bytes(settings['masterSign']),
            slaveSign=format_hex_bytes(settings['slaveSign']),
            onlyChargeMultiCarsAtHome=format_bool(settings['onlyChargeMultiCarsAtHome']),
            nonScheduledAmpsMax=int(settings['nonScheduledAmpsMax']),
            scheduledAmpsMax=int(settings['scheduledAmpsMax']),
            scheduledAmpsStartHour=settings['scheduledAmpsStartHour'],
            scheduledAmpsEndHour=settings['scheduledAmpsEndHour'],
            scheduledAmpsDaysBitmap=int(settings['scheduledAmpsDaysBitmap']),
            hourResumeTrackGreenEnergy=settings['hourResumeTrackGreenEnergy'],
            greenEnergyRateLimitedUntil=settings['greenEnergyRateLimitedUntil'],
            kWhDelivered=settings['kWhDelivered'],
            homeLat=settings['homeLat'],
            homeLon=settings['homeLon'],
        )


class EnergyHistoryStore:
    """Persist delivered-energy history with solar/grid breakdown."""
    def __init__(self, general_config):
        self.file_name = re.sub(r'/[^/]+$', r'/TWCManagerEnergyHistory.json',
                                general_config.settings_file_name)

    def load(self):
        try:
            fh = open(self.file_name, 'r')
        except FileNotFoundError:
            return {
                'version': 1,
                'last_total_kwh': 0.0,
                'last_update_ts': 0.0,
                'hourly': {},
                'daily': {},
            }

        try:
            payload = json.load(fh)
        except json.JSONDecodeError:
            print(time_now() + ': ERROR: Energy history file contains invalid JSON: ' + self.file_name)
            payload = {}
        finally:
            fh.close()

        if(not isinstance(payload, dict)):
            payload = {}

        hourly = payload.get('hourly', {})
        if(not isinstance(hourly, dict)):
            hourly = {}
        daily = payload.get('daily', {})
        if(not isinstance(daily, dict)):
            daily = {}

        return {
            'version': 1,
            'last_total_kwh': max(0.0, float(payload.get('last_total_kwh', 0.0))),
            'last_update_ts': max(0.0, float(payload.get('last_update_ts', 0.0))),
            'hourly': self._clean_hourly(hourly),
            'daily': self._clean_daily(daily),
        }

    def save(self, history):
        fh = open(self.file_name, 'w')
        try:
            json.dump(history, fh, indent=2, sort_keys=True)
            fh.write('\n')
        finally:
            fh.close()

    def _normalize_bucket(self, value):
        if(isinstance(value, dict)):
            solar = max(0.0, float(value.get('solar', 0.0)))
            grid = max(0.0, float(value.get('grid', 0.0)))
            total = max(0.0, float(value.get('total', solar + grid)))
            if(total < solar + grid):
                total = solar + grid
            return {
                'solar': solar,
                'grid': grid,
                'total': total,
            }

        try:
            total = max(0.0, float(value))
        except (TypeError, ValueError):
            total = 0.0

        return {
            'solar': 0.0,
            'grid': total,
            'total': total,
        }

    def _clean_hourly(self, hourly):
        cleaned = {}
        for key, value in hourly.items():
            if(re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}$', str(key))):
                cleaned[str(key)] = self._normalize_bucket(value)
        return cleaned

    def _clean_daily(self, daily):
        cleaned = {}
        for key, value in daily.items():
            if(re.match(r'^\d{4}-\d{2}-\d{2}$', str(key))):
                cleaned[str(key)] = self._normalize_bucket(value)
        return cleaned

    def record_delta(self, history, start_ts, end_ts, solar_kwh, grid_kwh, total_kwh):
        if(history == None):
            history = self.load()

        start_ts = float(start_ts)
        end_ts = float(end_ts)
        solar_kwh = max(0.0, float(solar_kwh))
        grid_kwh = max(0.0, float(grid_kwh))
        total_kwh = max(0.0, float(total_kwh))
        hourly = dict(history.get('hourly', {}))
        daily = dict(history.get('daily', {}))

        if(end_ts > start_ts and (solar_kwh > 0 or grid_kwh > 0)):
            hourly_allocations = allocate_delta_by_hour(start_ts, end_ts, solar_kwh, grid_kwh)
            for hour_key, values in hourly_allocations.items():
                bucket = self._normalize_bucket(hourly.get(hour_key, {}))
                bucket['solar'] = round(bucket['solar'] + values['solar'], 6)
                bucket['grid'] = round(bucket['grid'] + values['grid'], 6)
                bucket['total'] = round(bucket['solar'] + bucket['grid'], 6)
                hourly[hour_key] = bucket

            daily_allocations = allocate_delta_by_day_breakdown(start_ts, end_ts, solar_kwh, grid_kwh)
            for day_key, values in daily_allocations.items():
                bucket = self._normalize_bucket(daily.get(day_key, {}))
                bucket['solar'] = round(bucket['solar'] + values['solar'], 6)
                bucket['grid'] = round(bucket['grid'] + values['grid'], 6)
                bucket['total'] = round(bucket['solar'] + bucket['grid'], 6)
                daily[day_key] = bucket

        history = {
            'version': 2,
            'last_total_kwh': total_kwh,
            'last_update_ts': end_ts,
            'hourly': prune_hourly_history(hourly),
            'daily': prune_daily_history(daily),
        }
        return history


class GreenEnergyMonitor:
    """Translate external solar telemetry into available charging current."""
    def __init__(self, config, lock, state=None):
        self.config = config
        self.lock = lock
        self.state = state
        self.url = 'https://www.eu.solaxcloud.com:9443/proxy/api/getRealtimeInfo.do?tokenId=202109170145029163555411&sn=SPRBG2GRWG'
        self.rate_limited_until = max(0.0, float(
            getattr(state, 'greenEnergyRateLimitedUntil', 0.0)
        ))

    def _parse_rate_limit_delay(self, payload):
        code = payload.get('code')
        exception_text = str(payload.get('exception', ''))
        match = re.search(r'suspend the request for\s+(\d+)\s+minutes?', exception_text, re.IGNORECASE)
        if(match):
            return max(60, int(match.group(1)) * 60)
        if(code == 104):
            return 60
        return 5 * 60

    def check(self):
        global debugLevel, maxAmpsToDivideAmongSlaves

        now = time.time()
        if(now < self.rate_limited_until):
            return

        try:
            resp = requests.get(self.url, timeout=30)
        except Exception as exc:
            print(time_now() + " ERROR: " + str(exc))
            return

        try:
            greenEnergyData = resp.json()
        except Exception:
            print(time_now() + " ERROR: no json response " + str(resp.raw))
            return

        if(greenEnergyData.get('code') in (3, 104)):
            delay_seconds = self._parse_rate_limit_delay(greenEnergyData)
            self.rate_limited_until = max(self.rate_limited_until, now + delay_seconds)
            if(self.state != None):
                self.state.greenEnergyRateLimitedUntil = self.rate_limited_until
                save_settings(self.state)
            print(time_now() + " WARNING: Solar telemetry API rate limit reached. "
                  + "Pausing new telemetry requests for %d seconds." % delay_seconds)
            return

        if(greenEnergyData.get('exception') != "Query success!"):
            print(time_now() +
                " ERROR: Can't determine current solar generation from:\n" +
                str(greenEnergyData))
            return

        try:
            solarW = int(greenEnergyData['result']['feedinpower'])
        except (KeyError, TypeError, ValueError):
            print(time_now() +
                " ERROR: Invalid solar telemetry payload:\n" +
                str(greenEnergyData))
            return

        if(solarW < 0):
            solarW = 0

        self.rate_limited_until = 0
        if(self.state != None and self.state.greenEnergyRateLimitedUntil != 0):
            self.state.greenEnergyRateLimitedUntil = 0
            save_settings(self.state)

        with self.lock:
            maxAmpsToDivideAmongSlaves = int(solarW / 240) + self.config.green_energy_amps_offset
            if(self.state != None):
                self.state.maxAmpsToDivideAmongSlaves = maxAmpsToDivideAmongSlaves
                self.state.greenEnergyAvailableAmps = max(0.0, float(maxAmpsToDivideAmongSlaves))
            if (debugLevel >= 2):
                print("%s: Solar generating %dW so limit car charging to:\n" \
                     "          %.2fA + %.2fA = %.2fA.  Charge when above %.0fA (minAmpsPerTWC)." % \
                     (time_now(), solarW, (solarW / 240),
                     self.config.green_energy_amps_offset, maxAmpsToDivideAmongSlaves,
                     self.config.min_amps_per_twc))


class BackgroundTaskRunner:
    """Serialize slow side effects outside the RS-485 polling loop."""
    def __init__(self, car_api_available_fn, car_api_charge_fn, green_energy_fn,
                 lock=None):
        self.car_api_available_fn = car_api_available_fn
        self.car_api_charge_fn = car_api_charge_fn
        self.green_energy_fn = green_energy_fn
        self.lock = lock if lock is not None else threading.Lock()
        self.queue = queue.Queue()
        self.cmds = {}
        self.thread = threading.Thread(target=self.run_forever, args=())
        self.thread.daemon = True

    def start(self):
        self.thread.start()

    def queue_task(self, task):
        if(task['cmd'] in self.cmds):
            # Some tasks, like cmd='charge', will be called once per second until
            # a charge starts or we determine the car is done charging. To avoid
            # wasting memory queueing up a bunch of these tasks when we're
            # handling a charge cmd already, don't queue two of the same task.
            return False

        self.cmds[task['cmd']] = True
        self.queue.put(task)
        return True

    def run_forever(self):
        while True:
            task = self.queue.get()

            try:
                if(task['cmd'] == 'charge'):
                    self.car_api_charge_fn(task['charge'])
                elif(task['cmd'] == 'carApiTokens'):
                    if(tesla_car_api != None):
                        tesla_car_api.last_error_time = 0
                        tesla_car_api.import_tokens(task['tokens'])
                elif(task['cmd'] == 'checkGreenEnergy'):
                    self.green_energy_fn()
            except Exception:
                print(time_now() + ': ERROR: Background task failed: ' + str(task))
                traceback.print_exc()
            finally:
                self.cmds.pop(task['cmd'], None)
                self.queue.task_done()


class TeslaCarApi:
    """Tesla Fleet API client used for wake and charge commands."""
    auth_url = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token'
    default_audience = 'https://fleet-api.prd.eu.vn.cloud.tesla.com'
    default_fleet_api_base_url = 'https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1'

    def __init__(self, config=None, token_store=None):
        self.config = config if config != None else TeslaApiConfig(
            only_charge_multi_cars_at_home=onlyChargeMultiCarsAtHome,
            home_lat=homeLat,
            home_lon=homeLon,
        )
        self.token_store = token_store
        self.last_error_time = 0
        self.bearer_token = ''
        self.refresh_token = ''
        self.token_expire_time = time.time()
        self.last_start_or_stop_charge_time = 0
        self.vehicles = []
        self.transient_errors = ['upstream internal error', 'operation_timedout',
                                 'vehicle unavailable']
        self.error_retry_mins = 10
        self.audience = os.environ.get('TESLA_API_AUDIENCE', self.default_audience).strip()
        self.client_id = os.environ.get('TESLA_API_CLIENT_ID', '').strip()
        fleet_api_base_url = os.environ.get(
            'TESLA_FLEET_API_BASE_URL',
            self.default_fleet_api_base_url
        ).strip()
        self.fleet_api_base_url = self.normalize_fleet_api_base_url(fleet_api_base_url)

    def normalize_fleet_api_base_url(self, value):
        value = str(value).strip().rstrip('/')
        if(value.endswith('/api/1')):
            return value
        return value + '/api/1'

    def has_tokens(self):
        return (self.refresh_token != '' or self.bearer_token != '')

    def load_tokens(self):
        if(self.token_store == None):
            return False

        return self.import_tokens(self.token_store.load(), save=False)

    def import_tokens(self, tokens, save=True):
        if(type(tokens) != dict or len(tokens) == 0):
            return False

        access_token = str(tokens.get('access_token', '')).strip()
        refresh_token = str(tokens.get('refresh_token', '')).strip()
        expires_at = tokens.get('expires_at', 0)

        try:
            expires_at = float(expires_at)
        except (TypeError, ValueError):
            expires_at = 0

        if(access_token == '' and refresh_token == ''):
            print(time_now() + ': ERROR: Token payload must include access_token or refresh_token.')
            return False

        audience = str(tokens.get('audience', '')).strip()
        client_id = str(tokens.get('client_id', '')).strip()
        fleet_api_base_url = str(tokens.get('fleet_api_base_url', '')).strip()
        if(audience != ''):
            self.audience = audience
        if(client_id != ''):
            self.client_id = client_id
        if(fleet_api_base_url != ''):
            self.fleet_api_base_url = self.normalize_fleet_api_base_url(fleet_api_base_url)
        elif(self.audience == 'https://fleet-api.prd.eu.vn.cloud.tesla.com'):
            self.fleet_api_base_url = self.normalize_fleet_api_base_url(self.audience)
        elif(self.audience == 'https://fleet-api.prd.na.vn.cloud.tesla.com'):
            self.fleet_api_base_url = self.normalize_fleet_api_base_url(self.audience)

        self.bearer_token = access_token
        self.refresh_token = refresh_token
        if(expires_at > 0):
            self.token_expire_time = expires_at
        elif(access_token != '' and refresh_token == ''):
            self.token_expire_time = time.time() + 8*60*60
        else:
            self.token_expire_time = time.time()
        self.last_error_time = 0

        if(save and self.token_store != None):
            self.persist_tokens()

        if(debugLevel >= 1):
            print(time_now() + ': Tesla API tokens imported. ' +
                  ('Refresh token available.' if self.refresh_token != '' else 'Access token only.') +
                  ' Fleet API base=' + self.fleet_api_base_url)
        return True

    def persist_tokens(self):
        if(self.token_store != None):
            self.token_store.save({
                'access_token': self.bearer_token,
                'refresh_token': self.refresh_token,
                'expires_at': self.token_expire_time,
                'audience': self.audience,
                'client_id': self.client_id,
                'fleet_api_base_url': self.fleet_api_base_url,
            })

    def auth_headers(self):
        return {
            'accept': 'application/json',
            'Authorization': 'Bearer ' + self.bearer_token,
        }

    def request_json(self, method, url, payload=None, headers=None):
        request_headers = {'accept': 'application/json'}
        if(headers != None):
            request_headers.update(headers)

        try:
            if(method == 'POST'):
                resp = requests.post(url, json=payload, headers=request_headers, timeout=60)
            else:
                resp = requests.get(url, headers=request_headers, timeout=60)
        except Exception as exc:
            if(debugLevel >= 1):
                print(time_now() + ': ERROR: Tesla API request failed: ' + str(exc))
            return {}

        try:
            response_json = resp.json()
        except ValueError:
            if(debugLevel >= 1):
                print(time_now() + ': ERROR: Tesla API returned non-JSON response from ' + url)
            return {}

        if(debugLevel >= 4):
            print(time_now() + ': Tesla API response from ' + url + ':', response_json, '\n')

        return response_json

    def describe_response(self, payload):
        try:
            return json.dumps(payload, sort_keys=True)
        except Exception:
            return str(payload)

    def refresh_access_token(self):
        now = time.time()

        if(self.refresh_token == ''):
            print(time_now() + ': ERROR: Tesla API requires an imported refresh token. '
                  'Generate it externally and import it via token file, environment or web UI.')
            self.last_error_time = now
            self.token_expire_time = now + timedelta(days=10).total_seconds()
            return False

        payload = {
            'grant_type': 'refresh_token',
            'refresh_token': self.refresh_token,
            'audience': self.audience,
        }
        if(self.client_id != ''):
            payload['client_id'] = self.client_id
        try:
            resp = requests.post(
                self.auth_url,
                data=payload,
                headers={
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                },
                timeout=60,
            )
            apiResponseDict = resp.json()
        except Exception as exc:
            print(time_now() + ': ERROR: Failed to refresh Tesla Fleet API token: ' + str(exc))
            self.last_error_time = now
            self.token_expire_time = now + timedelta(days=10).total_seconds()
            return False

        try:
            self.bearer_token = apiResponseDict['access_token']
            self.refresh_token = apiResponseDict.get('refresh_token', self.refresh_token)
            self.token_expire_time = now + float(apiResponseDict['expires_in'])
        except (KeyError, TypeError, ValueError):
            print(time_now() + ': ERROR: Failed to refresh Tesla API token. '
                  'Import a new token set generated with MFA.')
            self.last_error_time = now
            self.token_expire_time = now + timedelta(days=10).total_seconds()
            return False

        self.persist_tokens()
        return True

    def available(self, charge = None):
        global debugLevel

        now             = time.time()
        apiResponseDict = {}
        needSleep       = False
        has_ready_vehicle = False

        if (now - self.last_error_time < self.error_retry_mins*60):
            if (debugLevel >= 11):
                print(time_now() + ': Car API disabled for ' +
                      str(int(self.error_retry_mins*60 - (now - self.last_error_time))) +
                      ' more seconds due to recent error.')
            return False

        token_needs_refresh = (
            self.refresh_token != '' and self.token_expire_time - now < 5*60
        )
        token_expired_without_refresh = (
            self.refresh_token == '' and self.bearer_token != '' and self.token_expire_time <= now
        )

        if(self.bearer_token == '' or token_needs_refresh or token_expired_without_refresh):
            if(self.refresh_access_token() == False):
                return False

        if (self.bearer_token != ''):
            if (len(self.vehicles) < 1):
                apiResponseDict = self.request_json(
                    'GET',
                    self.fleet_api_base_url + '/vehicles',
                    headers=self.auth_headers()
                )

                try:
                    vehicle_list = apiResponseDict.get('response', [])
                    if(type(vehicle_list) == dict and 'vehicles' in vehicle_list):
                        vehicle_list = vehicle_list.get('vehicles', [])
                    if(type(vehicle_list) != list):
                        raise TypeError

                    self.vehicles = []
                    for vehicle_payload in vehicle_list:
                        vin = str(vehicle_payload.get('vin', '')).strip()
                        if(vin == ''):
                            continue
                        self.vehicles.append(CarApiVehicle(vin, self))

                except (AttributeError, KeyError, TypeError):
                    print(time_now() + ": ERROR: Can't get list of vehicles via Tesla Fleet API from "
                          + self.fleet_api_base_url + '/vehicles'
                          + ". Will try again in " + str(self.error_retry_mins)
                          + " minutes. Response: " + self.describe_response(apiResponseDict))

                    self.last_error_time = now
                    return False

            if (len(self.vehicles) > 0):
                for vehicle in self.vehicles:
                    if (charge == True and vehicle.stopAskingToStartCharging):
                        if (debugLevel >= 8):
                            print(time_now() + ": Don't charge vehicle " + str(vehicle.ID)
                                  + " because vehicle.stopAskingToStartCharging == True")
                        continue

                    if (now - vehicle.lastErrorTime < self.error_retry_mins*60):
                        if (debugLevel >= 8):
                            print(time_now() + ": Don't send commands to vehicle " + str(vehicle.ID)
                                  + " because it returned an error in the last "
                                  + str(self.error_retry_mins) + " minutes.")
                        continue

                    if (vehicle.ready()):
                        has_ready_vehicle = True
                        continue

                    if (now - vehicle.lastWakeAttemptTime <= vehicle.delayNextWakeAttempt):
                        if(debugLevel >= 10):
                            print(time_now() + ": Skip wake for vehicle " + str(vehicle.ID) + " because we are still delaying "
                                  + str(vehicle.delayNextWakeAttempt) + " seconds after the last failed wake attempt.")
                        continue

                    vehicle.lastWakeAttemptTime = now
                    apiResponseDict = self.request_json(
                        'POST',
                        self.fleet_api_base_url + '/vehicles/' + str(vehicle.ID) + '/wake_up',
                        headers=self.auth_headers()
                    )

                    state = 'error'

                    try:
                        if (debugLevel >= 4):
                            print(time_now() + ': Car API wake car response', apiResponseDict, '\n')

                        state = apiResponseDict['response']['state']

                    except (KeyError, TypeError):
                        state = 'error'

                    if (state == 'online'):
                        vehicle.firstWakeAttemptTime = 0
                        vehicle.delayNextWakeAttempt = 0
                        has_ready_vehicle = True
                        needSleep = True
                    else:
                        if (vehicle.firstWakeAttemptTime == 0):
                            vehicle.firstWakeAttemptTime = now

                        if (state == 'asleep' or state == 'waking'):
                            if (now - vehicle.firstWakeAttemptTime <= 10*60):
                                vehicle.delayNextWakeAttempt = 30
                            elif (now - vehicle.firstWakeAttemptTime <= 70*60):
                                vehicle.delayNextWakeAttempt = 5*60
                            else:
                                vehicle.delayNextWakeAttempt = 15*60
                        elif (state == 'offline'):
                            if(now - vehicle.firstWakeAttemptTime <= 31*60):
                                vehicle.delayNextWakeAttempt = 25
                        else:
                            if (now - vehicle.firstWakeAttemptTime <= 60*60):
                                foundKnownError = False
                                if('error' in apiResponseDict):
                                    error = apiResponseDict['error']
                                    for knownError in self.transient_errors:
                                        if(knownError == error[0:len(knownError)]):
                                            foundKnownError = True
                                            break

                                if (foundKnownError):
                                    vehicle.delayNextWakeAttempt = 60
                                else:
                                    vehicle.delayNextWakeAttempt = 5*60
                            else:
                                vehicle.delayNextWakeAttempt = 15*60

                        if (debugLevel >= 1):
                            if(state == 'error'):
                                print(time_now() + ": Car API wake car failed with unknown response.  " \
                                    "Will try again in "
                                    + str(vehicle.delayNextWakeAttempt) + " seconds.")
                            else:
                                print(time_now() + ": Car API wake car failed.  State remains: '"
                                    + state + "'.  Will try again in "
                                    + str(vehicle.delayNextWakeAttempt) + " seconds.")

                    if (vehicle.firstWakeAttemptTime > 0
                        and now - vehicle.firstWakeAttemptTime > 60*60):
                        print(time_now() + ": ERROR: We have failed to wake a car from '"
                            + state + "' state for %.1f hours.\n" \
                              "Please private message user CDragon at " \
                              "http://teslamotorsclub.com with a copy of this error. " \
                              "Also include this: %s" % (
                              ((now - vehicle.firstWakeAttemptTime) / 60 / 60),
                              str(apiResponseDict)))
            else:
                if(debugLevel >= 8):
                    print(time_now() + ": car_api_available returning False because no vehicles were discovered.")

        if (now - self.last_error_time < self.error_retry_mins*60 or self.bearer_token == ''):
            if (debugLevel >= 8):
                print(time_now() + ": car_api_available returning False because of recent carApiLasterrorTime "
                    + str(now - self.last_error_time) + " or empty carApiBearerToken '"
                    + self.bearer_token + "'")
            return False

        if (len(self.vehicles) > 0 and has_ready_vehicle == False):
            if (debugLevel >= 8):
                print(time_now() + ": car_api_available returning False because no vehicle is currently ready.")
            return False

        if (debugLevel >= 8):
            print(time_now() + ": car_api_available returning True")

        if (needSleep):
            time.sleep(5)

        return True

    def charge(self, charge):
        global debugLevel

        now             = time.time()
        apiResponseDict = {}
        sentCommand     = False

        if (not charge):
            for vehicle in self.vehicles:
                vehicle.stopAskingToStartCharging = False

        if (now - self.last_start_or_stop_charge_time < 60):
            if (debugLevel >= 8):
                print(time_now() + ': car_api_charge return because under 60 sec since last carApiLastStartOrStopChargeTime')

            return 'error'

        if (self.available(charge = charge) == False):
            if (debugLevel >= 8):
                print(time_now() + ': car_api_charge return because car_api_available() == False')

            return 'error'

        startOrStop = 'start' if charge else 'stop'
        result      = 'success'

        for vehicle in self.vehicles:
            if (charge and vehicle.stopAskingToStartCharging):
                if (debugLevel >= 8):
                    print(time_now() + ": Don't charge vehicle " + str(vehicle.ID)
                          + " because vehicle.stopAskingToStartCharging == True")

                continue

            if (vehicle.ready() == False):
                continue

            if (self.config.only_charge_multi_cars_at_home and len(self.vehicles) > 1):
                if (vehicle.update_location() == False):
                    result = 'error'
                    continue

                if (self.config.home_lat == 10000):
                    if (debugLevel >= 1):
                        print(time_now() + ": Home location for vehicles has never been set.  " +
                            "We'll assume home is where we found the first vehicle currently parked.  " +
                            "Home set to lat=" + str(vehicle.lat) + ", lon=" +
                            str(vehicle.lon))

                    self.config.home_lat = vehicle.lat
                    self.config.home_lon = vehicle.lon
                    save_settings(api=self, api_config=self.config)

                if (abs(self.config.home_lat - vehicle.lat) > 0.097
                   or abs(self.config.home_lon - vehicle.lon) > 0.097):
                    if (debugLevel >= 1):
                        print(time_now() + ': Vehicle ID ' + str(vehicle.ID) +
                              ' is not at home.  Do not ' + startOrStop + ' charge.')

                    continue

                time.sleep(5)

            for retryCount in range(0, 3):
                self.last_start_or_stop_charge_time = time.time()
                sentCommand = True
                if (debugLevel >= 1):
                    print(time_now() + ': Sending Tesla API charge_' + startOrStop
                          + ' to vehicle ' + str(vehicle.ID) + '.')
                apiResponseDict = self.request_json(
                    'POST',
                    self.fleet_api_base_url + '/vehicles/' + str(vehicle.ID) + '/command/charge_' + startOrStop,
                    headers=self.auth_headers()
                )

                try:
                    if (apiResponseDict['response'] == None):
                        if ('error' in apiResponseDict):
                            foundKnownError = False
                            error           = apiResponseDict['error']

                            for knownError in self.transient_errors:
                                if (knownError == error[0:len(knownError)]):
                                    if (debugLevel >= 1):
                                        print(time_now() + ": Car API returned '"
                                              + error
                                              + "' when trying to start charging.  Try again in 1 minute.")
                                    time.sleep(60)
                                    foundKnownError = True
                                    break

                            if (foundKnownError):
                                continue

                        print(time_now() + ': ++Car API ' + startOrStop + \
                              ' charge response', apiResponseDict, '\n')

                        result                = 'error'
                        vehicle.lastErrorTime = now
                    elif (apiResponseDict['response']['result'] == False):
                        reason = apiResponseDict['response'].get('reason', 'unknown')

                        if (charge):
                            if (reason == 'complete' or reason == 'charging'):
                                if (debugLevel >= 1):
                                    print(time_now() + ': Vehicle ' + str(vehicle.ID)
                                          + ' is done charging or already trying to charge.  Stop asking to start charging.')

                                vehicle.stopAskingToStartCharging = True
                            else:
                                if (reason == 'could_not_wake_buses'):
                                    time.sleep(5)
                                    continue
                                elif (reason == 'requested'):
                                    time.sleep(120)
                                    continue
                                else:
                                    print(time_now() + ': ERROR "' + reason + '" when trying to ' +
                                          startOrStop + ' car charging via Tesla car API.  Will try again later.' +
                                          "\nIf this error persists, please private message user CDragon at http://teslamotorsclub.com " \
                                          "with a copy of this error.")
                                    result = 'error'
                                    vehicle.lastErrorTime = now
                        else:
                            if (reason == 'could_not_wake_buses'):
                                time.sleep(5)
                                continue
                            elif (reason == 'not_charging'):
                                if (debugLevel >= 1):
                                    print(time_now() + ': Vehicle ' + str(vehicle.ID)
                                          + ' is already not charging.')
                            else:
                                print(time_now() + ': ERROR "' + reason + '" when trying to ' +
                                      startOrStop + ' car charging via Tesla car API.  Will try again later.' +
                                      "\nIf this error persists, please private message user CDragon at http://teslamotorsclub.com " \
                                      "with a copy of this error.")
                                result = 'error'
                                vehicle.lastErrorTime = now
                    else:
                        if (debugLevel >= 1):
                            print(time_now() + ': Tesla API charge_' + startOrStop
                                  + ' accepted for vehicle ' + str(vehicle.ID) + '.')
                        break

                except (KeyError, TypeError):
                    print(time_now() + ': ERROR: Failed to ' + startOrStop
                          + ' car charging via Tesla car API.  Will try again later.')
                    result = 'error'
                    vehicle.lastErrorTime = now
                    break

        if (debugLevel >= 1 and sentCommand):
            print(time_now() + ': Car API ' + startOrStop + ' charge result: ' + result)

        return result


class RS485Transport:
    """Thin wrapper around ``pyserial`` to isolate bus access."""
    def __init__(self, config):
        self.config = config
        self.serial = serial.Serial(config.adapter, config.baud_rate, timeout=config.timeout)

    def write(self, msg):
        self.serial.write(msg)

    def in_waiting(self):
        return self.serial.inWaiting()

    def read(self, data_len):
        return self.serial.read(data_len)

    def close(self):
        self.serial.close()


class WebIPCServer:
    """SysV message queue endpoint used by the companion web interface."""
    def __init__(self, script_path):
        self.key = sysv_ipc.ftok(re.sub('/[^/]+$', '/', script_path), ord('T'), True)
        self.queue = sysv_ipc.MessageQueue(self.key, sysv_ipc.IPC_CREAT, 0o660)

    def receive(self):
        return self.queue.receive(False, 2)

    def send(self, payload, block=False):
        self.queue.send(payload, block=block)


def configure_log_output(general_config=None):
    global logFileName, log_output_stream

    target_file = logFileName
    if(general_config != None):
        target_file = general_config.log_file

    if(target_file == None):
        target_file = ''
    target_file = str(target_file).strip()

    if(target_file == ''):
        if(log_output_stream != None):
            try:
                log_output_stream.close()
            except OSError:
                pass
            log_output_stream = None
        sys.stdout = original_stdout
        sys.stderr = original_stderr
        if(general_config != None):
            general_config.log_file = ''
        logFileName = ''
        return

    try:
        log_stream = open(target_file, 'a', buffering=1)
    except OSError as exc:
        sys.stdout = original_stdout
        sys.stderr = original_stderr
        print(time_now() + ': ERROR: Cannot open log file ' + target_file
              + '. Logging will continue on the console. ' + str(exc),
              file=original_stderr)
        if(log_output_stream != None):
            try:
                log_output_stream.close()
            except OSError:
                pass
            log_output_stream = None
        if(general_config != None):
            general_config.log_file = ''
        logFileName = ''
        return

    if(log_output_stream != None):
        try:
            log_output_stream.close()
        except OSError:
            pass

    log_output_stream = log_stream
    sys.stdout = log_stream
    sys.stderr = log_stream
    if(general_config != None):
        general_config.log_file = target_file
    logFileName = target_file


def parse_web_ipc_message(raw_msg):
    """Return (command, metadata) from an IPC payload sent by the web UI."""
    metadata = {}
    command = raw_msg

    if(raw_msg[0:9] == b'__meta__='):
        newline_pos = raw_msg.find(b'\n')
        if(newline_pos != -1):
            meta_payload = raw_msg[9:newline_pos]
            command = raw_msg[newline_pos + 1:]
            try:
                decoded = json.loads(meta_payload.decode('utf-8'))
                if(isinstance(decoded, dict)):
                    metadata = decoded
            except (UnicodeDecodeError, json.JSONDecodeError):
                metadata = {}

    return command, metadata


def load_settings(state=None, api=None, api_config=None, general_config=None,
                  energy_config=None, rs485_config=None):
    global debugLevel, displayMilliseconds, fakeMaster, fakeTWCID, masterSign, \
           slaveSign, rs485Adapter, baud, wiringMaxAmpsAllTWCs, \
           wiringMaxAmpsPerTWC, minAmpsPerTWC, minAmpsTWCSupports, \
           greenEnergyAmpsOffset, onlyChargeMultiCarsAtHome, settingsFileName, \
           logFileName, \
           nonScheduledAmpsMax, scheduledAmpsMax, \
           scheduledAmpsStartHour, scheduledAmpsEndHour, \
           scheduledAmpsDaysBitmap, hourResumeTrackGreenEnergy, \
           greenEnergyRateLimitedUntil, kWhDelivered, \
           homeLat, homeLon

    if(settings_store == None):
        return

    settings = settings_store.load()
    rs485Adapter = settings.get('rs485Adapter', rs485Adapter)
    baud = settings.get('baud', baud)
    wiringMaxAmpsAllTWCs = settings.get('wiringMaxAmpsAllTWCs', wiringMaxAmpsAllTWCs)
    wiringMaxAmpsPerTWC = settings.get('wiringMaxAmpsPerTWC', wiringMaxAmpsPerTWC)
    minAmpsPerTWC = settings.get('minAmpsPerTWC', minAmpsPerTWC)
    minAmpsTWCSupports = settings.get('minAmpsTWCSupports', minAmpsTWCSupports)
    greenEnergyAmpsOffset = settings.get('greenEnergyAmpsOffset', greenEnergyAmpsOffset)
    debugLevel = settings.get('debugLevel', debugLevel)
    displayMilliseconds = settings.get('displayMilliseconds', displayMilliseconds)
    logFileName = settings.get('logFile', logFileName)
    fakeMaster = settings.get('fakeMaster', fakeMaster)
    fakeTWCID = settings.get('fakeTWCID', fakeTWCID)
    masterSign = settings.get('masterSign', masterSign)
    slaveSign = settings.get('slaveSign', slaveSign)
    onlyChargeMultiCarsAtHome = settings.get('onlyChargeMultiCarsAtHome', onlyChargeMultiCarsAtHome)
    nonScheduledAmpsMax = settings.get('nonScheduledAmpsMax', nonScheduledAmpsMax)
    scheduledAmpsMax = settings.get('scheduledAmpsMax', scheduledAmpsMax)
    scheduledAmpsStartHour = settings.get('scheduledAmpsStartHour', scheduledAmpsStartHour)
    scheduledAmpsEndHour = settings.get('scheduledAmpsEndHour', scheduledAmpsEndHour)
    scheduledAmpsDaysBitmap = settings.get('scheduledAmpsDaysBitmap', scheduledAmpsDaysBitmap)
    hourResumeTrackGreenEnergy = settings.get('hourResumeTrackGreenEnergy', hourResumeTrackGreenEnergy)
    greenEnergyRateLimitedUntil = settings.get('greenEnergyRateLimitedUntil', greenEnergyRateLimitedUntil)
    kWhDelivered = settings.get('kWhDelivered', kWhDelivered)
    homeLat = settings.get('homeLat', homeLat)
    homeLon = settings.get('homeLon', homeLon)
    if(general_config != None):
        general_config.debug_level = debugLevel
        general_config.display_milliseconds = displayMilliseconds
        general_config.log_file = logFileName
        general_config.fake_master = fakeMaster
        general_config.fake_twc_id = fakeTWCID
        general_config.master_sign = masterSign
        general_config.slave_sign = slaveSign
    if(energy_config != None):
        energy_config.wiring_max_amps_all_twcs = wiringMaxAmpsAllTWCs
        energy_config.wiring_max_amps_per_twc = wiringMaxAmpsPerTWC
        energy_config.min_amps_per_twc = minAmpsPerTWC
        energy_config.min_amps_twc_supports = minAmpsTWCSupports
        energy_config.green_energy_amps_offset = greenEnergyAmpsOffset
    if(rs485_config != None):
        rs485_config.adapter = rs485Adapter
        rs485_config.baud_rate = baud
    if(state != None):
        state.nonScheduledAmpsMax = nonScheduledAmpsMax
        state.scheduledAmpsMax = scheduledAmpsMax
        state.scheduledAmpsStartHour = scheduledAmpsStartHour
        state.scheduledAmpsEndHour = scheduledAmpsEndHour
        state.scheduledAmpsDaysBitmap = scheduledAmpsDaysBitmap
        state.hourResumeTrackGreenEnergy = hourResumeTrackGreenEnergy
        state.greenEnergyRateLimitedUntil = greenEnergyRateLimitedUntil
        state.kWhDelivered = kWhDelivered
    if(api_config != None):
        api_config.home_lat = homeLat
        api_config.home_lon = homeLon

def save_settings(state=None, api=None, api_config=None):
    global debugLevel, displayMilliseconds, fakeMaster, fakeTWCID, masterSign, \
           slaveSign, rs485Adapter, baud, wiringMaxAmpsAllTWCs, \
           wiringMaxAmpsPerTWC, minAmpsPerTWC, minAmpsTWCSupports, \
           greenEnergyAmpsOffset, onlyChargeMultiCarsAtHome, settingsFileName, \
           logFileName, \
           nonScheduledAmpsMax, scheduledAmpsMax, \
           scheduledAmpsStartHour, scheduledAmpsEndHour, \
           scheduledAmpsDaysBitmap, hourResumeTrackGreenEnergy, \
           greenEnergyRateLimitedUntil, kWhDelivered, \
           homeLat, homeLon

    if(settings_store == None):
        return

    if(state != None):
        nonScheduledAmpsMax = state.nonScheduledAmpsMax
        scheduledAmpsMax = state.scheduledAmpsMax
        scheduledAmpsStartHour = state.scheduledAmpsStartHour
        scheduledAmpsEndHour = state.scheduledAmpsEndHour
        scheduledAmpsDaysBitmap = state.scheduledAmpsDaysBitmap
        hourResumeTrackGreenEnergy = state.hourResumeTrackGreenEnergy
        greenEnergyRateLimitedUntil = state.greenEnergyRateLimitedUntil
        kWhDelivered = state.kWhDelivered
    if(api == None):
        api = tesla_car_api
    if(api_config == None and api != None and hasattr(api, 'config')):
        api_config = api.config
    if(api_config != None):
        homeLat = api_config.home_lat
        homeLon = api_config.home_lon
    settings_store.save({
        'rs485Adapter': rs485Adapter,
        'baud': baud,
        'wiringMaxAmpsAllTWCs': wiringMaxAmpsAllTWCs,
        'wiringMaxAmpsPerTWC': wiringMaxAmpsPerTWC,
        'minAmpsPerTWC': minAmpsPerTWC,
        'minAmpsTWCSupports': minAmpsTWCSupports,
        'greenEnergyAmpsOffset': greenEnergyAmpsOffset,
        'debugLevel': debugLevel,
        'displayMilliseconds': displayMilliseconds,
        'logFile': logFileName,
        'fakeMaster': fakeMaster,
        'fakeTWCID': fakeTWCID,
        'masterSign': masterSign,
        'slaveSign': slaveSign,
        'onlyChargeMultiCarsAtHome': onlyChargeMultiCarsAtHome,
        'nonScheduledAmpsMax': nonScheduledAmpsMax,
        'scheduledAmpsMax': scheduledAmpsMax,
        'scheduledAmpsStartHour': scheduledAmpsStartHour,
        'scheduledAmpsEndHour': scheduledAmpsEndHour,
        'scheduledAmpsDaysBitmap': scheduledAmpsDaysBitmap,
        'hourResumeTrackGreenEnergy': hourResumeTrackGreenEnergy,
        'greenEnergyRateLimitedUntil': greenEnergyRateLimitedUntil,
        'kWhDelivered': kWhDelivered,
        'homeLat': homeLat,
        'homeLon': homeLon,
    })

def trim_pad(s:bytearray, makeLen):
    # Trim or pad s with zeros so that it's makeLen length.
    while(len(s) < makeLen):
        s += b'\x00'

    if(len(s) > makeLen):
        s = s[0:makeLen]

    return s


def prune_daily_history(daily, keep_days=400):
    items = sorted(daily.items())
    if(len(items) <= keep_days):
        return dict(items)
    return dict(items[-keep_days:])


def prune_hourly_history(hourly, keep_hours=24 * 45):
    items = sorted(hourly.items())
    if(len(items) <= keep_hours):
        return dict(items)
    return dict(items[-keep_hours:])


def allocate_breakdown(start_ts, end_ts, solar_kwh, grid_kwh, bucket_format, step):
    allocations = {}
    if((solar_kwh <= 0 and grid_kwh <= 0) or end_ts < start_ts):
        return allocations

    start_dt = datetime.fromtimestamp(start_ts)
    end_dt = datetime.fromtimestamp(end_ts)
    total_seconds = max(end_ts - start_ts, 1.0)
    cursor = start_dt

    while cursor < end_dt:
        segment_end = min(step(cursor), end_dt)
        segment_seconds = max((segment_end - cursor).total_seconds(), 0.0)
        bucket_key = cursor.strftime(bucket_format)
        if(bucket_key not in allocations):
            allocations[bucket_key] = {'solar': 0.0, 'grid': 0.0, 'total': 0.0}
        allocations[bucket_key]['solar'] += solar_kwh * (segment_seconds / total_seconds)
        allocations[bucket_key]['grid'] += grid_kwh * (segment_seconds / total_seconds)
        allocations[bucket_key]['total'] = allocations[bucket_key]['solar'] + allocations[bucket_key]['grid']
        cursor = segment_end

    if(len(allocations) == 0):
        allocations[start_dt.strftime(bucket_format)] = {
            'solar': solar_kwh,
            'grid': grid_kwh,
            'total': solar_kwh + grid_kwh,
        }

    return allocations


def allocate_delta_by_day_breakdown(start_ts, end_ts, solar_kwh, grid_kwh):
    return allocate_breakdown(
        start_ts,
        end_ts,
        solar_kwh,
        grid_kwh,
        '%Y-%m-%d',
        lambda cursor: (cursor + timedelta(days=1)).replace(hour=0, minute=0, second=0, microsecond=0),
    )


def allocate_delta_by_hour(start_ts, end_ts, solar_kwh, grid_kwh):
    return allocate_breakdown(
        start_ts,
        end_ts,
        solar_kwh,
        grid_kwh,
        '%Y-%m-%dT%H',
        lambda cursor: (cursor + timedelta(hours=1)).replace(minute=0, second=0, microsecond=0),
    )


def calculate_energy_periods(history, now_ts=None):
    if(now_ts == None):
        now_ts = time.time()

    now_dt = datetime.fromtimestamp(now_ts)
    daily = history.get('daily', {}) if history != None else {}
    today_key = now_dt.strftime('%Y-%m-%d')
    iso_year, iso_week, _ = now_dt.isocalendar()
    current_month = now_dt.strftime('%Y-%m')
    current_year = now_dt.strftime('%Y')

    periods = {
        'today': 0.0,
        'week': 0.0,
        'month': 0.0,
        'year': 0.0,
    }

    for day_key, value in daily.items():
        try:
            day_dt = datetime.strptime(day_key, '%Y-%m-%d')
            if(isinstance(value, dict)):
                amount = max(0.0, float(value.get('total', value.get('solar', 0.0) + value.get('grid', 0.0))))
            else:
                amount = max(0.0, float(value))
        except (TypeError, ValueError):
            continue

        if(day_key == today_key):
            periods['today'] += amount
        if(day_dt.strftime('%Y-%m') == current_month):
            periods['month'] += amount
        if(day_dt.strftime('%Y') == current_year):
            periods['year'] += amount

        day_iso_year, day_iso_week, _ = day_dt.isocalendar()
        if(day_iso_year == iso_year and day_iso_week == iso_week):
            periods['week'] += amount

    return periods


def build_energy_chart_payload(history, now_ts=None):
    if(now_ts == None):
        now_ts = time.time()

    now_dt = datetime.fromtimestamp(now_ts)
    hourly = history.get('hourly', {}) if history != None else {}
    daily = history.get('daily', {}) if history != None else {}

    today_labels = []
    today_solar = []
    today_grid = []
    for hour in range(24):
        dt = now_dt.replace(hour=hour, minute=0, second=0, microsecond=0)
        key = dt.strftime('%Y-%m-%dT%H')
        bucket = hourly.get(key, {})
        today_labels.append(dt.strftime('%H:00'))
        today_solar.append(round(float(bucket.get('solar', 0.0)), 3))
        today_grid.append(round(float(bucket.get('grid', 0.0)), 3))

    week_labels = []
    week_solar = []
    week_grid = []
    week_start = (now_dt - timedelta(days=now_dt.weekday())).replace(hour=0, minute=0, second=0, microsecond=0)
    for offset in range(7):
        dt = week_start + timedelta(days=offset)
        key = dt.strftime('%Y-%m-%d')
        bucket = daily.get(key, {})
        week_labels.append(dt.strftime('%a'))
        week_solar.append(round(float(bucket.get('solar', 0.0)), 3))
        week_grid.append(round(float(bucket.get('grid', 0.0)), 3))

    month_labels = []
    month_solar = []
    month_grid = []
    month_start = (now_dt - timedelta(days=29)).replace(hour=0, minute=0, second=0, microsecond=0)
    for offset in range(30):
        dt = month_start + timedelta(days=offset)
        key = dt.strftime('%Y-%m-%d')
        bucket = daily.get(key, {})
        month_labels.append(dt.strftime('%d %b'))
        month_solar.append(round(float(bucket.get('solar', 0.0)), 3))
        month_grid.append(round(float(bucket.get('grid', 0.0)), 3))

    year_labels = []
    year_solar = []
    year_grid = []
    for months_back in range(11, -1, -1):
        month_dt = (now_dt.replace(day=1, hour=0, minute=0, second=0, microsecond=0))
        month_number = month_dt.month - months_back
        year_number = month_dt.year
        while month_number <= 0:
            month_number += 12
            year_number -= 1
        while month_number > 12:
            month_number -= 12
            year_number += 1

        solar_total = 0.0
        grid_total = 0.0
        month_prefix = '%04d-%02d-' % (year_number, month_number)
        for day_key, bucket in daily.items():
            if(str(day_key).startswith(month_prefix)):
                solar_total += float(bucket.get('solar', 0.0))
                grid_total += float(bucket.get('grid', 0.0))

        label_dt = datetime(year_number, month_number, 1)
        year_labels.append(label_dt.strftime('%b'))
        year_solar.append(round(solar_total, 3))
        year_grid.append(round(grid_total, 3))

    return {
        'today': {'labels': today_labels, 'solar': today_solar, 'grid': today_grid},
        'week': {'labels': week_labels, 'solar': week_solar, 'grid': week_grid},
        'month': {'labels': month_labels, 'solar': month_solar, 'grid': month_grid},
        'year': {'labels': year_labels, 'solar': year_solar, 'grid': year_grid},
    }


def update_energy_tracking(state, now, general_config=None):
    global energy_history_store

    debug_level = general_config.debug_level if general_config != None else debugLevel
    if(general_config != None and general_config.fake_master != 1):
        state.timeLastkWhDelivered = now
        return

    elapsed = now - state.timeLastkWhDelivered
    if(elapsed <= 0):
        return

    total_amps = total_amps_actual_all_twcs(state, general_config)
    solar_amps = min(total_amps, max(0.0, float(getattr(state, 'greenEnergyAvailableAmps', 0.0))))
    grid_amps = max(0.0, total_amps - solar_amps)
    solar_kwh = ((240 * solar_amps) / 1000 / 60 / 60) * elapsed
    grid_kwh = ((240 * grid_amps) / 1000 / 60 / 60) * elapsed
    state.kWhDelivered += (solar_kwh + grid_kwh)
    state.timeLastkWhDelivered = now

    if(energy_history_store != None):
        state.energyHistory = energy_history_store.record_delta(
            state.energyHistory, now - elapsed, now, solar_kwh, grid_kwh, state.kWhDelivered
        )

    if(time.time() - state.timeLastkWhSaved >= 300.0):
        state.timeLastkWhSaved = now
        if(debug_level >= 9):
            print(time_now() + ": Delivered %.3fkWh total" % (state.kWhDelivered))
        if(energy_history_store != None):
            energy_history_store.save(state.energyHistory)
        save_settings(state)


def is_time_in_window(hour_now, start_hour, end_hour):
    """Return True when hour_now falls inside the configured time window."""
    if(start_hour < 0 or end_hour < 0):
        return False

    if(start_hour == end_hour):
        return False

    if(start_hour < end_hour):
        return hour_now >= start_hour and hour_now < end_hour

    return hour_now >= start_hour or hour_now < end_hour


def send_msg(msg, state=None):
    # Send msg on the RS485 network. We'll escape bytes with a special meaning,
    # add a CRC byte to the message end, and add a C0 byte to the start and end
    # to mark where it begins and ends.
    msg = bytearray(msg)
    checksum = 0
    for i in range(1, len(msg)):
        checksum += msg[i]

    msg.append(checksum & 0xFF)

    # Escaping special chars:
    # The protocol uses C0 to mark the start and end of the message.  If a C0
    # must appear within the message, it is 'escaped' by replacing it with
    # DB and DC bytes.
    # A DB byte in the message is escaped by replacing it with DB DD.
    #
    # User FuzzyLogic found that this method of escaping and marking the start
    # and end of messages is based on the SLIP protocol discussed here:
    #   https://en.wikipedia.org/wiki/Serial_Line_Internet_Protocol
    i = 0
    while(i < len(msg)):
        if(msg[i] == 0xc0):
            msg[i:i+1] = b'\xdb\xdc'
            i = i + 1
        elif(msg[i] == 0xdb):
            msg[i:i+1] = b'\xdb\xdd'
            i = i + 1
        i = i + 1

    msg = bytearray(b'\xc0' + msg + b'\xc0')

    if(debugLevel >= 9):
        print("Tx@" + time_now() + ": " + hex_str(msg))

    if(rs485_transport != None):
        rs485_transport.write(msg)

    if(state != None):
        state.timeLastTx = time.time()

def unescape_msg(msg:bytearray, msgLen):
    # Given a message received on the RS485 network, remove leading and trailing
    # C0 byte, unescape special byte values, and verify its data matches the CRC
    # byte.
    # Some read paths may hand us immutable bytes. Work on a mutable copy
    # because the unescape logic rewrites the buffer in place.
    msg = bytearray(msg[0:msgLen])

    # See notes in send_msg() for the way certain bytes in messages are escaped.
    # We basically want to change db dc into c0 and db dd into db.
    # Only scan to one less than the length of the string to avoid running off
    # the end looking at i+1.
    i = 0
    while i < len(msg):
        if(msg[i] == 0xdb):
            if(i + 1 >= len(msg)):
                print(time_now() + " ERROR: Message ends with incomplete escape byte 0xDB.  Message may be corrupted.")
                msg[i:i+1] = [0xdb]
                break
            if(msg[i+1] == 0xdc):
                # Replace characters at msg[i] and msg[i+1] with 0xc0,
                # shortening the string by one character. In Python, msg[x:y]
                # refers to a substring starting at x and ending immediately
                # before y. y - x is the length of the substring.
                msg[i:i+2] = [0xc0]
            elif(msg[i+1] == 0xdd):
                msg[i:i+2] = [0xdb]
            else:
                print(time_now(), "ERROR: Special character 0xDB in message is " \
                  "followed by invalid character 0x%02X.  " \
                  "Message may be corrupted." %
                  (msg[i+1]))

                # Replace the character with something even though it's probably
                # not the right thing.
                msg[i:i+2] = [0xdb]
        i = i+1

    # Remove leading and trailing C0 byte.
    msg = msg[1:len(msg)-1]
    return msg


def send_master_linkready1(state=None, general_config=None):
    debug_level = general_config.debug_level if general_config != None else debugLevel
    fake_twc_id = general_config.fake_twc_id if general_config != None else fakeTWCID
    master_sign = general_config.master_sign if general_config != None else masterSign

    if(debug_level >= 1):
        print(time_now() + ": Send master linkready1")

    # When master is powered on or reset, it sends 5 to 7 copies of this
    # linkready1 message followed by 5 copies of linkready2 (I've never seen
    # more or less than 5 of linkready2).
    #
    # This linkready1 message advertises master's TWCID to other slaves on the
    # network.
    # If a slave happens to have the same id as master, it will pick a new
    # random TWCID. Other than that, slaves don't seem to respond to linkready1.

    # linkready1 and linkready2 are identical except FC E1 is replaced by FB E2
    # in bytes 2-3. Both messages will cause a slave to pick a new id if the
    # slave's id conflicts with master.
    # If a slave stops sending heartbeats for awhile, master may send a series
    # of linkready1 and linkready2 messages in seemingly random order, which
    # means they don't indicate any sort of startup state.

    # linkready1 is not sent again after boot/reset unless a slave sends its
    # linkready message.
    # At that point, linkready1 message may start sending every 1-5 seconds, or
    # it may not be sent at all.
    # Behaviors I've seen:
    #   Not sent at all as long as slave keeps responding to heartbeat messages
    #   right from the start.
    #   If slave stops responding, then re-appears, linkready1 gets sent
    #   frequently.

    # One other possible purpose of linkready1 and/or linkready2 is to trigger
    # an error condition if two TWCs on the network transmit those messages.
    # That means two TWCs have rotary switches setting them to master mode and
    # they will both flash their red LED 4 times with top green light on if that
    # happens.

    # Also note that linkready1 starts with FC E1 which is similar to the FC D1
    # message that masters send out every 4 hours when idle. Oddly, the FC D1
    # message contains all zeros instead of the master's id, so it seems
    # pointless.

    # I also don't understand the purpose of having both linkready1 and
    # linkready2 since only two or more linkready2 will provoke a response from
    # a slave regardless of whether linkready1 was sent previously. Firmware
    # trace shows that slaves do something somewhat complex when they receive
    # linkready1 but I haven't been curious enough to try to understand what
    # they're doing. Tests show neither linkready1 or 2 are necessary. Slaves
    # send slave linkready every 10 seconds whether or not they got master
    # linkready1/2 and if a master sees slave linkready, it will start sending
    # the slave master heartbeat once per second and the two are then connected.
    send_msg(bytearray(b'\xFC\xE1') + fake_twc_id + master_sign + bytearray(b'\x00\x00\x00\x00\x00\x00\x00\x00'), state)


def send_master_linkready2(state=None, general_config=None):
    debug_level = general_config.debug_level if general_config != None else debugLevel
    fake_twc_id = general_config.fake_twc_id if general_config != None else fakeTWCID
    master_sign = general_config.master_sign if general_config != None else masterSign

    if(debug_level >= 1):
        print(time_now() + ": Send master linkready2")

    # This linkready2 message is also sent 5 times when master is booted/reset
    # and then not sent again if no other TWCs are heard from on the network.
    # If the master has ever seen a slave on the network, linkready2 is sent at
    # long intervals.
    # Slaves always ignore the first linkready2, but respond to the second
    # linkready2 around 0.2s later by sending five slave linkready messages.
    #
    # It may be that this linkready2 message that sends FB E2 and the master
    # heartbeat that sends fb e0 message are really the same, (same FB byte
    # which I think is message type) except the E0 version includes the TWC ID
    # of the slave the message is intended for whereas the E2 version has no
    # recipient TWC ID.
    #
    # Once a master starts sending heartbeat messages to a slave, it
    # no longer sends the global linkready2 message (or if it does,
    # they're quite rare so I haven't seen them).
    send_msg(bytearray(b'\xFB\xE2') + fake_twc_id + master_sign + bytearray(b'\x00\x00\x00\x00\x00\x00\x00\x00'), state)

def send_slave_linkready(state=None, protocol_version=1, general_config=None):
    fake_twc_id = general_config.fake_twc_id if general_config != None else fakeTWCID
    slave_sign = general_config.slave_sign if general_config != None else slaveSign

    # In the message below, \x1F\x40 (hex 0x1f40 or 8000 in base 10) refers to
    # this being a max 80.00Amp charger model.
    # EU chargers are 32A and send 0x0c80 (3200 in base 10).
    #
    # I accidentally changed \x1f\x40 to \x2e\x69 at one point, which makes the
    # master TWC immediately start blinking its red LED 6 times with top green
    # LED on. Manual says this means "The networked Wall Connectors have
    # different maximum current capabilities".
    msg = bytearray(b'\xFD\xE2') + fake_twc_id + slave_sign + bytearray(b'\x1F\x40\x00\x00\x00\x00\x00\x00')

    if(protocol_version == 2):
        msg += bytearray(b'\x00\x00')

    send_msg(msg, state)

def master_id_conflict(general_config=None):
    # We're playing fake slave, and we got a message from a master with our TWCID.
    # By convention, as a slave we must change our TWCID because a master will not.
    fake_twc_id = general_config.fake_twc_id if general_config != None else fakeTWCID
    slave_sign = general_config.slave_sign if general_config != None else slaveSign

    fake_twc_id[0] = random.randint(0, 0xFF)
    fake_twc_id[1] = random.randint(0, 0xFF)

    # Real slaves change their sign during a conflict, so we do too.
    slave_sign[0] = random.randint(0, 0xFF)

    print(time_now() + ": Master's TWCID matches our fake slave's TWCID.  " \
        "Picked new random TWCID %02X%02X with sign %02X" % \
        (fake_twc_id[0], fake_twc_id[1], slave_sign[0]))

def new_slave(state, newSlaveID, maxAmps, general_config=None, energy_config=None):
    slaveTWCs = state.slaveTWCs
    slaveTWCRoundRobin = state.slaveTWCRoundRobin

    try:
        slaveTWC = slaveTWCs[newSlaveID]
        # We didn't get KeyError exception, so this slave is already in
        # slaveTWCs and we can simply return it.
        return slaveTWC
    except KeyError:
        pass

    slaveTWC = TWCSlave(
        newSlaveID,
        maxAmps,
        tesla_car_api,
        background_task_runner,
        state,
        (general_config.fake_twc_id if general_config != None else fakeTWCID),
        general_config,
        energy_config,
    )
    slaveTWCs[newSlaveID] = slaveTWC
    slaveTWCRoundRobin.append(slaveTWC)

    if(len(slaveTWCRoundRobin) > 3):
        print("WARNING: More than 3 slave TWCs seen on network.  " \
            "Dropping oldest: " + hex_str(slaveTWCRoundRobin[0].TWCID) + ".")
        delete_slave(state, slaveTWCRoundRobin[0].TWCID)

    return slaveTWC

def delete_slave(state, deleteSlaveID):
    slaveTWCs = state.slaveTWCs
    slaveTWCRoundRobin = state.slaveTWCRoundRobin

    for i in range(0, len(slaveTWCRoundRobin)):
        if(slaveTWCRoundRobin[i].TWCID == deleteSlaveID):
            del slaveTWCRoundRobin[i]
            break
    try:
        del slaveTWCs[deleteSlaveID]
    except KeyError:
        pass

def total_amps_actual_all_twcs(state, general_config=None):
    debug_level = general_config.debug_level if general_config != None else debugLevel
    slaveTWCRoundRobin = state.slaveTWCRoundRobin

    totalAmps = 0
    for slaveTWC in slaveTWCRoundRobin:
        totalAmps += slaveTWC.reportedAmpsActual
    if(debug_level >= 10):
        print("Total amps all slaves are using: " + str(totalAmps))
    return totalAmps


def car_api_available(charge = None):
    if(tesla_car_api == None):
        return False
    return tesla_car_api.available(charge)

def car_api_charge(charge):
    if(tesla_car_api == None):
        return 'error'
    return tesla_car_api.charge(charge)


def queue_background_task(task):
    if(background_task_runner == None):
        return False

    return background_task_runner.queue_task(task)


def background_tasks_thread():
    if(background_task_runner != None):
        background_task_runner.run_forever()

def check_green_energy():
    if(green_energy_monitor != None):
        green_energy_monitor.check()

#
# End functions
#
##############################


##############################
#
# Begin CarApiVehicle class
#

class CarApiVehicle:
    ID = None
    api = None

    firstWakeAttemptTime = 0
    lastWakeAttemptTime = 0
    delayNextWakeAttempt = 0

    lastErrorTime = 0
    stopAskingToStartCharging = False
    lat = 10000
    lon = 10000

    def __init__(self, ID, api=None):
        self.ID = ID
        self.api = api

    def ready(self):
        if(self.api == None):
            raise RuntimeError("CarApiVehicle.ready requires TeslaCarApi")
        error_retry_mins = self.api.error_retry_mins

        if(time.time() - self.lastErrorTime < error_retry_mins*60):
            # It's been under carApiErrorRetryMins minutes since the car API
            # generated an error on this vehicle. Return that car is not ready.
            if(debugLevel >= 8):
                print(time_now() + ': Vehicle ' + str(self.ID)
                    + ' not ready because of recent lastErrorTime '
                    + str(self.lastErrorTime))
            return False

        if(self.firstWakeAttemptTime == 0 and time.time() - self.lastWakeAttemptTime < 2*60):
            # Less than 2 minutes since we successfully woke this car, so it
            # should still be awake.  Tests on my car in energy saver mode show
            # it returns to sleep state about two minutes after the last command
            # was issued.  Times I've tested: 1:35, 1:57, 2:30
            return True

        if(debugLevel >= 8):
            print(time_now() + ': Vehicle ' + str(self.ID)
                + " not ready because it wasn't woken in the last 2 minutes.")
        return False

    def update_location(self):
        if(self.api == None):
            raise RuntimeError("CarApiVehicle.update_location requires TeslaCarApi")
        if(self.ready() == False):
            return False

        apiResponseDict = {}
        transient_errors = self.api.transient_errors
        error_retry_mins = self.api.error_retry_mins

        # Retry up to 3 times on certain errors.
        for retryCount in range(0, 3):
            apiResponseDict = self.api.request_json(
                'GET',
                self.api.fleet_api_base_url + '/vehicles/' + str(self.ID) + '/vehicle_data',
                headers=self.api.auth_headers()
            )

            try:
                if(debugLevel >= 4):
                    print(time_now() + ': Car API vehicle GPS location', apiResponseDict, '\n')

                if('error' in apiResponseDict):
                    foundKnownError = False
                    error           = apiResponseDict['error']

                    for knownError in transient_errors:
                        if (knownError == error[0:len(knownError)]):
                            # I see these errors often enough that I think
                            # it's worth re-trying in 1 minute rather than
                            # waiting carApiErrorRetryMins minutes for retry
                            # in the standard error handler.
                            if (debugLevel >= 1):
                                print(time_now() + ": Car API returned '"
                                      + error
                                      + "' when trying to get GPS location.  Try again in 1 minute.")

                            time.sleep(60)
                            foundKnownError = True
                            break
                    if (foundKnownError):
                        continue

                response = apiResponseDict['response']['drive_state']

                # A successful call to drive_state will not contain a
                # response['reason'], so we check if the 'reason' key exists.
                if('reason' in response and response['reason'] == 'could_not_wake_buses'):
                    # Retry after 5 seconds.  See notes in car_api_charge where
                    # 'could_not_wake_buses' is handled.
                    time.sleep(5)
                    continue

                self.lat = response['latitude']
                self.lon = response['longitude']
            except (KeyError, TypeError):
                # This catches cases like trying to access
                # apiResponseDict['response'] when 'response' doesn't exist in
                # apiResponseDict.
                if(debugLevel >= 1):
                    print(time_now() + ": ERROR: Can't get GPS location of vehicle " + str(self.ID) + \
                          ".  Will try again later.")
                self.lastErrorTime = time.time()
                return False

            return True


#
# End CarApiVehicle class
#
##############################



##############################
#
# Begin TWCSlave class
#

class TWCSlave:
    TWCID               = None
    maxAmps             = None

    # Protocol 2 TWCs tend to respond to commands sent using protocol 1, so
    # default to that till we know for sure we're talking to protocol 2.
    protocolVersion     = 1
    minAmpsTWCSupports  = 1 
    masterHeartbeatData = bytearray(b'\x00\x00\x00\x00\x00\x00\x00\x00\x00')
    timeLastRx          = time.time()

    # reported* vars below are reported to us in heartbeat messages from a Slave
    # TWC.
    reportedAmpsMax     = 0
    reportedAmpsActual  = 0
    reportedState       = 0

    # reportedAmpsActual frequently changes by small amounts, like 5.14A may
    # frequently change to 5.23A and back.
    # reportedAmpsActualSignificantChangeMonitor is set to reportedAmpsActual
    # whenever reportedAmpsActual is at least 0.8A different than
    # reportedAmpsActualSignificantChangeMonitor. Whenever
    # reportedAmpsActualSignificantChangeMonitor is changed,
    # timeReportedAmpsActualChangedSignificantly is set to the time of the
    # change. The value of reportedAmpsActualSignificantChangeMonitor should not
    # be used for any other purpose. timeReportedAmpsActualChangedSignificantly
    # is used for things like preventing start and stop charge on a car more
    # than once per minute.
    reportedAmpsActualSignificantChangeMonitor = -1
    timeReportedAmpsActualChangedSignificantly = time.time()

    lastAmpsOffered              = -1
    timeLastAmpsOfferedChanged   = time.time()
    lastHeartbeatDebugOutput     = ''
    timeLastHeartbeatDebugOutput = 0
    wiringMaxAmps                = 0
    car_api = None
    task_runner = None
    state = None
    local_twc_id = None
    general_config = None
    energy_config = None
    lastVehicleConnected = None
    lastVehicleCharging = None
    autoStartChargeRequested = False
    lastChargeStartRequestTime = 0
    lastChargeStopRequestTime = 0

    def __init__(self, TWCID, maxAmps, car_api=None, task_runner=None, state=None,
                 local_twc_id=None, general_config=None, energy_config=None):
        if(general_config == None or energy_config == None):
            raise RuntimeError("TWCSlave requires GeneralConfig and EnergyConfig")

        now = time.time()
        self.TWCID   = TWCID
        self.maxAmps = maxAmps
        self.car_api = car_api
        self.task_runner = task_runner
        self.state = state
        self.general_config = general_config
        self.energy_config = energy_config
        self.masterHeartbeatData = bytearray(b'\x00\x00\x00\x00\x00\x00\x00\x00\x00')
        self.timeLastRx = now
        self.reportedAmpsMax = 0
        self.reportedAmpsActual = 0
        self.reportedState = 0
        self.reportedAmpsActualSignificantChangeMonitor = -1
        self.timeReportedAmpsActualChangedSignificantly = now
        self.lastAmpsOffered = -1
        self.timeLastAmpsOfferedChanged = now
        self.lastHeartbeatDebugOutput = ''
        self.timeLastHeartbeatDebugOutput = 0
        default_local_twc_id = self.general_config.fake_twc_id
        self.local_twc_id = local_twc_id if local_twc_id != None else default_local_twc_id
        self.wiringMaxAmps = self.energy_config.wiring_max_amps_per_twc
        self.minAmpsTWCSupports = self.energy_config.min_amps_twc_supports
        self.lastVehicleConnected = None
        self.lastVehicleCharging = None
        self.autoStartChargeRequested = False
        self.lastChargeStartRequestTime = 0
        self.lastChargeStopRequestTime = 0

    def describe_reported_state(self, state_code):
        state_map = {
            0x00: 'ready',
            0x01: 'plugged in, charging',
            0x02: 'error',
            0x03: 'plugged in, do not charge',
            0x04: 'plugged in, ready to charge',
            0x05: 'busy',
            0x08: 'stopped charging',
        }
        if (state_code in state_map):
            return state_map[state_code]
        return 'state 0x%02X' % state_code

    def log_vehicle_state_transition(self, prev_state, prev_amps_actual):
        debug_level = self.general_config.debug_level
        prev_connected = (prev_state in (0x01, 0x03, 0x04, 0x05, 0x08) or prev_amps_actual >= 1.0)
        now_connected = (self.reportedState in (0x01, 0x03, 0x04, 0x05, 0x08) or self.reportedAmpsActual >= 1.0)
        prev_charging = (prev_amps_actual >= 1.0 or prev_state == 0x01)
        now_charging = (self.reportedAmpsActual >= 1.0 or self.reportedState == 0x01)
        twc_id = '%02X%02X' % (self.TWCID[0], self.TWCID[1])

        if (self.lastVehicleConnected is None):
            self.lastVehicleConnected = now_connected
        elif (self.lastVehicleConnected != now_connected):
            if (now_connected):
                print(time_now() + ': TWC ' + twc_id + ' detected vehicle connected. ' +
                      'state=' + self.describe_reported_state(self.reportedState) +
                      ', amps=' + ('%.2f' % self.reportedAmpsActual))
            elif (debug_level >= 1):
                print(time_now() + ': TWC ' + twc_id + ' detected vehicle disconnected.')
                self.autoStartChargeRequested = False
            self.lastVehicleConnected = now_connected

        if (self.lastVehicleCharging is None):
            self.lastVehicleCharging = now_charging
        elif (self.lastVehicleCharging != now_charging):
            if (now_charging):
                print(time_now() + ': TWC ' + twc_id + ' vehicle started charging. ' +
                      'state=' + self.describe_reported_state(self.reportedState) +
                      ', amps=' + ('%.2f' % self.reportedAmpsActual))
            elif (debug_level >= 1):
                print(time_now() + ': TWC ' + twc_id + ' vehicle stopped charging. ' +
                      'state=' + self.describe_reported_state(self.reportedState) +
                      ', amps=' + ('%.2f' % self.reportedAmpsActual))
                self.autoStartChargeRequested = False
            self.lastVehicleCharging = now_charging

    def print_status(self, heartbeatData):
        if(self.state == None):
            raise RuntimeError("TWCSlave.print_status requires RuntimeState")
        if(self.general_config == None):
            raise RuntimeError("TWCSlave.print_status requires GeneralConfig")

        debug_level = self.general_config.debug_level
        fake_master = self.general_config.fake_master
        master_twc_id = self.state.masterTWCID

        try:
            debugOutput = ": SHB %02X%02X: %02X %05.2f/%05.2fA %02X%02X" % \
                (self.TWCID[0], self.TWCID[1], heartbeatData[0],
                (((heartbeatData[3] << 8) + heartbeatData[4]) / 100),
                (((heartbeatData[1] << 8) + heartbeatData[2]) / 100),
                heartbeatData[5], heartbeatData[6]
                )
            if (self.protocolVersion == 2):
                debugOutput += (" %02X%02X" % (heartbeatData[7], heartbeatData[8]))

            debugOutput += "  M"

            if (not fake_master):
                debugOutput += " %02X%02X" % (master_twc_id[0], master_twc_id[1])

            debugOutput += ": %02X %05.2f/%05.2fA %02X%02X" % \
                    (self.masterHeartbeatData[0],
                    (((self.masterHeartbeatData[3] << 8) + self.masterHeartbeatData[4]) / 100),
                    (((self.masterHeartbeatData[1] << 8) + self.masterHeartbeatData[2]) / 100),
                    self.masterHeartbeatData[5], self.masterHeartbeatData[6])
            if (self.protocolVersion == 2):
                debugOutput += (" %02X%02X" %
                    (self.masterHeartbeatData[7], self.masterHeartbeatData[8]))

            # Only output once-per-second heartbeat debug info when it's
            # different from the last output or if the only change has been amps
            # in use and it's only changed by 1.0 or less. Also output f it's
            # been 10 mins since the last output or if debugLevel is turned up
            # to 11.
            lastAmpsUsed       = 0
            ampsUsed           = 1
            debugOutputCompare = debugOutput
            m1                 = re.search(r'SHB ....: .. (..\...)/', self.lastHeartbeatDebugOutput)

            if (m1):
                lastAmpsUsed = float(m1.group(1))

            m2 = re.search(r'SHB ....: .. (..\...)/', debugOutput)

            if (m2):
                ampsUsed = float(m2.group(1))

                if (m1):
                    debugOutputCompare = debugOutputCompare[0:m2.start(1)] + \
                        self.lastHeartbeatDebugOutput[m1.start(1):m1.end(1)] + \
                        debugOutputCompare[m2.end(1):]

            if (
                debugOutputCompare != self.lastHeartbeatDebugOutput
                or abs(ampsUsed - lastAmpsUsed) >= 1.0
                or time.time() - self.timeLastHeartbeatDebugOutput > 600
                or debug_level >= 11
            ):
                print(time_now() + debugOutput)

                self.lastHeartbeatDebugOutput     = debugOutput
                self.timeLastHeartbeatDebugOutput = time.time()
        except IndexError:
            # This happens if we try to access, say, heartbeatData[8] when
            # len(heartbeatData) < 9. This was happening due to a bug I fixed
            # but I may as well leave this here just in case.
            if (len(heartbeatData) != (7 if self.protocolVersion == 1 else 9)):
                print(time_now() + ': Error in print_status displaying heartbeatData',
                      heartbeatData, 'based on msg', hex_str(msg))

            if (len(self.masterHeartbeatData) != (7 if self.protocolVersion == 1 else 9)):
                print(time_now() + ': Error in print_status displaying masterHeartbeatData', self.masterHeartbeatData)

    def queue_charge_request(self, charge, reason):
        debug_level = self.general_config.debug_level
        now = time.time()
        last_request_time = (
            self.lastChargeStartRequestTime if charge else self.lastChargeStopRequestTime
        )

        # Match TeslaCarApi.charge()'s one-minute command rate limit so a TWC
        # does not re-request the same start/stop command on every heartbeat
        # while the car or API is still settling.
        if(now - last_request_time < 60):
            return False

        queued = False
        if(self.task_runner != None):
            queued = self.task_runner.queue_task({'cmd':'charge', 'charge':charge})
        else:
            queued = queue_background_task({'cmd':'charge', 'charge':charge})

        if(not queued):
            return False

        if(charge):
            self.lastChargeStartRequestTime = now
        else:
            self.lastChargeStopRequestTime = now

        if(debug_level >= 1):
            print(time_now() + ': TWC %02X%02X queue Tesla API %s because %s' % (
                self.TWCID[0],
                self.TWCID[1],
                ('start charge' if charge else 'stop charge'),
                reason,
            ))

        return True

    def send_slave_heartbeat(self, masterID):
        # Send slave heartbeat
        #
        # Heartbeat includes data we store in slaveHeartbeatData.
        # Meaning of data:
        #
        # Byte 1 is a state code:
        #   00 Ready
        #      Car may or may not be plugged in.
        #      When car has reached its charge target, I've repeatedly seen it
        #      change from 03 to 00 the moment I wake the car using the phone app.
        #   01 Plugged in, charging
        #   02 Error
        #      This indicates an error such as not getting a heartbeat message
        #      from Master for too long.
        #   03 Plugged in, do not charge
        #      I've seen this state briefly when plug is first inserted, and
        #      I've seen this state remain indefinitely after pressing stop
        #      charge on car's screen or when the car reaches its target charge
        #      percentage. Unfortunately, this state does not reliably remain
        #      set, so I don't think it can be used to tell when a car is done
        #      charging. It may also remain indefinitely if TWCManager script is
        #      stopped for too long while car is charging even after TWCManager
        #      is restarted. In that case, car will not charge even when start
        #      charge on screen is pressed - only re-plugging in charge cable
        #      fixes it.
        #   04 Plugged in, ready to charge or charge scheduled
        #      I've seen this state even when car is set to charge at a future
        #      time via its UI. In that case, it won't accept power offered to
        #      it.
        #   05 Busy?
        #      I've only seen it hit this state for 1 second at a time and it
        #      can seemingly happen during any other state. Maybe it means wait,
        #      I'm busy? Communicating with car?
        #   08 Starting to charge?
        #      This state may remain for a few seconds while car ramps up from
        #      0A to 1.3A, then state usually changes to 01. Sometimes car skips
        #      08 and goes directly to 01.
        #      I saw 08 consistently each time I stopped fake master script with
        #      car scheduled to charge, plugged in, charge port blue. If the car
        #      is actually charging and you stop TWCManager, after 20-30 seconds
        #      the charge port turns solid red, steering wheel display says
        #      "charge cable fault", and main screen says "check charger power".
        #      When TWCManager is started, it sees this 08 status again. If we
        #      start TWCManager and send the slave a new max power value, 08
        #      becomes 00 and car starts charging again.
        #
        #   Protocol 2 adds a number of other states:
        #   06, 07, 09
        #      These are each sent as a response to Master sending the
        #      corresponding state. Ie if Master sends 06, slave responds with
        #      06. See notes in send_master_heartbeat for meaning.
        #   0A Amp adjustment period complete
        #      Master uses state 06 and 07 to raise or lower the slave by 2A
        #      temporarily.  When that temporary period is over, it changes
        #      state to 0A.
        #   0F was reported by another user but I've not seen it during testing
        #      and have no idea what it means.
        #
        # Byte 2-3 is the max current available as provided by bytes 2-3 in our
        # fake master status.
        # For example, if bytes 2-3 are 0F A0, combine them as 0x0fa0 hex which
        # is 4000 in base 10. Move the decimal point two places left and you get
        # 40.00Amps max.
        #
        # Byte 4-5 represents the power the car is actually drawing for
        # charging. When a car is told to charge at 19A you may see a value like
        # 07 28 which is 0x728 hex or 1832 in base 10. Move the decimal point
        # two places left and you see the charger is using 18.32A.
        # Some TWCs report 0A when a car is not charging while others may report
        # small values such as 0.25A. I suspect 0A is what should be reported
        # and any small value indicates a minor calibration error.
        #
        # Remaining bytes are always 00 00 from what I've seen and could be
        # reserved for future use or may be used in a situation I've not
        # observed.  Protocol 1 uses two zero bytes while protocol 2 uses four.

        ###############################
        # How was the above determined?
        #
        # An unplugged slave sends a status like this:
        #   00 00 00 00 19 00 00
        #
        # A real master always sends all 00 status data to a slave reporting the
        # above status. slaveHeartbeatData[0] is the main driver of how master
        # responds, but whether slaveHeartbeatData[1] and [2] have 00 or non-00
        # values also matters.
        #
        # I did a test with a protocol 1 TWC with fake slave sending
        # slaveHeartbeatData[0] values from 00 to ff along with
        # slaveHeartbeatData[1-2] of 00 and whatever
        # value Master last responded with. I found:
        #   Slave sends:     04 00 00 00 19 00 00
        #   Master responds: 05 12 c0 00 00 00 00
        #
        #   Slave sends:     04 12 c0 00 19 00 00
        #   Master responds: 00 00 00 00 00 00 00
        #
        #   Slave sends:     08 00 00 00 19 00 00
        #   Master responds: 08 12 c0 00 00 00 00
        #
        #   Slave sends:     08 12 c0 00 19 00 00
        #   Master responds: 00 00 00 00 00 00 00
        #
        # In other words, master always sends all 00 unless slave sends
        # slaveHeartbeatData[0] 04 or 08 with slaveHeartbeatData[1-2] both 00.
        #
        # I interpret all this to mean that when slave sends
        # slaveHeartbeatData[1-2] both 00, it's requesting a max power from
        # master. Master responds by telling the slave how much power it can
        # use. Once the slave is saying how much max power it's going to use
        # (slaveHeartbeatData[1-2] = 12 c0 = 32.00A), master indicates that's
        # fine by sending 00 00.
        #
        # However, if the master wants to set a lower limit on the slave, all it
        # has to do is send any heartbeatData[1-2] value greater than 00 00 at
        # any time and slave will respond by setting its
        # slaveHeartbeatData[1-2] to the same value.
        #
        # I thought slave might be able to negotiate a lower value if, say, the
        # car reported 40A was its max capability or if the slave itself could
        # only handle 80A, but the slave dutifully responds with the same value
        # master sends it even if that value is an insane 655.35A. I tested
        # these values on car which has a 40A limit when AC charging and
        # slave accepts them all:
        #   0f aa (40.10A)
        #   1f 40 (80.00A)
        #   1f 41 (80.01A)
        #   ff ff (655.35A)
        if(self.state == None):
            raise RuntimeError("TWCSlave.send_slave_heartbeat requires RuntimeState")

        slave_heartbeat_data = self.state.slaveHeartbeatData

        if(self.protocolVersion == 1 and len(slave_heartbeat_data) > 7):
            # Cut array down to length 7
            slave_heartbeat_data = slave_heartbeat_data[0:7]
        elif(self.protocolVersion == 2):
            while(len(slave_heartbeat_data) < 9):
                # Increase array length to 9
                slave_heartbeat_data.append(0x00)

        self.state.slaveHeartbeatData = slave_heartbeat_data
        send_msg(bytearray(b'\xFD\xE0') + self.local_twc_id + bytearray(masterID) + bytearray(slave_heartbeat_data), self.state)

    def send_master_heartbeat(self):
        # Send our fake master's heartbeat to this TWCSlave.
        #
        # Heartbeat includes 7 bytes (Protocol 1) or 9 bytes (Protocol 2) of data
        # that we store in masterHeartbeatData.

        # Meaning of data:
        #
        # Byte 1 is a command:
        #   00 Make no changes
        #   02 Error
        #     Byte 2 appears to act as a bitmap where each set bit causes the
        #     slave TWC to enter a different error state. First 8 digits below
        #     show which bits are set and these values were tested on a Protocol
        #     2 TWC:
        #       0000 0001 = Middle LED blinks 3 times red, top LED solid green.
        #                   Manual says this code means 'Incorrect rotary switch
        #                   setting.'
        #       0000 0010 = Middle LED blinks 5 times red, top LED solid green.
        #                   Manual says this code means 'More than three Wall
        #                   Connectors are set to Slave.'
        #       0000 0100 = Middle LED blinks 6 times red, top LED solid green.
        #                   Manual says this code means 'The networked Wall
        #                   Connectors have different maximum current
        #                   capabilities.'
        #   	0000 1000 = No effect
        #   	0001 0000 = No effect
        #   	0010 0000 = No effect
        #   	0100 0000 = No effect
    	#       1000 0000 = No effect
        #     When two bits are set, the lowest bit (rightmost bit) seems to
        #     take precedence (ie 111 results in 3 blinks, 110 results in 5
        #     blinks).
        #
        #     If you send 02 to a slave TWC with an error code that triggers
        #     the middle LED to blink red, slave responds with 02 in its
        #     heartbeat, then stops sending heartbeat and refuses further
        #     communication. Slave's error state can be cleared by holding red
        #     reset button on its left side for about 4 seconds.
        #     If you send an error code with bitmap 11110xxx (where x is any bit),
        #     the error can not be cleared with a 4-second reset.  Instead, you
        #     must power cycle the TWC or 'reboot' reset which means holding
        #     reset for about 6 seconds till all the LEDs turn green.
        #   05 Tell slave charger to limit power to number of amps in bytes 2-3.
        #
        # Protocol 2 adds a few more command codes:
        #   06 Increase charge current by 2 amps.  Slave changes its heartbeat
        #      state to 06 in response. After 44 seconds, slave state changes to
        #      0A but amp value doesn't change.  This state seems to be used to
        #      safely creep up the amp value of a slave when the Master has extra
        #      power to distribute.  If a slave is attached to a car that doesn't
        #      want that many amps, Master will see the car isn't accepting the
        #      amps and stop offering more.  It's possible the 0A state change
        #      is not time based but rather indicates something like the car is
        #      now using as many amps as it's going to use.
        #   07 Lower charge current by 2 amps. Slave changes its heartbeat state
        #      to 07 in response. After 10 seconds, slave raises its amp setting
        #      back up by 2A and changes state to 0A.
        #      I could be wrong, but when a real car doesn't want the higher amp
        #      value, I think the TWC doesn't raise by 2A after 10 seconds. Real
        #      Master TWCs seem to send 07 state to all children periodically as
        #      if to check if they're willing to accept lower amp values. If
        #      they do, Master assigns those amps to a different slave using the
        #      06 state.
        #   08 Master acknowledges that slave stopped charging (I think), but
        #      the next two bytes contain an amp value the slave could be using.
        #   09 Tell slave charger to limit power to number of amps in bytes 2-3.
        #      This command replaces the 05 command in Protocol 1. However, 05
        #      continues to be used, but only to set an amp value to be used
        #      before a car starts charging. If 05 is sent after a car is
        #      already charging, it is ignored.
        #
        # Byte 2-3 is the max current a slave TWC can charge at in command codes
        # 05, 08, and 09. In command code 02, byte 2 is a bitmap. With other
        # command codes, bytes 2-3 are ignored.
        # If bytes 2-3 are an amp value of 0F A0, combine them as 0x0fa0 hex
        # which is 4000 in base 10. Move the decimal point two places left and
        # you get 40.00Amps max.
        #
        # Byte 4: 01 when a Master TWC is physically plugged in to a car.
        # Otherwise 00.
        #
        # Remaining bytes are always 00.
        #
        # Example 7-byte data that real masters have sent in Protocol 1:
        #   00 00 00 00 00 00 00  (Idle)
        #   02 04 00 00 00 00 00  (Error bitmap 04.  This happened when I
        #                         advertised a fake Master using an invalid max
        #                         amp value)
        #   05 0f a0 00 00 00 00  (Master telling slave to limit power to 0f a0
        #                         (40.00A))
        #   05 07 d0 01 00 00 00  (Master plugged in to a car and presumably
        #                          telling slaves to limit power to 07 d0
        #                          (20.00A). 01 byte indicates Master is plugged
        #                          in to a car.)
        if(self.state == None):
            raise RuntimeError("TWCSlave.send_master_heartbeat requires RuntimeState")
        if(self.general_config == None):
            raise RuntimeError("TWCSlave.send_master_heartbeat requires GeneralConfig")

        debug_level = self.general_config.debug_level
        vehicles = self.car_api.vehicles if self.car_api != None else []
        override_master_heartbeat_data = self.state.overrideMasterHeartbeatData

        if(len(override_master_heartbeat_data) >= 7):
            self.masterHeartbeatData = override_master_heartbeat_data

        if(self.protocolVersion == 2):
            # TODO: Start and stop charging using protocol 2 commands to TWC
            # instead of car api if I ever figure out how.
            if(self.lastAmpsOffered == 0 and self.reportedAmpsActual > 4.0):
                # Car is trying to charge, so stop it via car API.
                # car_api_charge() will prevent telling the car to start or stop
                # more than once per minute. Once the car gets the message to
                # stop, reportedAmpsActualSignificantChangeMonitor should drop
                # to near zero within a few seconds.
                # WARNING: If you own two vehicles and one is charging at home but
                # the other is charging away from home, this command will stop
                # them both from charging.  If the away vehicle is not currently
                # charging, I'm not sure if this would prevent it from charging
                # when next plugged in.
                self.queue_charge_request(
                    False,
                    'car is drawing %.2fA while offered amps is 0.' % (
                        self.reportedAmpsActual,
                    )
                )
            elif(self.lastAmpsOffered >= 5.0 and self.reportedAmpsActual < 2.0
                 and self.reportedState != 0x02
            ):
                # Car is not charging and is not reporting an error state, so
                # try starting charge via car api.
                self.autoStartChargeRequested = True
                self.queue_charge_request(
                    True,
                    'vehicle is present but drawing only %.2fA with %.2fA offered.' % (
                        self.reportedAmpsActual,
                        self.lastAmpsOffered,
                    )
                )
            elif(self.reportedAmpsActual > 4.0):
                # At least one plugged in car is successfully charging. We don't
                # know which car it is, so we must set
                # vehicle.stopAskingToStartCharging = False on all vehicles such
                # that if any vehicle is not charging without us calling
                # car_api_charge(False), we'll try to start it charging again at
                # least once. This probably isn't necessary but might prevent
                # some unexpected case from never starting a charge. It also
                # seems less confusing to see in the output that we always try
                # to start API charging after the car stops taking a charge.
                for vehicle in vehicles:
                    vehicle.stopAskingToStartCharging = False

        send_msg(bytearray(b'\xFB\xE0') + self.local_twc_id + bytearray(self.TWCID)
                 + bytearray(self.masterHeartbeatData), self.state)


    def receive_slave_heartbeat(self, heartbeatData):
        # Handle heartbeat message received from real slave TWC.
        state = self.state
        if(state == None):
            raise RuntimeError("TWCSlave.receive_slave_heartbeat requires RuntimeState")
        if(self.general_config == None or self.energy_config == None):
            raise RuntimeError("TWCSlave.receive_slave_heartbeat requires config")

        debug_level = self.general_config.debug_level
        wiring_max_amps_all_twcs = self.energy_config.wiring_max_amps_all_twcs
        min_amps_per_twc = self.energy_config.min_amps_per_twc

        now                     = time.time()
        prev_reported_state     = self.reportedState
        prev_reported_amps      = self.reportedAmpsActual
        self.timeLastRx         = now
        self.reportedAmpsMax    = ((heartbeatData[1] << 8) + heartbeatData[2]) / 100
        self.reportedAmpsActual = ((heartbeatData[3] << 8) + heartbeatData[4]) / 100
        self.reportedState      = heartbeatData[0]
        self.log_vehicle_state_transition(prev_reported_state, prev_reported_amps)

        # self.lastAmpsOffered is initialized to -1.
        # If we find it at that value, set it to the current value reported by the
        # TWC.
        if (self.lastAmpsOffered < 0):
            self.lastAmpsOffered = self.reportedAmpsMax

        # Keep track of the amps the slave is actually using and the last time it
        # changed by more than 0.8A.
        # Also update self.reportedAmpsActualSignificantChangeMonitor if it's
        # still set to its initial value of -1.
        if (self.reportedAmpsActualSignificantChangeMonitor < 0
           or abs(self.reportedAmpsActual - self.reportedAmpsActualSignificantChangeMonitor) > 0.8
        ):
            self.timeReportedAmpsActualChangedSignificantly = now
            self.reportedAmpsActualSignificantChangeMonitor = self.reportedAmpsActual

        ltNow = time.localtime()
        hourNow = ltNow.tm_hour + (ltNow.tm_min / 60)
        yesterday = ltNow.tm_wday - 1

        if (yesterday < 0):
            yesterday += 7

        # Check if it's time to resume tracking green energy.
        resume_window_end = state.hourResumeTrackGreenEnergy + (1 / 60)
        if(state.nonScheduledAmpsMax != -1
           and state.hourResumeTrackGreenEnergy > -1
           and hourNow >= state.hourResumeTrackGreenEnergy
           and hourNow < resume_window_end
        ):
            state.nonScheduledAmpsMax = -1
            state.timeLastGreenEnergyCheck = 0
            save_settings(state)

        # Check if we're within the hours we must use scheduledAmpsMax instead
        # of nonScheduledAmpsMax
        blnUseScheduledAmps = 0

        if (state.scheduledAmpsMax > 0
             and
           state.scheduledAmpsStartHour > -1
             and
           state.scheduledAmpsEndHour > -1
             and
           state.scheduledAmpsDaysBitmap > 0
        ):
            if (state.scheduledAmpsStartHour > state.scheduledAmpsEndHour):
                # We have a time like 8am to 7am which we must interpret as the
                # 23-hour period after 8am or before 7am. Since this case always
                # crosses midnight, we only ensure that scheduledAmpsDaysBitmap
                # is set for the day the period starts on. For example, if
                # scheduledAmpsDaysBitmap says only schedule on Monday, 8am to
                # 7am, we apply scheduledAmpsMax from Monday at 8am to Monday at
                # 11:59pm, and on Tuesday at 12am to Tuesday at 6:59am.
                if (
                   (
                     is_time_in_window(hourNow, state.scheduledAmpsStartHour, 24)
                       and
                     (state.scheduledAmpsDaysBitmap & (1 << ltNow.tm_wday))
                   )
                     or
                   (
                     is_time_in_window(hourNow, 0, state.scheduledAmpsEndHour)
                       and
                     (state.scheduledAmpsDaysBitmap & (1 << yesterday))
                   )
                ):
                   blnUseScheduledAmps = 1
            else:
                # We have a time like 7am to 8am which we must interpret as the
                # 1-hour period between 7am and 8am.
                if(is_time_in_window(hourNow, state.scheduledAmpsStartHour, state.scheduledAmpsEndHour)
                   and (state.scheduledAmpsDaysBitmap & (1 << ltNow.tm_wday))
                ):
                   blnUseScheduledAmps = 1

        if (state.chargeNowTimeEnd > 0 and state.chargeNowTimeEnd < now):
            # We're beyond the one-day period where we want to charge at
            # chargeNowAmps, so reset the chargeNow variables.
            state.chargeNowAmps = 0
            state.chargeNowTimeEnd = 0

        if (state.chargeNowTimeEnd > 0 and state.chargeNowAmps > 0):
            # We're still in the one-day period where we want to charge at
            # chargeNowAmps, ignoring all other charging criteria.
            state.maxAmpsToDivideAmongSlaves = state.chargeNowAmps

            if (debug_level >= 10):
                print(time_now() + ': Charge at chargeNowAmps %.2f' % (state.chargeNowAmps))
        elif (blnUseScheduledAmps):
            # We're within the scheduled hours that we need to provide a set
            # number of amps.
            state.maxAmpsToDivideAmongSlaves = state.scheduledAmpsMax
        else:
            if (state.nonScheduledAmpsMax > -1):
                state.maxAmpsToDivideAmongSlaves = state.nonScheduledAmpsMax
            elif (now - state.timeLastGreenEnergyCheck > 60):
                state.timeLastGreenEnergyCheck = now

                # Don't bother to check solar generation before 6am or after
                # 8pm. Sunrise in most U.S. areas varies from a little before
                # 6am in Jun to almost 7:30am in Nov before the clocks get set
                # back an hour. Sunset can be ~4:30pm to just after 8pm.
                if (ltNow.tm_hour < 6 or ltNow.tm_hour >= 20):
                    state.maxAmpsToDivideAmongSlaves = 0
                else:
                    queue_background_task({'cmd':'checkGreenEnergy'})
            elif (6 <= ltNow.tm_hour < 20 and state.maxAmpsToDivideAmongSlaves == 0):
                # After a long overnight idle period, request a fresh solar
                # reading as soon as daytime returns instead of waiting for the
                # previous nighttime zero to linger.
                state.timeLastGreenEnergyCheck = now
                queue_background_task({'cmd':'checkGreenEnergy'})

        # Use backgroundTasksLock to prevent the background thread from changing
        # the value of maxAmpsToDivideAmongSlaves after we've checked the value
        # is safe to use but before we've used it.
        backgroundTasksLock.acquire()
        try:
            if (state.maxAmpsToDivideAmongSlaves > wiring_max_amps_all_twcs):
                # Never tell the slaves to draw more amps than the physical charger
                # wiring can handle.
                if (debug_level >= 1):
                    print(time_now() +
                        " ERROR: maxAmpsToDivideAmongSlaves " + str(state.maxAmpsToDivideAmongSlaves) +
                        " > wiringMaxAmpsAllTWCs " + str(wiring_max_amps_all_twcs) +
                        ".\nSee notes above wiringMaxAmpsAllTWCs in the 'Configuration parameters' section.")

                state.maxAmpsToDivideAmongSlaves = wiring_max_amps_all_twcs

            # Determine how many cars are charging and how many amps they're using
            numCarsCharging    = 1
            desiredAmpsOffered = state.maxAmpsToDivideAmongSlaves

            for slaveTWC in state.slaveTWCRoundRobin:
                if (slaveTWC.TWCID != self.TWCID):
                    # To avoid exceeding maxAmpsToDivideAmongSlaves, we must
                    # subtract the actual amps being used by this TWC from the amps
                    # we will offer.
                    desiredAmpsOffered -= slaveTWC.reportedAmpsActual

                    if(slaveTWC.reportedAmpsActual >= 1.0):
                        numCarsCharging += 1

            # Allocate this slave a fraction of maxAmpsToDivideAmongSlaves divided
            # by the number of cars actually charging.
            fairShareAmps = int(state.maxAmpsToDivideAmongSlaves / numCarsCharging)

            if (desiredAmpsOffered > fairShareAmps):
                desiredAmpsOffered = fairShareAmps

            if (debug_level >= 10):
                print("desiredAmpsOffered reduced from " + str(state.maxAmpsToDivideAmongSlaves)
                      + " to " + str(desiredAmpsOffered)
                      + " with " + str(numCarsCharging)
                      + " cars charging.")
        finally:
            backgroundTasksLock.release()

        minAmpsToOffer = min_amps_per_twc

        if (self.minAmpsTWCSupports > minAmpsToOffer):
            minAmpsToOffer = self.minAmpsTWCSupports

        if (desiredAmpsOffered < minAmpsToOffer):
            if (state.maxAmpsToDivideAmongSlaves / numCarsCharging > minAmpsToOffer):
                # There is enough power available to give each car
                # minAmpsToOffer, but currently-charging cars are leaving us
                # less power than minAmpsToOffer to give this car.
                #
                # minAmpsToOffer is based on minAmpsPerTWC which is
                # user-configurable, whereas self.minAmpsTWCSupports is based on
                # the minimum amps TWC must be set to reliably start a car
                # charging.
                #
                # Unfortunately, we can't tell if a car is plugged in or wanting
                # to charge without offering it minAmpsTWCSupports. As the car
                # gradually starts to charge, we will see it using power and
                # tell other TWCs on the network to use less power. This could
                # cause the sum of power used by all TWCs to exceed
                # wiringMaxAmpsAllTWCs for a few seconds, but I don't think
                # exceeding by up to minAmpsTWCSupports for such a short period
                # of time will cause problems.
                if (debug_level >= 10):
                    print("desiredAmpsOffered increased from " + str(desiredAmpsOffered)
                          + " to " + str(self.minAmpsTWCSupports)
                          + " (self.minAmpsTWCSupports)")

                desiredAmpsOffered = self.minAmpsTWCSupports
            else:
                # There is not enough power available to give each car
                # minAmpsToOffer, so don't offer power to any cars. Alternately,
                # we could charge one car at a time and switch cars
                # periodically, but I'm not going to try to implement that.
                #
                # Note that 5A is the lowest value you can set using the Tesla car's
                # main screen, so lower values might have some adverse affect on the
                # car. I actually tried lower values when the sun was providing
                # under 5A of power and found the car would occasionally set itself
                # to state 03 and refuse to charge until you re-plugged the charger
                # cable. Clicking "Start charging" in the car's UI or in the phone
                # app would not start charging.
                #
                # A 5A charge only delivers ~3 miles of range to the car per hour,
                # but it forces the car to remain "on" at a level that it wastes
                # some power while it's charging. The lower the amps, the more power
                # is wasted. This is another reason not to go below 5A.
                #
                # So if there isn't at least 5A of power available, pass 0A as the
                # desired value. This tells the car to stop charging and it will
                # enter state 03 and go to sleep. You will hear the power relay in
                # the TWC turn off. When desiredAmpsOffered trends above 6A again,
                # it tells the car there's power.
                # If a car is set to energy saver mode in the car's UI, the car
                # seems to wake every 15 mins or so (unlocking or using phone app
                # also wakes it) and next time it wakes, it will see there's power
                # and start charging. Without energy saver mode, the car should
                # begin charging within about 10 seconds of changing this value.
                if(debug_level >= 10):
                    print("desiredAmpsOffered reduced to 0 from " + str(desiredAmpsOffered)
                          + " because maxAmpsToDivideAmongSlaves "
                          + str(state.maxAmpsToDivideAmongSlaves)
                          + " / numCarsCharging " + str(numCarsCharging)
                          + " < minAmpsToOffer " + str(minAmpsToOffer))
                desiredAmpsOffered = 0

            if (
                   not self.autoStartChargeRequested
                     and
                   self.lastAmpsOffered > 0
                     and
                   (
                     now - self.timeLastAmpsOfferedChanged < 60
                       or
                     now - self.timeReportedAmpsActualChangedSignificantly < 60
                       or
                     self.reportedAmpsActual < 4.0
                   )
                ):
                    # We were previously telling the car to charge but now we want
                    # to tell it to stop. However, it's been less than a minute
                    # since we told it to charge or since the last significant
                    # change in the car's actual power draw or the car has not yet
                    # started to draw at least 5 amps (telling it 5A makes it
                    # actually draw around 4.18-4.27A so we check for
                    # self.reportedAmpsActual < 4.0).
                    #
                    # Once we tell the car to charge, we want to keep it going for
                    # at least a minute before turning it off again. concern is that
                    # yanking the power at just the wrong time during the
                    # start-charge negotiation could put the car into an error state
                    # where it won't charge again without being re-plugged. This
                    # concern is hypothetical and most likely could not happen to a
                    # real car, but I'd rather not take any chances with getting
                    # someone's car into a non-charging state so they're stranded
                    # when they need to get somewhere. Note that non-Tesla cars
                    # using third-party adapters to plug in are at a higher risk of
                    # encountering this sort of hypothetical problem.
                    #
                    # The other reason for this tactic is that in the minute we
                    # wait, desiredAmpsOffered might rise above 5A in which case we
                    # won't have to turn off the charger power at all. Avoiding too
                    # many on/off cycles preserves the life of the TWC's main power
                    # relay and may also prevent errors in the car that might be
                    # caused by turning its charging on and off too rapidly.
                    #
                    # Seeing self.reportedAmpsActual < 4.0 means the car hasn't
                    # ramped up to whatever level we told it to charge at last time.
                    # It may be asleep and take up to 15 minutes to wake up, see
                    # there's power, and start charging.
                    #
                    # Unfortunately, self.reportedAmpsActual < 4.0 can also mean the
                    # car is at its target charge level and may not accept power for
                    # days until the battery drops below a certain level. I can't
                    # think of a reliable way to detect this case. When the car
                    # stops itself from charging, we'll see self.reportedAmpsActual
                    # drop to near 0.0A and heartbeatData[0] becomes 03, but we can
                    # see the same 03 state when we tell the TWC to stop charging.
                    # We could record the time the car stopped taking power and
                    # assume it won't want more for some period of time, but we
                    # can't reliably detect if someone unplugged the car, drove it,
                    # and re-plugged it so it now needs power, or if someone plugged
                    # in a different car that needs power. Even if I see the car
                    # hasn't taken the power we've offered for the
                    # last hour, it's conceivable the car will reach a battery state
                    # where it decides it wants power the moment we decide it's safe
                    # to stop offering it. Thus, I think it's safest to always wait
                    # until the car has taken 5A for a minute before cutting power
                    # even if that means the car will charge for a minute when you
                    # first plug it in after a trip even at a time when no power
                    # should be available.
                    #
                    # One advantage of the above situation is that whenever you plug
                    # the car in, unless no power has been available since you
                    # unplugged, the charge port will turn green and start charging
                    # for a minute. This lets the owner quickly see that TWCManager
                    # is working properly each time they return home and plug in.
                    if(debug_level >= 10):
                        print("Don't stop charging yet because: " +
                              'time - self.timeLastAmpsOfferedChanged ' +
                              str(int(now - self.timeLastAmpsOfferedChanged)) +
                              ' < 60 or time - self.timeReportedAmpsActualChangedSignificantly ' +
                              str(int(now - self.timeReportedAmpsActualChangedSignificantly)) +
                              ' < 60 or self.reportedAmpsActual ' + str(self.reportedAmpsActual) +
                              ' < 4')
                    desiredAmpsOffered = minAmpsToOffer
            elif (self.autoStartChargeRequested and debug_level >= 10):
                print(time_now() + ': TWC %02X%02X auto-started charge no longer has enough '
                      'available power (below minAmpsToOffer %.2fA).' %
                      (self.TWCID[0], self.TWCID[1], minAmpsToOffer))
        else:
            # We can tell the TWC how much power to use in 0.01A increments, but
            # the car will only alter its power in larger increments (somewhere
            # between 0.5 and 0.6A). The car seems to prefer being sent whole
            # amps and when asked to adjust between certain values like 12.6A
            # one second and 12.0A the next second, the car reduces its power
            # use to ~5.14-5.23A and refuses to go higher. So it seems best to
            # stick with whole amps.
            desiredAmpsOffered = int(desiredAmpsOffered)

            if (self.lastAmpsOffered == 0
               and now - self.timeLastAmpsOfferedChanged < 60
            ):
                # Keep charger off for at least 60 seconds before turning back
                # on. See reasoning above where I don't turn the charger off
                # till it's been on at least 60 seconds.
                if (debug_level >= 10):
                    print("Don't start charging yet because: " +
                          'self.lastAmpsOffered ' +
                          str(self.lastAmpsOffered) + " == 0 " +
                          'and time - self.timeLastAmpsOfferedChanged ' +
                          str(int(now - self.timeLastAmpsOfferedChanged)) +
                          " < 60")

                desiredAmpsOffered = self.lastAmpsOffered
            else:
                # Mid Oct 2017, Tesla pushed a firmware update to their cars
                # that seems to create the following bug:
                # If you raise desiredAmpsOffered AT ALL from the car's current
                # max amp limit, the car will drop its max amp limit to the 6A
                # setting (5.14-5.23A actual use as reported in
                # heartbeatData[2-3]). The odd fix to this problem is to tell
                # the car to raise to at least spikeAmpsToCancel6ALimit for 5 or
                # more seconds, then tell it to lower the limit to
                # desiredAmpsOffered. Even 0.01A less than
                # spikeAmpsToCancel6ALimit is not enough to cancel the 6A limit.
                #
                # I'm not sure how long we have to hold spikeAmpsToCancel6ALimit
                # but 3 seconds is definitely not enough but 5 seconds seems to
                # work. It doesn't seem to matter if the car actually hits
                # spikeAmpsToCancel6ALimit of power draw. In fact, the car is
                # slow enough to respond that even with 10s at 21A the most I've
                # seen it actually draw starting at 6A is 13A.
                if (debug_level >= 10):
                    print('desiredAmpsOffered=' + str(desiredAmpsOffered) +
                          ' spikeAmpsToCancel6ALimit=' + str(state.spikeAmpsToCancel6ALimit) +
                          ' self.lastAmpsOffered=' + str(self.lastAmpsOffered) +
                          ' self.reportedAmpsActual=' + str(self.reportedAmpsActual) +
                          ' now - self.timeReportedAmpsActualChangedSignificantly=' +
                          str(int(now - self.timeReportedAmpsActualChangedSignificantly)))

                if (
                    # If we just moved from a lower amp limit to
                    # a higher one less than spikeAmpsToCancel6ALimit.
                   (
                     desiredAmpsOffered < state.spikeAmpsToCancel6ALimit
                       and
                     desiredAmpsOffered > self.lastAmpsOffered
                   )
                      or
                   (
                     # ...or if we've been offering the car more amps than it's
                     # been using for at least 10 seconds, then we'll change the
                     # amps we're offering it. For some reason, the change in
                     # amps offered will get the car to up its amp draw.
                     #
                     # First, check that the car is drawing enough amps to be
                     # charging...
                     self.reportedAmpsActual > 2.0
                       and
                     # ...and car is charging at under spikeAmpsToCancel6ALimit.
                     # I think I've seen cars get stuck between spikeAmpsToCancel6ALimit
                     # and lastAmpsOffered, but more often a car will be limited
                     # to under lastAmpsOffered by its UI setting or by the
                     # charger hardware it has on board, and we don't want to
                     # keep reducing it to spikeAmpsToCancel6ALimit.
                     # If cars really are getting stuck above
                     # spikeAmpsToCancel6ALimit, I may need to implement a
                     # counter that tries spikeAmpsToCancel6ALimit only a
                     # certain number of times per hour.
                     (self.reportedAmpsActual <= state.spikeAmpsToCancel6ALimit)
                       and
                     # ...and car is charging at over two amps under what we
                     # want it to charge at. I have to use 2 amps because when
                     # offered, say 40A, the car charges at ~38.76A actual.
                     # Using a percentage instead of 2.0A doesn't work because
                     # 38.58/40 = 95.4% but 5.14/6 = 85.6%
                     (self.lastAmpsOffered - self.reportedAmpsActual) > 2.0
                       and
                     # ...and car hasn't changed its amp draw significantly in
                     # over 10 seconds, meaning it's stuck at its current amp
                     # draw.
                     now - self.timeReportedAmpsActualChangedSignificantly > 10
                   )
                ):
                    # We must set desiredAmpsOffered to a value that gets
                    # reportedAmpsActual (amps the car is actually using) up to
                    # a value near lastAmpsOffered. At the end of all these
                    # checks, we'll set lastAmpsOffered = desiredAmpsOffered and
                    # timeLastAmpsOfferedChanged if the value of lastAmpsOffered was
                    # actually changed.
                    if(self.lastAmpsOffered == state.spikeAmpsToCancel6ALimit
                       and now - self.timeLastAmpsOfferedChanged > 10):
                        # We've been offering the car spikeAmpsToCancel6ALimit
                        # for over 10 seconds but it's still drawing at least
                        # 2A less than spikeAmpsToCancel6ALimit.  I saw this
                        # happen once when an error stopped the car from
                        # charging and when the error cleared, it was offered
                        # spikeAmpsToCancel6ALimit as the first value it saw.
                        # The car limited itself to 6A indefinitely. In this
                        # case, the fix is to offer it lower amps.
                        if(debug_level >= 1):
                            print(time_now() + ': Car stuck when offered spikeAmpsToCancel6ALimit.  Offering 2 less.')
                        desiredAmpsOffered = state.spikeAmpsToCancel6ALimit - 2.0
                    elif(now - self.timeLastAmpsOfferedChanged > 5):
                        # self.lastAmpsOffered hasn't gotten the car to draw
                        # enough amps for over 5 seconds, so try
                        # spikeAmpsToCancel6ALimit
                        desiredAmpsOffered = state.spikeAmpsToCancel6ALimit
                    else:
                        # Otherwise, don't change the value of lastAmpsOffered.
                        desiredAmpsOffered = self.lastAmpsOffered

                    # Note that the car should have no problem increasing max
                    # amps to any whole value over spikeAmpsToCancel6ALimit as
                    # long as it's below any upper limit manually set in the
                    # car's UI. One time when I couldn't get TWC to push the car
                    # over 21A, I found the car's UI had set itself to 21A
                    # despite setting it to 40A the day before. I have been
                    # unable to reproduce whatever caused that problem.
                elif(desiredAmpsOffered < self.lastAmpsOffered):
                    # Tesla accepts lower amp limits while charging, so apply
                    # normal reductions immediately. The only time we hold the
                    # previous value is when we're intentionally keeping
                    # spikeAmpsToCancel6ALimit active long enough for the car to
                    # observe the temporary spike.
                    if(debug_level >= 10):
                        print('Reduce amps: time - self.timeLastAmpsOfferedChanged ' +
                            str(int(now - self.timeLastAmpsOfferedChanged)))
                    if(self.lastAmpsOffered == state.spikeAmpsToCancel6ALimit
                       and now - self.timeLastAmpsOfferedChanged < 5):
                        desiredAmpsOffered = self.lastAmpsOffered

        # set_last_amps_offered does some final checks to see if the new
        # desiredAmpsOffered is safe. It should be called after we've picked a
        # final value for desiredAmpsOffered.
        desiredAmpsOffered = self.set_last_amps_offered(desiredAmpsOffered)
        send_immediately = self.reportedAmpsMax > desiredAmpsOffered

        # See notes in send_slave_heartbeat() for details on how we transmit
        # desiredAmpsOffered and the meaning of the code in
        # self.masterHeartbeatData[0].
        #
        # Rather than only sending desiredAmpsOffered when slave is sending code
        # 04 or 08, it seems to work better to send desiredAmpsOffered whenever
        # it does not equal self.reportedAmpsMax reported by the slave TWC.
        # Doing it that way will get a slave charging again even when it's in
        # state 00 or 03 which it swings between after you set
        # desiredAmpsOffered = 0 to stop charging.
        #
        # I later found that a slave may end up swinging between state 01 and 03
        # when desiredAmpsOffered == 0:
        #   S 032e 0.25/0.00A: 01 0000 0019 0000  M: 00 0000 0000 0000
        #   S 032e 0.25/6.00A: 03 0258 0019 0000  M: 05 0000 0000 0000
        #   S 032e 0.25/0.00A: 01 0000 0019 0000  M: 00 0000 0000 0000
        #   S 032e 0.25/6.00A: 03 0258 0019 0000  M: 05 0000 0000 0000
        #
        # While it's doing this, it's continuously opening and closing the relay
        # on the TWC each second which makes an audible click and will wear out
        # the relay. To avoid that problem, always send code 05 when
        # desiredAmpsOffered == 0. In that case, slave's response should always
        # look like this:
        #   S 032e 0.25/0.00A: 03 0000 0019 0000 M: 05 0000 0000 0000
        if(self.autoStartChargeRequested and desiredAmpsOffered == 0):
            self.queue_charge_request(
                False,
                'auto-started charging no longer has enough available power.'
            )

        if(self.reportedAmpsMax != desiredAmpsOffered
           or desiredAmpsOffered == 0
        ):
            desiredHundredthsOfAmps = int(desiredAmpsOffered * 100)
            self.masterHeartbeatData = bytearray([(0x09 if self.protocolVersion == 2 else 0x05),
              (desiredHundredthsOfAmps >> 8) & 0xFF,
              desiredHundredthsOfAmps & 0xFF,
              0x00,0x00,0x00,0x00,0x00,0x00])
        else:
            self.masterHeartbeatData = bytearray([0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00])

        if(len(state.overrideMasterHeartbeatData) >= 7):
            self.masterHeartbeatData = state.overrideMasterHeartbeatData

        if(debug_level >= 1):
            self.print_status(heartbeatData)

        return send_immediately


    def set_last_amps_offered(self, desiredAmpsOffered):
        # self.lastAmpsOffered should only be changed using this sub.
        state = self.state
        if(state == None):
            raise RuntimeError("TWCSlave.set_last_amps_offered requires RuntimeState")
        if(self.general_config == None or self.energy_config == None):
            raise RuntimeError("TWCSlave.set_last_amps_offered requires config")

        debug_level = self.general_config.debug_level
        wiring_max_amps_all_twcs = self.energy_config.wiring_max_amps_all_twcs

        if(debug_level >= 10):
            print("set_last_amps_offered(TWCID=" + hex_str(self.TWCID) +
                  ", desiredAmpsOffered=" + str(desiredAmpsOffered) + ")")

        if(desiredAmpsOffered != self.lastAmpsOffered):
            oldLastAmpsOffered = self.lastAmpsOffered
            self.lastAmpsOffered = desiredAmpsOffered

            # Set totalAmpsAllTWCs to the total amps all TWCs are actually using
            # minus amps this TWC is using, plus amps this TWC wants to use.
            totalAmpsAllTWCs = total_amps_actual_all_twcs(state, self.general_config) \
                  - self.reportedAmpsActual + self.lastAmpsOffered
            if(totalAmpsAllTWCs > wiring_max_amps_all_twcs):
                # totalAmpsAllTWCs would exceed wiringMaxAmpsAllTWCs if we
                # allowed this TWC to use desiredAmpsOffered.  Instead, try
                # offering as many amps as will increase total_amps_actual_all_twcs()
                # up to wiringMaxAmpsAllTWCs.
                self.lastAmpsOffered = int(wiring_max_amps_all_twcs -
                                          (total_amps_actual_all_twcs(state, self.general_config) - self.reportedAmpsActual))

                if(self.lastAmpsOffered < self.minAmpsTWCSupports):
                    # Always offer at least minAmpsTWCSupports amps.
                    # See notes in receive_slave_heartbeat() beneath
                    # 'if(maxAmpsToDivideAmongSlaves / numCarsCharging > minAmpsToOffer):'
                    self.lastAmpsOffered = self.minAmpsTWCSupports

                print("WARNING: Offering slave TWC %02X%02X %.1fA instead of " \
                    "%.1fA to avoid overloading wiring shared by all TWCs." % (
                    self.TWCID[0], self.TWCID[1], self.lastAmpsOffered, desiredAmpsOffered))

            if(self.lastAmpsOffered > self.wiringMaxAmps):
                # We reach this case frequently in some configurations, such as
                # when two 80A TWCs share a 125A line.  Therefore, don't print
                # an error.
                self.lastAmpsOffered = self.wiringMaxAmps
                if(debug_level >= 10):
                    print("Offering slave TWC %02X%02X %.1fA instead of " \
                        "%.1fA to avoid overloading the TWC rated at %.1fA." % (
                        self.TWCID[0], self.TWCID[1], self.lastAmpsOffered,
                        desiredAmpsOffered, self.wiringMaxAmps))

            if(self.lastAmpsOffered != oldLastAmpsOffered):
                self.timeLastAmpsOfferedChanged = time.time()
        return self.lastAmpsOffered

#
# End TWCSlave class
#
##############################


##############################
#
# Begin global vars
#

data               = ''
dataLen            = 0
ignoredData        = bytearray()
msg                = bytearray()
msgLen             = 0
lastTWCResponseMsg = None
overrideMasterHeartbeatData = b''

masterTWCID        = ''
slaveHeartbeatData = bytearray([0x01,0x0F,0xA0,0x0F,0xA0,0x00,0x00,0x00,0x00])
numInitMsgsToSend  = 10
msgRxCount         = 0
timeLastTx         = 0

slaveTWCs          = {}
slaveTWCRoundRobin = []
idxSlaveToSendNextHeartbeat = 0

maxAmpsToDivideAmongSlaves = 0
scheduledAmpsMax           = -1
scheduledAmpsStartHour     = -1
scheduledAmpsEndHour       = -1
scheduledAmpsDaysBitmap    = 0x7F

chargeNowAmps      = 0
chargeNowTimeEnd   = 0

spikeAmpsToCancel6ALimit   = 16
timeLastGreenEnergyCheck   = 0
hourResumeTrackGreenEnergy = -1
greenEnergyRateLimitedUntil = 0
kWhDelivered               = 119
timeLastkWhDelivered       = time.time()
timeLastkWhSaved           = time.time()

# __FILE__ contains the path to the running script. Replace the script name with
# TWCManagerSettings.txt. This gives us a path that will always locate
# TWCManagerSettings.txt in the same directory as the script even when pwd does
# not match the script directory.
settingsFileName = re.sub(r'/[^/]+$', r'/TWCManagerSettings.txt', __file__)
logFileName = '/var/log/twcmanager.log'
# Tesla API tokens live in a separate JSON file to avoid mixing secrets with
# ordinary runtime settings. Set TESLA_API_TOKEN_FILE to override this path.
# Expected JSON keys: access_token, refresh_token, expires_at (unix timestamp).
teslaApiTokenFileName = os.environ.get(
    'TESLA_API_TOKEN_FILE',
    re.sub(r'/[^/]+$', r'/TeslaApiTokens.json', __file__)
)
nonScheduledAmpsMax = -1
timeLastHeartbeatDebugOutput = 0
webMsgPacked = ''
webMsgMaxSize = 300
webMsgResult = 0

timeTo0Aafter06 = 0
timeToRaise2A = 0

homeLat = 10000
homeLon = 10000

backgroundTasksQueue = queue.Queue()
backgroundTasksCmds = {}
backgroundTasksLock = threading.Lock()
backgroundTasksThread = None

ser = None
webIPCqueue = None
webIPCkey = None
energy_history_store = None

#
# End global vars
#
##############################


##############################
#
# Begin main program
#

class RuntimeState:
    """Mutable process state for protocol parsing and charging decisions."""
    def __init__(self):
        self.data = ''
        self.dataLen = 0
        self.ignoredData = bytearray()
        self.msg = bytearray()
        self.msgLen = 0
        self.lastTWCResponseMsg = None
        self.overrideMasterHeartbeatData = b''
        self.masterTWCID = ''
        self.slaveHeartbeatData = bytearray([0x01,0x0F,0xA0,0x0F,0xA0,0x00,0x00,0x00,0x00])
        self.numInitMsgsToSend = 10
        self.msgRxCount = 0
        self.timeLastTx = 0
        self.slaveTWCs = {}
        self.slaveTWCRoundRobin = []
        self.idxSlaveToSendNextHeartbeat = 0
        self.maxAmpsToDivideAmongSlaves = 0
        self.scheduledAmpsMax = -1
        self.scheduledAmpsStartHour = -1
        self.scheduledAmpsEndHour = -1
        self.scheduledAmpsDaysBitmap = 0x7F
        self.chargeNowAmps = 0
        self.chargeNowTimeEnd = 0
        self.spikeAmpsToCancel6ALimit = 16
        self.timeLastGreenEnergyCheck = 0
        self.hourResumeTrackGreenEnergy = -1
        self.greenEnergyRateLimitedUntil = 0
        self.greenEnergyAvailableAmps = 0.0
        self.kWhDelivered = 119
        self.timeLastkWhDelivered = time.time()
        self.timeLastkWhSaved = time.time()
        self.energyHistory = {
            'version': 1,
            'last_total_kwh': 0.0,
            'last_update_ts': 0.0,
            'daily': {},
        }
        self.nonScheduledAmpsMax = -1
        self.timeTo0Aafter06 = 0
        self.timeToRaise2A = 0

class TWCManagerApp:
    """Wire dependencies together and run the main charger-control loop."""
    def __init__(self):
        global settings_store, tesla_token_store, tesla_car_api, green_energy_monitor, \
               energy_history_store, \
               background_task_runner, backgroundTasksQueue, backgroundTasksCmds, \
               backgroundTasksLock, backgroundTasksThread, rs485_transport, ser, \
               web_ipc_server, webIPCkey, webIPCqueue

        self.general_config = GeneralConfig(
            debug_level=debugLevel,
            display_milliseconds=displayMilliseconds,
            log_file=logFileName,
            fake_master=fakeMaster,
            fake_twc_id=fakeTWCID,
            master_sign=masterSign,
            slave_sign=slaveSign,
            settings_file_name=settingsFileName,
        )
        self.energy_config = EnergyConfig(
            wiring_max_amps_all_twcs=wiringMaxAmpsAllTWCs,
            wiring_max_amps_per_twc=wiringMaxAmpsPerTWC,
            min_amps_per_twc=minAmpsPerTWC,
            min_amps_twc_supports=minAmpsTWCSupports,
            green_energy_amps_offset=greenEnergyAmpsOffset,
        )
        self.tesla_api_config = TeslaApiConfig(
            only_charge_multi_cars_at_home=onlyChargeMultiCarsAtHome,
            home_lat=homeLat,
            home_lon=homeLon,
        )
        self.rs485_config = RS485Config(
            adapter=rs485Adapter,
            baud_rate=baud,
            timeout=0,
        )
        self.web_ipc_config = WebIPCConfig(script_path=__file__)
        self.state = RuntimeState()
        settings_store = SettingsStore(self.general_config)
        energy_history_store = EnergyHistoryStore(self.general_config)
        tesla_token_store = TeslaTokenStore(teslaApiTokenFileName)
        tesla_car_api = TeslaCarApi(self.tesla_api_config, tesla_token_store)
        self.state.energyHistory = energy_history_store.load()
        load_settings(
            self.state,
            tesla_car_api,
            self.tesla_api_config,
            self.general_config,
            self.energy_config,
            self.rs485_config,
        )
        configure_log_output(self.general_config)
        tesla_car_api.load_tokens()
        green_energy_monitor = GreenEnergyMonitor(self.energy_config, backgroundTasksLock, self.state)
        background_task_runner = BackgroundTaskRunner(
            car_api_available,
            car_api_charge,
            check_green_energy,
            backgroundTasksLock,
        )
        backgroundTasksQueue = background_task_runner.queue
        backgroundTasksCmds = background_task_runner.cmds
        backgroundTasksLock = background_task_runner.lock
        backgroundTasksThread = background_task_runner.thread
        background_task_runner.start()
        rs485_transport = RS485Transport(self.rs485_config)
        ser = rs485_transport.serial
        web_ipc_server = WebIPCServer(self.web_ipc_config.script_path)
        webIPCkey = web_ipc_server.key
        webIPCqueue = web_ipc_server.queue
        if(webIPCqueue == None):
            print("ERROR: Can't create Interprocess Communication message queue to communicate with web interface.")

    def print_startup_banner(self):
        print("TWC Manager starting as fake %s with id %02X%02X and sign %02X" \
            % ( ("Master" if self.general_config.fake_master else "Slave"), \
            ord(self.general_config.fake_twc_id[0:1]), ord(self.general_config.fake_twc_id[1:2]),
            ord(self.general_config.slave_sign)))

    def run(self):
        state = self.state
        general_config = self.general_config
        energy_config = self.energy_config

        # This loop is the execution core of the application. It interleaves:
        # 1. periodic protocol transmissions,
        # 2. IPC commands from the web UI, and
        # 3. byte-by-byte RS-485 frame assembly and decoding.
        while True:
            try:
                time.sleep(0.025)

                now = time.time()
                update_energy_tracking(state, now, general_config)

                if(general_config.fake_master == 1):
                    if(state.numInitMsgsToSend > 5):
                        send_master_linkready1(state, general_config)
                        time.sleep(0.1)
                        state.numInitMsgsToSend -= 1
                    elif(state.numInitMsgsToSend > 0):
                        send_master_linkready2(state, general_config)
                        time.sleep(0.1)
                        state.numInitMsgsToSend = state.numInitMsgsToSend - 1
                    else:
                        if(time.time() - state.timeLastTx >= 1.0):
                            if(len(state.slaveTWCRoundRobin) > 0):
                                slaveTWC = state.slaveTWCRoundRobin[state.idxSlaveToSendNextHeartbeat]
                                if(time.time() - slaveTWC.timeLastRx > 26):
                                    print(time_now() + ": WARNING: We haven't heard from slave " \
                                        "%02X%02X for over 26 seconds.  " \
                                        "Stop sending them heartbeat messages." % \
                                        (slaveTWC.TWCID[0], slaveTWC.TWCID[1]))
                                    delete_slave(state, slaveTWC.TWCID)
                                else:
                                    slaveTWC.send_master_heartbeat()

                                state.idxSlaveToSendNextHeartbeat = state.idxSlaveToSendNextHeartbeat + 1
                                if(state.idxSlaveToSendNextHeartbeat >= len(state.slaveTWCRoundRobin)):
                                    state.idxSlaveToSendNextHeartbeat = 0
                                time.sleep(0.1)
                else:
                    if(general_config.fake_master != 2 and time.time() - state.timeLastTx >= 10.0):
                        if(general_config.debug_level >= 1):
                            print("Advertise fake slave %02X%02X with sign %02X is " \
                                  "ready to link once per 10 seconds as long as master " \
                                  "hasn't sent a heartbeat in the last 10 seconds." % \
                                (ord(general_config.fake_twc_id[0:1]), ord(general_config.fake_twc_id[1:2]),
                                 ord(general_config.slave_sign)))
                        send_slave_linkready(state, general_config=general_config)

                try:
                    webMsgRaw = web_ipc_server.receive()
                    if(len(webMsgRaw[0]) > 0):
                        webMsgType = webMsgRaw[1]
                        unpacked = struct.unpack('=LH', webMsgRaw[0][0:6])
                        webMsgTime = unpacked[0]
                        webMsgID = unpacked[1]
                        webMsg = webMsgRaw[0][6:len(webMsgRaw[0])]
                        webMsg, webMsgMeta = parse_web_ipc_message(webMsg)

                        if(general_config.debug_level >= 1):
                            webMsgRedacted = webMsg
                            m = re.search(b'^(carApiTokens=)', webMsg, re.MULTILINE)
                            if(m):
                                webMsgRedacted = m.group(1) + b'[HIDDEN]'
                            command_label = webMsgRedacted.decode('utf-8', 'replace')
                            client_addr = str(webMsgMeta.get('client', '')).strip()
                            if(client_addr == ''):
                                client_addr = 'unknown'
                            print(time_now() + ': Web: client=' + client_addr
                                               + ', command=' + command_label)
                        webResponseMsg = ''
                        numPackets = 0
                        if(webMsg == b'getStatus'):
                            needCarApiBearerToken = False
                            teslaApiOperational = False
                            teslaApiState = 'not_configured'
                            energy_periods = calculate_energy_periods(state.energyHistory, now)
                            if(tesla_car_api != None and tesla_car_api.has_tokens() == False):
                                for i in range(0, len(state.slaveTWCRoundRobin)):
                                    if(state.slaveTWCRoundRobin[i].protocolVersion == 2):
                                        needCarApiBearerToken = True

                            if(tesla_car_api != None):
                                if(tesla_car_api.has_tokens() == False):
                                    teslaApiState = (
                                        'tokens_required' if needCarApiBearerToken else 'not_connected'
                                    )
                                elif(now - tesla_car_api.last_error_time
                                     < tesla_car_api.error_retry_mins * 60):
                                    teslaApiState = 'error'
                                elif(tesla_car_api.bearer_token != ''
                                     and tesla_car_api.token_expire_time > now):
                                    teslaApiOperational = True
                                    teslaApiState = 'operational'
                                else:
                                    teslaApiState = 'not_operational'

                            webResponseMsg = (
                                "%.2f" % (state.maxAmpsToDivideAmongSlaves) +
                                '`' + "%.2f" % (energy_config.wiring_max_amps_all_twcs) +
                                '`' + "%.2f" % (energy_config.min_amps_per_twc) +
                                '`' + "%.2f" % (state.chargeNowAmps) +
                                '`' + str(state.nonScheduledAmpsMax) +
                                '`' + str(state.scheduledAmpsMax) +
                                '`' + "%02d:%02d" % (int(state.scheduledAmpsStartHour),
                                                     int((state.scheduledAmpsStartHour % 1) * 60)) +
                                '`' + "%02d:%02d" % (int(state.scheduledAmpsEndHour),
                                                     int((state.scheduledAmpsEndHour % 1) * 60)) +
                                '`' + str(state.scheduledAmpsDaysBitmap) +
                                '`' + "%02d:%02d" % (int(state.hourResumeTrackGreenEnergy),
                                                     int((state.hourResumeTrackGreenEnergy % 1) * 60)) +
                                '`' + ('1' if needCarApiBearerToken else '0') +
                                '`' + ('1' if teslaApiOperational else '0') +
                                '`' + teslaApiState +
                                '`' + str(int(max(0, state.chargeNowTimeEnd - now))) +
                                '`' + ("%.3f" % (state.kWhDelivered)) +
                                '`' + ("%.3f" % (energy_periods['today'])) +
                                '`' + ("%.3f" % (energy_periods['week'])) +
                                '`' + ("%.3f" % (energy_periods['month'])) +
                                '`' + ("%.3f" % (energy_periods['year'])) +
                                '`' + str(len(state.slaveTWCRoundRobin))
                                )

                            for i in range(0, len(state.slaveTWCRoundRobin)):
                                webResponseMsg += (
                                    '`' + "%02X%02X" % (state.slaveTWCRoundRobin[i].TWCID[0],
                                                                      state.slaveTWCRoundRobin[i].TWCID[1]) +
                                    '~' + str(state.slaveTWCRoundRobin[i].maxAmps) +
                                    '~' + "%.2f" % (state.slaveTWCRoundRobin[i].reportedAmpsActual) +
                                    '~' + str(state.slaveTWCRoundRobin[i].lastAmpsOffered) +
                                    '~' + str(state.slaveTWCRoundRobin[i].reportedState)
                                    )

                        elif(webMsg[0:20] == b'setNonScheduledAmps='):
                            m = re.search(b'([-0-9]+)', webMsg[19:len(webMsg)])
                            if(m):
                                state.nonScheduledAmpsMax = int(m.group(1))
                                save_settings(state)
                        elif(webMsg[0:17] == b'setScheduledAmps='):
                            m = re.search(b'([-0-9]+)\nstartTime=([-0-9]+):([0-9]+)\nendTime=([-0-9]+):([0-9]+)\ndays=([0-9]+)', \
                                          webMsg[17:len(webMsg)], re.MULTILINE)
                            if(m):
                                state.scheduledAmpsMax = int(m.group(1))
                                state.scheduledAmpsStartHour = int(m.group(2)) + (int(m.group(3)) / 60)
                                state.scheduledAmpsEndHour = int(m.group(4)) + (int(m.group(5)) / 60)
                                state.scheduledAmpsDaysBitmap = int(m.group(6))
                                save_settings(state)
                        elif(webMsg[0:30] == b'setResumeTrackGreenEnergyTime='):
                            m = re.search(b'([-0-9]+):([0-9]+)', webMsg[30:len(webMsg)], re.MULTILINE)
                            if(m):
                                state.hourResumeTrackGreenEnergy = int(m.group(1)) + (int(m.group(2)) / 60)
                                save_settings(state)
                        elif(webMsg[0:11] == b'sendTWCMsg='):
                            m = re.search(b'([0-9a-fA-F]+)', webMsg[11:len(webMsg)], re.MULTILINE)
                            if(m):
                                twcMsg = trim_pad(bytearray.fromhex(m.group(1).decode('ascii')),
                                                  15 if len(state.slaveTWCRoundRobin) == 0 \
                                                  or state.slaveTWCRoundRobin[0].protocolVersion == 2 else 13)
                                if((twcMsg[0:2] == b'\xFC\x19') or (twcMsg[0:2] == b'\xFC\x1A')):
                                    print("\n*** ERROR: Web interface requested sending command:\n"
                                          + hex_str(twcMsg)
                                          + "\nwhich could permanently disable the TWC.  Aborting.\n")
                                elif((twcMsg[0:2] == b'\xFB\xE8')):
                                    print("\n*** ERROR: Web interface requested sending command:\n"
                                          + hex_str(twcMsg)
                                          + "\nwhich could crash the TWC.  Aborting.\n")
                                else:
                                    state.lastTWCResponseMsg = bytearray()
                                    send_msg(twcMsg, state)
                        elif(webMsg == b'getLastTWCMsgResponse'):
                            if(state.lastTWCResponseMsg != None and state.lastTWCResponseMsg != b''):
                                webResponseMsg = hex_str(state.lastTWCResponseMsg)
                            else:
                                webResponseMsg = 'None'
                        elif(webMsg == b'getEnergyHistory'):
                            webResponseMsg = json.dumps(build_energy_chart_payload(state.energyHistory, now), separators=(',', ':'))
                            numPackets = math.ceil(len(webResponseMsg) / 290)
                        elif(webMsg[0:13] == b'carApiTokens='):
                            try:
                                tokenPayload = json.loads(webMsg[13:len(webMsg)].decode('utf-8'))
                            except (UnicodeDecodeError, json.JSONDecodeError):
                                print(time_now() + ': ERROR: Invalid carApiTokens payload. Expected JSON object.')
                            else:
                                queue_background_task({'cmd':'carApiTokens',
                                                          'tokens':tokenPayload})
                        elif(webMsg[0:23] == b'setMasterHeartbeatData='):
                            m = re.search(b'([0-9a-fA-F]*)', webMsg[23:len(webMsg)], re.MULTILINE)
                            if(m):
                                if(len(m.group(1)) > 0):
                                    protocol_version = 1
                                    if(len(state.slaveTWCRoundRobin) > 0):
                                        protocol_version = state.slaveTWCRoundRobin[0].protocolVersion
                                    state.overrideMasterHeartbeatData = trim_pad(bytearray.fromhex(m.group(1).decode('ascii')),
                                                                           9 if protocol_version == 2 else 7)
                                else:
                                    state.overrideMasterHeartbeatData = b''
                        elif(webMsg == b'chargeNow'):
                            state.chargeNowAmps = energy_config.wiring_max_amps_all_twcs
                            state.chargeNowTimeEnd = now + 60*60*24
                        elif(webMsg == b'chargeNowCancel'):
                            state.chargeNowAmps = 0
                            state.chargeNowTimeEnd = 0
                        elif(webMsg == b'dumpState'):
                            webResponseMsg = ('time=' + str(now) + ', fakeMaster='
                                + str(general_config.fake_master) + ', rs485Adapter=' + self.rs485_config.adapter
                                + ', baud=' + str(self.rs485_config.baud_rate)
                                + ', wiringMaxAmpsAllTWCs=' + str(energy_config.wiring_max_amps_all_twcs)
                                + ', wiringMaxAmpsPerTWC=' + str(energy_config.wiring_max_amps_per_twc)
                                + ', minAmpsPerTWC=' + str(energy_config.min_amps_per_twc)
                                + ', greenEnergyAmpsOffset=' + str(energy_config.green_energy_amps_offset)
                                + ', debugLevel=' + str(general_config.debug_level)
                                + '\n')
                            webResponseMsg += (
                                '\ncarApiLastStartOrStopChargeTime=' + str(time.strftime("%m-%d-%y %H:%M:%S", time.localtime(tesla_car_api.last_start_or_stop_charge_time if tesla_car_api != None else 0)))
                                + '\ncarApiLastErrorTime=' + str(time.strftime("%m-%d-%y %H:%M:%S", time.localtime(tesla_car_api.last_error_time if tesla_car_api != None else 0)))
                                + '\ncarApiTokenExpireTime=' + str(time.strftime("%m-%d-%y %H:%M:%S", time.localtime(tesla_car_api.token_expire_time if tesla_car_api != None else 0)))
                                + '\n'
                                )

                            for vehicle in (tesla_car_api.vehicles if tesla_car_api != None else []):
                                webResponseMsg += str(vehicle.__dict__) + '\n'

                            webResponseMsg += 'slaveTWCRoundRobin:\n'
                            for slaveTWC in state.slaveTWCRoundRobin:
                                webResponseMsg += str(slaveTWC.__dict__) + '\n'

                            numPackets = math.ceil(len(webResponseMsg) / 290)
                        elif(webMsg[0:14] == b'setDebugLevel='):
                            m = re.search(b'([-0-9]+)', webMsg[14:len(webMsg)], re.MULTILINE)
                            if(m):
                                general_config.debug_level = int(m.group(1))
                        else:
                            print(time_now() + ": Unknown IPC request from web server: " + str(webMsg))

                        if(len(webResponseMsg) > 0):
                            if(general_config.debug_level >= 5):
                                print(time_now() + ": Web query response: '" + webResponseMsg + "'")

                            try:
                                if(numPackets == 0):
                                    if(len(webResponseMsg) > 290):
                                        webResponseMsg = webResponseMsg[0:290]

                                    web_ipc_server.send(struct.pack('=LH' + str(len(webResponseMsg)) + 's', webMsgTime, webMsgID,
                                           webResponseMsg.encode('ascii')), block=False)
                                else:
                                    msgTemp = struct.pack('=LH1s', webMsgTime, webMsgID, bytearray([numPackets]))
                                    web_ipc_server.send(msgTemp, block=False)
                                    for i in range(0, numPackets):
                                        packet = webResponseMsg[i*290:i*290+290]
                                        web_ipc_server.send(struct.pack('=LH' + str(len(packet)) + 's', webMsgTime, webMsgID,
                                           packet.encode('ascii')), block=False)

                            except sysv_ipc.BusyError:
                                print(time_now() + ": Error: IPC queue full when trying to send response to web interface.")

                except sysv_ipc.BusyError:
                    pass

                timeMsgRxStart = time.time()

                while True:
                    now     = time.time()
                    dataLen = rs485_transport.in_waiting()

                    if(dataLen == 0):
                        if(state.msgLen == 0):
                            break
                        else:
                            if(now - timeMsgRxStart >= 2.0):
                                if(general_config.debug_level >= 9):
                                    print(time_now() + ": Msg timeout (" + hex_str(state.ignoredData) +
                                          ') ' + hex_str(state.msg[0:state.msgLen]))
                                state.msgLen = 0
                                state.ignoredData = bytearray()
                                break

                            time.sleep(0.025)
                            continue
                    else:
                        dataLen = 1
                        data    = rs485_transport.read(dataLen)

                    if(dataLen != 1 or len(data) == 0):
                        print("WARNING: No data available.")
                        break

                    timeMsgRxStart = now

                    if(state.msgLen == 0 and data[0] != 0xc0):
                        if(general_config.debug_level >= 11):
                            print("Ignoring byte %02X between messages." % (data[0]))

                        state.ignoredData += data
                        continue
                    elif(state.msgLen > 0 and state.msgLen < 15 and data[0] == 0xc0):
                        if(general_config.debug_level >= 10):
                            print("Found end of message before full-length message received.  " \
                                  "Discard and wait for new message.")

                        state.msg = bytearray(data)
                        state.msgLen = 1
                        continue

                    if(state.msgLen == 0):
                        state.msg = bytearray()

                    state.msg += data
                    state.msgLen += 1

                    if(state.msgLen >= 16 and data[0] == 0xc0):
                        break

                if(state.msgLen >= 16):
                    state.msg = unescape_msg(state.msg, state.msgLen)
                    state.msgLen = 0
                    msg = state.msg

                    state.msgRxCount += 1

                    if(state.lastTWCResponseMsg == b''
                       and state.msg[0:2] != b'\xFB\xE0' and state.msg[0:2] != b'\xFD\xE0'
                       and state.msg[0:2] != b'\xFC\xE1' and state.msg[0:2] != b'\xFB\xE2'
                       and state.msg[0:2] != b'\xFD\xE2' and state.msg[0:2] != b'\xFB\xEB'
                       and state.msg[0:2] != b'\xFD\xEB' and state.msg[0:2] != b'\xFD\xE0'
                    ):
                        state.lastTWCResponseMsg = state.msg

                    if(general_config.debug_level >= 9):
                        print("Rx@" + time_now() + ": (" + hex_str(state.ignoredData) + ') ' \
                              + hex_str(state.msg) + "")

                    state.ignoredData = bytearray()

                    if(len(state.msg) != 14 and len(state.msg) != 16 and len(state.msg) != 20):
                        print(time_now() + ": ERROR: Ignoring message of unexpected length %d: %s" % \
                               (len(state.msg), hex_str(state.msg)))
                        continue

                    checksumExpected = state.msg[len(state.msg) - 1]
                    checksum = 0
                    for i in range(1, len(state.msg) - 1):
                        checksum += state.msg[i]

                    if((checksum & 0xFF) != checksumExpected):
                        print("ERROR: Checksum %X does not match %02X.  Ignoring message: %s" %
                            (checksum, checksumExpected, hex_str(state.msg)))
                        continue

                    if(general_config.fake_master == 1):
                        foundMsgMatch = False
                        msgMatch = re.search(b'^\xfd\xe2(..)(.)(..)\x00\x00\x00\x00\x00\x00.+$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            sign = msgMatch.group(2)
                            maxAmps = ((msgMatch.group(3)[0] << 8) + msgMatch.group(3)[1]) / 100

                            if(general_config.debug_level >= 1):
                                print(time_now() + ": %.2f amp slave TWC %02X%02X is ready to link.  Sign: %s" % \
                                    (maxAmps, senderID[0], senderID[1], hex_str(sign)))

                            state.spikeAmpsToCancel6ALimit = 21 if maxAmps >= 80 else 16

                            if(senderID == general_config.fake_twc_id):
                                print(time_now() + ": Slave TWC %02X%02X reports same TWCID as master.  " \
                                      "Slave should resolve by changing its TWCID." % \
                                      (senderID[0], senderID[1]))
                                state.numInitMsgsToSend = 10
                                continue

                            slaveTWC = new_slave(state, senderID, maxAmps, general_config, energy_config)

                            protocolVersionDetected = slaveTWC.protocolVersion
                            minAmpsDetected = slaveTWC.minAmpsTWCSupports
                            if (len(msg) == 14):
                                protocolVersionDetected = 1
                                minAmpsDetected = 5
                            elif (len(msg) == 16):
                                protocolVersionDetected = 2
                                minAmpsDetected = 6

                            if (slaveTWC.protocolVersion != protocolVersionDetected
                               or slaveTWC.minAmpsTWCSupports != minAmpsDetected
                            ):
                                slaveTWC.protocolVersion = protocolVersionDetected
                                slaveTWC.minAmpsTWCSupports = minAmpsDetected

                                if (general_config.debug_level >= 1):
                                    print(time_now() + ": Set slave TWC %02X%02X protocolVersion to %d, minAmpsTWCSupports to %d." % \
                                         (senderID[0], senderID[1], slaveTWC.protocolVersion, slaveTWC.minAmpsTWCSupports))

                            if (slaveTWC.wiringMaxAmps > maxAmps):
                                print("\n\n!!! DANGER DANGER !!!\nYou have set wiringMaxAmpsPerTWC to "
                                      + str(energy_config.wiring_max_amps_per_twc)
                                      + " which is greater than the max "
                                      + str(maxAmps) + " amps your charger says it can handle.  " \
                                      "Please review instructions in the source code and consult an " \
                                      "electrician if you don't know what to do.")

                                slaveTWC.wiringMaxAmps = maxAmps / 4

                            slaveTWC.lastHeartbeatDebugOutput = ''
                            slaveTWC.timeLastRx               = time.time()
                            slaveTWC.send_master_heartbeat()
                        else:
                            msgMatch = re.search(b'^\xfd\xe0(..)(..)(.......+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID      = msgMatch.group(1)
                            receiverID    = msgMatch.group(2)
                            heartbeatData = msgMatch.group(3)

                            try:
                                slaveTWC = state.slaveTWCs[senderID]
                            except KeyError:
                                print(time_now() + ": ERROR: Received heartbeat message from " \
                                        "slave %02X%02X that we've not met before." % \
                                        (senderID[0], senderID[1]))
                                continue

                            if(general_config.fake_twc_id == receiverID):
                                if(slaveTWC.receive_slave_heartbeat(heartbeatData)):
                                    slaveTWC.send_master_heartbeat()
                            else:
                                if(general_config.debug_level >= 1):
                                    print(time_now() + ": WARNING: Slave TWC %02X%02X status data: " \
                                          "%s sent to unknown TWC %02X%02X." % \
                                        (senderID[0], senderID[1],
                                        hex_str(heartbeatData), receiverID[0], receiverID[1]))
                        else:
                            msgMatch = re.search(b'^\xfd\xeb(..)(..)(.+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            receiverID = msgMatch.group(2)
                            data = msgMatch.group(3)

                            if(general_config.debug_level >= 1):
                                print(time_now() + ": Slave TWC %02X%02X unexpectedly reported kWh and voltage data: %s." % \
                                    (senderID[0], senderID[1], hex_str(data)))
                        else:
                            msgMatch = re.search(b'^\xfc(\xe1|\xe2)(..)(.)\x00\x00\x00\x00\x00\x00\x00\x00.+$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            print(time_now() + " ERROR: TWC is set to Master mode so it can't be controlled by TWCManager.  " \
                                   "Search installation instruction PDF for 'rotary switch' and set " \
                                   "switch so its arrow points to F on the dial.")
                        if(foundMsgMatch == False):
                            print(time_now() + ": *** UNKNOWN MESSAGE FROM SLAVE:" + hex_str(msg)
                                  + "\nPlease private message user CDragon at http://teslamotorsclub.com " \
                                  "with a copy of this error.")
                    else:
                        foundMsgMatch = False
                        msgMatch = re.search(b'^\xfc\xe1(..)(.)\x00\x00\x00\x00\x00\x00\x00\x00+?.$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            sign = msgMatch.group(2)
                            state.masterTWCID = senderID

                            if(general_config.debug_level >= 1):
                                print(time_now() + ": Master TWC %02X%02X Linkready1.  Sign: %s" % \
                                    (senderID[0], senderID[1], hex_str(sign)))

                            if(senderID == general_config.fake_twc_id):
                                master_id_conflict(general_config)
                        else:
                            msgMatch = re.search(b'^\xfb\xe2(..)(.)\x00\x00\x00\x00\x00\x00\x00\x00+?.$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            sign = msgMatch.group(2)
                            state.masterTWCID = senderID

                            if(general_config.debug_level >= 1):
                                print(time_now() + ": Master TWC %02X%02X Linkready2.  Sign: %s" % \
                                    (senderID[0], senderID[1], hex_str(sign)))

                            if(senderID == general_config.fake_twc_id):
                                master_id_conflict(general_config)
                        else:
                            msgMatch = re.search(b'^\xfb\xe0(..)(..)(.......+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            receiverID = msgMatch.group(2)
                            heartbeatData = msgMatch.group(3)

                            state.masterTWCID = senderID
                            try:
                                slaveTWC = state.slaveTWCs[receiverID]
                            except KeyError:
                                slaveTWC = new_slave(state, receiverID, 80, general_config, energy_config)

                            slaveTWC.masterHeartbeatData = heartbeatData

                            if(receiverID != general_config.fake_twc_id):
                                if(general_config.debug_level >= 11):
                                    print(time_now() + ": Master %02X%02X sent " \
                                        "heartbeat message %s to receiver %02X%02X " \
                                        "that isn't our fake slave." % \
                                        (senderID[0], senderID[1],
                                        hex_str(heartbeatData),
                                        receiverID[0], receiverID[1]))
                                continue

                            amps = (state.slaveHeartbeatData[1] << 8) + state.slaveHeartbeatData[2]
                            fake_slave_elapsed = max(0.0, now - state.timeLastkWhDelivered)
                            fake_slave_delta = (((240 * (amps/100)) / 1000 / 60 / 60) * fake_slave_elapsed)
                            state.kWhDelivered += fake_slave_delta
                            state.timeLastkWhDelivered = now
                            if(energy_history_store != None):
                                state.energyHistory = energy_history_store.record_delta(
                                    state.energyHistory, now - fake_slave_elapsed, now,
                                    0.0, fake_slave_delta, state.kWhDelivered
                                )
                            if(time.time() - state.timeLastkWhSaved >= 300.0):
                                state.timeLastkWhSaved = now
                                if(general_config.debug_level >= 9):
                                    print(time_now() + ": Fake slave has delivered %.3fkWh" % \
                                       (state.kWhDelivered))
                                if(energy_history_store != None):
                                    energy_history_store.save(state.energyHistory)
                                save_settings(state)

                            if(heartbeatData[0] == 0x07):
                                state.slaveHeartbeatData[0] = heartbeatData[0]
                                state.timeToRaise2A = now + 10
                                amps -= 280
                                state.slaveHeartbeatData[3] = ((amps >> 8) & 0xFF)
                                state.slaveHeartbeatData[4] = (amps & 0xFF)
                            elif(heartbeatData[0] == 0x06):
                                state.timeTo0Aafter06 = now + 44
                                state.slaveHeartbeatData[0] = heartbeatData[0]
                                amps += 200
                                state.slaveHeartbeatData[1] = ((amps >> 8) & 0xFF)
                                state.slaveHeartbeatData[2] = (amps & 0xFF)
                                amps -= 80
                                state.slaveHeartbeatData[3] = ((amps >> 8) & 0xFF)
                                state.slaveHeartbeatData[4] = (amps & 0xFF)
                            elif(heartbeatData[0] == 0x05 or heartbeatData[0] == 0x08 or heartbeatData[0] == 0x09):
                                if(((heartbeatData[1] << 8) + heartbeatData[2]) > 0):
                                    state.slaveHeartbeatData[1] = heartbeatData[1]
                                    state.slaveHeartbeatData[2] = heartbeatData[2]

                                    ampsUsed = (heartbeatData[1] << 8) + heartbeatData[2]
                                    ampsUsed -= 80
                                    state.slaveHeartbeatData[3] = ((ampsUsed >> 8) & 0xFF)
                                    state.slaveHeartbeatData[4] = (ampsUsed & 0xFF)
                            elif(heartbeatData[0] == 0):
                                if(state.timeTo0Aafter06 > 0 and state.timeTo0Aafter06 < now):
                                    state.timeTo0Aafter06 = 0
                                    state.slaveHeartbeatData[0] = 0x0A
                                elif(state.timeToRaise2A > 0 and state.timeToRaise2A < now):
                                    state.timeToRaise2A = 0
                                    amps -= 80
                                    state.slaveHeartbeatData[3] = ((amps >> 8) & 0xFF)
                                    state.slaveHeartbeatData[4] = (amps & 0xFF)
                                    state.slaveHeartbeatData[0] = 0x0A
                            elif(heartbeatData[0] == 0x02):
                                print(time_now() + ": Master heartbeat contains error %ld: %s" % \
                                        (heartbeatData[1], hex_str(heartbeatData)))
                            else:
                                print(time_now() + ": UNKNOWN MHB state %s" % \
                                        (hex_str(heartbeatData)))

                            slaveTWC.send_slave_heartbeat(senderID)
                            slaveTWC.print_status(state.slaveHeartbeatData)
                        else:
                            msgMatch = re.search(b'^\xfc\x1d\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00+?.$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            if(general_config.debug_level >= 1):
                                print(time_now() + ": Received 2-hour idle message from Master.")
                        else:
                            msgMatch = re.search(b'^\xfd\xe2(..)(.)(..)\x00\x00\x00\x00\x00\x00.+$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            sign = msgMatch.group(2)
                            maxAmps = ((msgMatch.group(3)[0] << 8) + msgMatch.group(3)[1]) / 100
                            if(general_config.debug_level >= 1):
                                print(time_now() + ": %.2f amp slave TWC %02X%02X is ready to link.  Sign: %s" % \
                                    (maxAmps, senderID[0], senderID[1],
                                    hex_str(sign)))
                            if(senderID == general_config.fake_twc_id):
                                print(time_now() + ": ERROR: Received slave heartbeat message from " \
                                        "slave %02X%02X that has the same TWCID as our fake slave." % \
                                        (senderID[0], senderID[1]))
                                continue

                            new_slave(state, senderID, maxAmps, general_config, energy_config)
                        else:
                            msgMatch = re.search(b'^\xfd\xe0(..)(..)(.......+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            receiverID = msgMatch.group(2)
                            heartbeatData = msgMatch.group(3)

                            if(senderID == general_config.fake_twc_id):
                                print(time_now() + ": ERROR: Received slave heartbeat message from " \
                                        "slave %02X%02X that has the same TWCID as our fake slave." % \
                                        (senderID[0], senderID[1]))
                                continue

                            try:
                                slaveTWC = state.slaveTWCs[senderID]
                            except KeyError:
                                slaveTWC = new_slave(state, senderID, 80, general_config, energy_config)

                            slaveTWC.print_status(heartbeatData)
                        else:
                            msgMatch = re.search(b'^\xfb\xeb(..)(..)(\x00\x00\x00\x00\x00\x00\x00\x00\x00+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            receiverID = msgMatch.group(2)

                            if(senderID == general_config.fake_twc_id):
                                print(time_now() + ": ERROR: Received voltage request message from " \
                                        "TWC %02X%02X that has the same TWCID as our fake slave." % \
                                        (senderID[0], senderID[1]))
                                continue

                            if(general_config.debug_level >= 8):
                                print(time_now() + ": VRQ from %02X%02X to %02X%02X" % \
                                    (senderID[0], senderID[1], receiverID[0], receiverID[1]))

                            if(receiverID == general_config.fake_twc_id):
                                kWhCounter = int(state.kWhDelivered)
                                kWhPacked = bytearray([((kWhCounter >> 24) & 0xFF),
                                              ((kWhCounter >> 16) & 0xFF),
                                              ((kWhCounter >> 8) & 0xFF),
                                              (kWhCounter & 0xFF)])
                                print(time_now() + ": VRS %02X%02X: %dkWh (%s) %dV %dV %dV" % \
                                    (general_config.fake_twc_id[0], general_config.fake_twc_id[1],
                                    kWhCounter, hex_str(kWhPacked), 240, 0, 0))
                                send_msg(bytearray(b'\xFD\xEB') + general_config.fake_twc_id
                                         + kWhPacked
                                         + bytearray(b'\x00\xF0\x00\x00\x00\x00\x00'), state)
                        else:
                            msgMatch = re.search(b'^\xfd\xeb(..)(.........+?).$', msg, re.DOTALL)
                        if(msgMatch and foundMsgMatch == False):
                            foundMsgMatch = True
                            senderID = msgMatch.group(1)
                            data = msgMatch.group(2)
                            kWhCounter = (data[0] << 24) + (data[1] << 16) + (data[2] << 8) + data[3]
                            voltsPhaseA = (data[4] << 8) + data[5]
                            voltsPhaseB = (data[6] << 8) + data[7]
                            voltsPhaseC = (data[8] << 8) + data[9]

                            if(senderID == general_config.fake_twc_id):
                                print(time_now() + ": ERROR: Received voltage response message from " \
                                        "TWC %02X%02X that has the same TWCID as our fake slave." % \
                                        (senderID[0], senderID[1]))
                                continue

                            if(general_config.debug_level >= 1):
                                print(time_now() + ": VRS %02X%02X: %dkWh %dV %dV %dV" % \
                                    (senderID[0], senderID[1],
                                    kWhCounter, voltsPhaseA, voltsPhaseB, voltsPhaseC))

                        if(foundMsgMatch == False):
                            print(time_now() + ": ***UNKNOWN MESSAGE from master: " + hex_str(msg))

            except KeyboardInterrupt:
                print("Exiting after background tasks complete...")
                break

            except Exception:
                traceback.print_exc()
                time.sleep(5)

        self.shutdown()

    def shutdown(self):
        backgroundTasksQueue.join()
        rs485_transport.close()


app = TWCManagerApp()

# After the IPC message queue is created, if you type 'sudo ipcs -q' on the
# command like, you should see something like:
# ------ Message Queues --------
# key        msqid      owner      perms      used-bytes   messages
# 0x5402ed16 491520     pi         666        0            0
#
# Notice that we've created the only IPC message queue in the system. Apparently
# default software on the pi doesn't use IPC or if it does, it creates and
# deletes its message queues quickly.
#
# If you want to get rid of all queues because you created extras accidentally,
# reboot or type 'sudo ipcrm -a msg'.  Don't get rid of all queues if you see
# ones you didn't create or you may crash another process.
# Find more details in IPC here:
# http://www.onlamp.com/pub/a/php/2004/05/13/shared_memory.html


app.print_startup_banner()
app.run()

#
# End main program
#
##############################
