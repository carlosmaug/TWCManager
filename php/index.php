<?php require_once __DIR__ . '/lib/index_bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?=h($pageTitle)?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #efe8dc;
            --bg-deep: #e4d8c3;
            --panel: rgba(255, 251, 244, 0.92);
            --panel-strong: #fffdf9;
            --ink: #18313c;
            --muted: #61717b;
            --line: rgba(137, 111, 86, 0.22);
            --accent: #b44824;
            --accent-soft: #f3ddcf;
            --accent-deep: #842f14;
            --ok-bg: #eef8f0;
            --ok-ink: #24533b;
            --warn-bg: #fff1e6;
            --warn-ink: #8c3a17;
            --chip: #f4ebdf;
            --shadow: 0 18px 44px rgba(49, 34, 16, 0.12);
            --shadow-soft: 0 10px 24px rgba(49, 34, 16, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(210, 168, 131, 0.34), transparent 28%),
                radial-gradient(circle at bottom right, rgba(164, 101, 58, 0.10), transparent 32%),
                linear-gradient(155deg, var(--bg-deep) 0%, var(--bg) 100%);
            font-family: "Palatino Linotype", "Book Antiqua", Georgia, serif;
        }
        a {
            color: var(--accent);
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 18px 36px;
        }
        .hero {
            display: flex;
            justify-content: space-between;
            gap: 22px;
            align-items: flex-start;
            margin-bottom: 22px;
            padding: 24px 26px;
            background: linear-gradient(145deg, rgba(255, 251, 244, 0.95), rgba(248, 238, 223, 0.84));
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }
        .hero-kicker {
            display: inline-block;
            margin-bottom: 10px;
            padding: 5px 10px;
            background: var(--chip);
            color: var(--accent-deep);
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .hero h1 {
            margin: 0 0 8px;
            font-size: 2.35rem;
            letter-spacing: -0.03em;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            max-width: 700px;
            line-height: 1.6;
            font-size: 1.02rem;
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
            align-self: center;
        }
        .button,
        button,
        input[type="submit"],
        input[type="image"] {
            font: inherit;
        }
        .button,
        button,
        input[type="submit"] {
            border: 0;
            background: var(--accent);
            color: #fff;
            padding: 11px 16px;
            border-radius: 999px;
            cursor: pointer;
            box-shadow: var(--shadow-soft);
            transition: transform 120ms ease, box-shadow 120ms ease, background 120ms ease;
        }
        .button:hover,
        button:hover,
        input[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        .button.secondary,
        .secondary-button {
            background: #43515a;
        }
        .button.ghost {
            background: rgba(255,255,255,0.58);
            color: var(--accent-deep);
            border: 1px solid var(--line);
            box-shadow: none;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(8px);
            padding: 22px;
            margin-bottom: 18px;
        }
        .panel h2, .panel h3 {
            margin-top: 0;
        }
        .panel h2 {
            font-size: 1.45rem;
            margin-bottom: 8px;
        }
        .page-title {
            margin: 0 0 6px;
            font-size: 2.7rem;
            letter-spacing: -0.04em;
        }
        .page-subtitle {
            margin: 0;
            color: var(--muted);
            max-width: 760px;
            line-height: 1.6;
        }
        .section-block {
            margin-top: 20px;
        }
        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin: 0 0 10px;
        }
        .section-heading h2 {
            margin: 0;
            font-size: 1.05rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent-deep);
        }
        .section-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .grid {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.2fr 1fr;
        }
        .summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 14px;
        }
        .metric {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            padding: 16px 16px 14px;
            position: relative;
            overflow: hidden;
        }
        .metric::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, var(--accent), #d98c66);
        }
        .metric .label {
            display: block;
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }
        .metric .value {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.03em;
        }
        .flash {
            padding: 13px 15px;
            margin-bottom: 18px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow-soft);
        }
        .flash.ok {
            background: var(--ok-bg);
            color: var(--ok-ink);
        }
        .flash.error {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }
        .twc-list {
            display: grid;
            gap: 14px;
        }
        .twc-card {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            padding: 16px;
            box-shadow: var(--shadow-soft);
        }
        .twc-card header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 10px;
        }
        .twc-card h3 {
            margin: 0;
        }
        .twc-state {
            color: var(--accent-deep);
            font-size: 0.82rem;
            background: var(--chip);
            padding: 5px 9px;
            border-radius: 999px;
        }
        .twc-stats {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 10px;
        }
        .stat {
            padding: 10px;
            border: 1px solid var(--line);
            background: #f8f3eb;
            border-radius: 12px;
        }
        .stat .label {
            display: block;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .stat .value {
            display: block;
            margin-top: 5px;
            font-weight: 700;
        }
        .muted {
            color: var(--muted);
        }
        form {
            margin: 0;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .field {
            margin-bottom: 14px;
        }
        .field.full {
            grid-column: 1 / -1;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }
        select,
        input[type="text"],
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink);
            border-radius: 12px;
            transition: border-color 120ms ease, box-shadow 120ms ease;
        }
        select:focus,
        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(180, 72, 36, 0.48);
            box-shadow: 0 0 0 4px rgba(180, 72, 36, 0.10);
        }
        textarea {
            min-height: 220px;
            font-family: "Courier New", monospace;
            font-size: 14px;
        }
        .days {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            margin-top: 8px;
        }
        .days label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            font-weight: 400;
        }
        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .callout {
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #fcf5eb, #f7efe2);
            padding: 14px;
            margin-top: 14px;
            border-radius: 14px;
        }
        .callout strong {
            display: block;
            margin-bottom: 6px;
        }
        .callout form {
            margin-top: 12px;
        }
        .subnav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .subnav a {
            padding: 7px 10px;
            background: rgba(255,255,255,0.65);
            border: 1px solid var(--line);
            border-radius: 999px;
        }
        .codebox {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .debug-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .section-note {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .status-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .health-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--chip);
            border: 1px solid var(--line);
            font-size: 0.84rem;
            color: var(--accent-deep);
            border-radius: 999px;
        }
        .health-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #c69060;
            box-shadow: 0 0 0 3px rgba(198, 144, 96, 0.18);
        }
        .health-dot.good {
            background: #2f8a58;
            box-shadow: 0 0 0 3px rgba(47, 138, 88, 0.18);
        }
        .policy-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: 1fr 1fr;
        }
        .policy-card {
            padding: 14px;
            background: var(--panel-strong);
            border: 1px solid var(--line);
            border-radius: 14px;
        }
        .policy-card .eyebrow {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.78rem;
            margin-bottom: 8px;
            display: block;
        }
        .policy-card .main {
            font-size: 1.18rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .chart-card {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
        }
        .chart-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .chart-head h3 {
            margin: 0 0 4px;
        }
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--muted);
            font-size: 0.84rem;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
        }
        .legend-swatch.solar {
            background: #2f8a58;
        }
        .legend-swatch.grid {
            background: #b44824;
        }
        .line-chart {
            width: 100%;
            height: 210px;
            display: block;
        }
        .chart-empty {
            min-height: 210px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted);
            border: 1px dashed rgba(137, 111, 86, 0.28);
            background: rgba(255, 255, 255, 0.42);
            padding: 18px;
        }
        .chart-grid-line {
            stroke: rgba(97, 113, 123, 0.22);
            stroke-width: 1;
        }
        .chart-axis-base {
            stroke: rgba(24, 49, 60, 0.28);
            stroke-width: 1.2;
        }
        .chart-axis-label {
            fill: #6a7881;
            font-size: 10px;
        }
        .chart-bar.solar {
            fill: #2f8a58;
            stroke: rgba(255, 255, 255, 0.72);
            stroke-width: 0.8;
        }
        .chart-bar.grid {
            fill: #b44824;
            stroke: rgba(255, 255, 255, 0.72);
            stroke-width: 0.8;
        }
        @media (max-width: 920px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
                display: grid;
            }
            .summary-grid,
            .twc-stats,
            .field-grid,
            .chart-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .wrap {
                padding: 18px 12px 28px;
            }
            .summary-grid,
            .twc-stats,
            .field-grid,
            .policy-grid,
            .chart-grid {
                grid-template-columns: 1fr;
            }
            .hero-actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <span class="hero-kicker">Gen2 Wall Connector Control</span>
            <h1 class="page-title">TWCManager Control Panel</h1>
            <p class="page-subtitle">Monitor the live RS-485 status, review the active charging policy, and adjust Wall Connector behavior from one page.</p>
        </div>
        <div class="hero-actions">
            <form action="index.php" method="post">
                <?=csrf_input()?>
                <input type="hidden" name="action" value="logout">
                <button class="button ghost" type="submit">Sign Out</button>
            </form>
            <a class="button ghost" href="<?=h($mainViewUrl)?>">Main View</a>
            <?php if($debugMenuUrl !== ''): ?>
            <a class="button ghost" href="<?=h($debugMenuUrl)?>">Debug Menu</a>
            <?php endif; ?>
            <?php if($teslaHelperUrl !== ''): ?>
            <a class="button ghost" href="<?=h($teslaHelperUrl)?>">Tesla Token Helper</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if($flashMessage !== ''): ?>
    <div class="flash ok"><?=h($flashMessage)?></div>
    <?php endif; ?>

    <?php if($flashError !== ''): ?>
    <div class="flash error"><?=h($flashError)?></div>
    <?php endif; ?>

    <?php if(!$statusValid): ?>
    <div class="flash error">
        No valid status response was received from TWCManager.
        <?php if($statusError !== ''): ?>
        <?=h($statusError)?>
        <?php else: ?>
        Verify that the Python process is running, that <code>$twcScriptDir</code> is correct, and that PHP can access the SysV IPC queue.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if($pageMode === 'debug'): ?>
    <div class="panel">
        <h2>Debug Menu</h2>
        <p class="muted">Use these tools only for protocol diagnostics. Some commands can affect charger behavior directly.</p>

        <div class="field-grid">
            <form class="field" action="index.php?debugTWC=1" method="post">
                <?=csrf_input()?>
                <input type="hidden" name="debugTWC" value="1">
                <label for="setDebugLevel">Debug Level</label>
                <input id="setDebugLevel" type="text" name="setDebugLevel" value="<?=h($setDebugLevel)?>">
                <div class="inline-actions">
                    <input type="submit" name="submit" value="Set">
                </div>
            </form>

            <form class="field" action="index.php?debugTWC=1" method="post">
                <?=csrf_input()?>
                <input type="hidden" name="debugTWC" value="1">
                <label for="beginTest">Debug Test</label>
                <input id="beginTest" type="text" name="beginTest" value="<?=h($beginTest)?>">
                <div class="inline-actions">
                    <input type="submit" name="submit" value="Begin">
                </div>
            </form>
        </div>

        <div class="debug-links">
            <a class="button ghost" href="<?=h($sendMessageUrl)?>">Send RS-485 Message</a>
            <a class="button ghost" href="<?=h($heartbeatUrl)?>">Override Master Heartbeat</a>
            <a class="button ghost" href="<?=h($dumpStateUrl)?>">Dump Internal State</a>
        </div>
    </div>
    <?php elseif($pageMode === 'send'): ?>
    <div class="panel">
        <h2>Send RS-485 Message</h2>
        <p class="muted">This tool is for protocol debugging. TWCManager blocks some dangerous message types, but sending arbitrary frames still carries risk.</p>

        <form action="index.php?sendTWCMsg=" method="post">
            <?=csrf_input()?>
            <div class="field">
                <label for="sendTWCMsg">Hex Payload</label>
                <input id="sendTWCMsg" type="text" name="sendTWCMsg" value="<?=h($sendTWCMsg)?>">
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Submit">
                <a class="button ghost" href="<?=h($debugMenuUrl)?>">Back To Debug Menu</a>
            </div>
        </form>

        <div class="subnav">
            <span class="muted">Preset debug frames are disabled for direct one-click execution. Paste the payload manually if needed.</span>
        </div>

        <?php if($debugResponse !== ''): ?>
        <div class="callout">
            <strong>Response:</strong>
            <div class="codebox"><?=h($debugResponse)?></div>
            <?php if($debugDecoded !== ''): ?>
            <p><strong>Decoded:</strong> <?=h($debugDecoded)?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif($pageMode === 'heartbeat'): ?>
    <div class="panel">
        <h2>Override Master Heartbeat</h2>
        <p class="muted">This tool forces custom master heartbeat payload data for debugging and protocol experiments.</p>
        <form action="index.php?setMasterHeartbeatData=" method="post">
            <?=csrf_input()?>
            <div class="field">
                <label for="setMasterHeartbeatData">Heartbeat Data</label>
                <input id="setMasterHeartbeatData" type="text" name="setMasterHeartbeatData" value="<?=h($setMasterHeartbeatData)?>">
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Submit">
                <button class="button ghost" type="submit" name="setMasterHeartbeatData" value="">Clear Override</button>
                <a class="button ghost" href="<?=h($debugMenuUrl)?>">Back To Debug Menu</a>
            </div>
        </form>
        <div class="subnav">
            <span class="muted">Preset heartbeat overrides are disabled for direct one-click execution. Enter the payload manually if needed.</span>
        </div>
    </div>
    <?php elseif($pageMode === 'dump'): ?>
    <div class="panel">
        <h2>Internal State Dump</h2>
        <p class="muted">Raw state returned by the Python process.</p>
        <form action="index.php" method="get">
            <input type="hidden" name="dumpState" value="1">
            <div class="field">
                <textarea readonly><?=h($dumpStateResponse)?></textarea>
            </div>
            <div class="inline-actions">
                <input type="submit" name="submit" value="Refresh">
                <a class="button ghost" href="<?=h($debugMenuUrl)?>">Back To Debug Menu</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="section-block">
        <div class="section-heading">
            <h2>Overview</h2>
            <p>Current backend status and key charge limits.</p>
        </div>
    <div class="panel">
        <div class="section-note">
            <div>
                <h2>Live Status</h2>
                <p class="muted">Current charger state from the running TWCManager process. This page refreshes automatically every 10 seconds.</p>
            </div>
            <div class="status-badges">
                <div class="health-badge">
                    <span class="health-dot <?=h($backendBadgeClass)?>"></span>
                    <?=h($backendBadgeText)?>
                </div>
                <div class="health-badge">
                    <span class="health-dot <?=h($teslaConnectionClass)?>"></span>
                    <?=h($teslaConnectionText)?>
                </div>
            </div>
        </div>
        <p class="muted"><?=h($teslaConnectionDetail)?></p>
        <div class="summary-grid">
            <?php render_metric('Power Available', $availableAmpsDisplay); ?>
            <?php render_metric('Wiring Limit', $wiringLimitDisplay); ?>
            <?php render_metric('Minimum Charge', $minChargeDisplay); ?>
            <?php render_metric('24-Hour Override', $chargeNowDisplay); ?>
        </div>

        <?php if($chargeNowActive): ?>
        <div class="callout">
            <strong>1-day charge is active.</strong>
            TWCManager is forcing normal charging for approximately <?=h($chargeNowRemainingDisplay)?> more.
            <form action="index.php" method="post">
                <?=csrf_input()?>
                <input type="submit" name="submit" value="Cancel 1-day charge">
            </form>
        </div>
        <?php endif; ?>

        <?php if($status['need_tesla_tokens']): ?>
        <div class="callout">
            <strong>Tesla token action needed.</strong>
            A connected charger appears to need Tesla API access, but no tokens are loaded.
            <?php if($teslaHelperUrl !== ''): ?>
            Use <a href="tesla_callback.php">Tesla Token Helper</a> to generate `TeslaApiTokens.json`.
            <?php else: ?>
            The Tesla Token Helper is disabled in this deployment.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    </div>

    <div class="section-block">
        <div class="section-heading">
            <h2>Control</h2>
            <p>Active policy first, editable settings underneath.</p>
        </div>
        <div class="grid">
            <div class="panel">
                <h2>Charging Policy</h2>
                <div class="policy-grid">
                    <?php render_policy_card('Scheduled Power', $scheduledPowerDisplay, $scheduledTimeDisplay . '<br>' . $scheduledDaysDisplay); ?>
                    <?php render_policy_card('Non-Scheduled Power', $nonScheduledPowerDisplay, 'Resume green energy at ' . $resumeGreenDisplay); ?>
                </div>
            </div>

            <div class="panel">
                <h2>Adjust Settings</h2>
                <form action="index.php" method="post">
                    <?=csrf_input()?>
                    <div class="field-grid">
                        <div class="field full">
                            <label for="scheduledAmpsMax">Scheduled Power</label>
                            <?php render_select(
                                'scheduledAmpsMax',
                                array_merge(array('Disabled' => '-1'), $standardAmps),
                                $status['scheduled_amps'],
                                ' onchange="toggleScheduleFields()"'
                            ); ?>
                        </div>

                        <div class="field" id="scheduledStartField">
                            <label for="scheduledAmpStartTime">Start Time</label>
                            <?php render_select('scheduledAmpStartTime', $hourOptions, $status['scheduled_start']); ?>
                        </div>

                        <div class="field" id="scheduledEndField">
                            <label for="scheduledAmpsEndTime">End Time</label>
                            <?php render_select('scheduledAmpsEndTime', $hourOptions, $status['scheduled_end']); ?>
                        </div>

                        <div class="field full" id="scheduledDaysField">
                            <label>Scheduled Days</label>
                            <div class="days">
                                <?php render_day_checkboxes($scheduledAmpDays); ?>
                            </div>
                        </div>

                        <div class="field full">
                            <label for="nonScheduledAmpsMax">Non-Scheduled Power</label>
                            <?php render_select(
                                'nonScheduledAmpsMax',
                                array_merge(array('Track green energy' => '-1', 'Do not charge' => '0'), $standardAmps),
                                $status['non_scheduled_amps'],
                                ' onchange="toggleResumeField()"'
                            ); ?>
                        </div>

                        <div class="field full" id="resumeGreenField">
                            <label for="resumeTrackGreenEnergyTime">Resume "Track green energy" At</label>
                            <?php render_select(
                                'resumeTrackGreenEnergyTime',
                                array_merge(array('Never' => '-1:00'), $hourOptions),
                                $status['resume_track_green_energy_time']
                            ); ?>
                        </div>
                    </div>

                    <div class="inline-actions">
                        <input type="submit" name="submit" value="Save">
                        <?php if($chargeNowActive): ?>
                        <input type="submit" name="submit" value="Cancel 1-day charge">
                        <?php else: ?>
                        <input type="submit" name="submit" value="<?=h($chargeNowButtonLabel)?>">
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-heading">
            <h2>Energy</h2>
            <p>Delivered energy totals from the stored cumulative kWh counter.</p>
        </div>
        <div class="panel">
            <div class="section-note">
                <div>
                    <h2>Charging History</h2>
                    <p class="muted">Approximate solar and grid energy delivered over hourly, daily and monthly views.</p>
                </div>
                <div class="health-badge"><?=h($totalDeliveredDisplay)?></div>
            </div>
            <div class="chart-grid">
                <?php render_energy_line_chart('Today', 'Hourly energy for the current day.', $energyHistoryCharts['today']); ?>
                <?php render_energy_line_chart('This Week', 'Daily energy for the current ISO week.', $energyHistoryCharts['week']); ?>
                <?php render_energy_line_chart('Last 30 Days', 'Daily energy across the last 30 days.', $energyHistoryCharts['month']); ?>
                <?php render_energy_line_chart('Last 12 Months', 'Monthly energy totals.', $energyHistoryCharts['year']); ?>
            </div>
        </div>
    </div>

    <div class="section-block">
        <div class="section-heading">
            <h2>Chargers</h2>
            <p>Detected Wall Connectors and their live telemetry.</p>
        </div>
        <div class="panel">
            <div class="section-note">
                <div>
                    <h2>Managed Wall Connectors</h2>
                    <p class="muted">Per-charger telemetry from the RS-485 heartbeat stream.</p>
                </div>
                <div class="health-badge"><?=h($detectedTwcLabel)?></div>
            </div>
            <?php if($detectedTwcCount === 0): ?>
            <p class="muted">No slave TWCs were reported on the RS-485 network.</p>
            <?php else: ?>
            <div class="twc-list">
                <?php foreach($status['twcs'] as $twc): ?>
                <?php render_twc_card($twc, $status['available_amps'], $status['min_amps_per_twc']); ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleResumeField() {
    const nonScheduled = document.getElementById('nonScheduledAmpsMax');
    const resume = document.getElementById('resumeGreenField');
    if (!nonScheduled || !resume) return;
    resume.style.display = (nonScheduled.value === '-1') ? 'none' : 'block';
}

function toggleScheduleFields() {
    const scheduled = document.getElementById('scheduledAmpsMax');
    const visible = scheduled && scheduled.value !== '-1';
    ['scheduledStartField', 'scheduledEndField', 'scheduledDaysField'].forEach(function(id) {
        const node = document.getElementById(id);
        if (node) {
            node.style.display = visible ? 'block' : 'none';
        }
    });
}

toggleResumeField();
toggleScheduleFields();
setTimeout(function() {
    window.location.reload();
}, 10000);
</script>
</body>
</html>
