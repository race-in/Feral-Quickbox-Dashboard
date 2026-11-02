<?php
/**
 * Feral Quickbox Dashboard — rootless QuickBox-style dashboard for shared servers.
 * Copyright (C) 2026 race-in contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Motto: shared server = rootless. No root, no sudo, no systemctl, no package manager.
 */
declare(strict_types=1);

if (!defined('FQD_BOOTSTRAPPED')) define('FQD_BOOTSTRAPPED', true);
$pathFile = __DIR__ . '/config-path.php';
if (!is_file($pathFile) || is_link($pathFile)) { http_response_code(503); exit('Dashboard is not configured.'); }
$configFile = require $pathFile;
if (!is_string($configFile) || !is_file($configFile) || is_link($configFile) || !is_readable($configFile)) { http_response_code(503); exit('Private configuration unavailable.'); }
$config = parse_ini_file($configFile, false, INI_SCANNER_RAW);
if (!is_array($config) || empty($config['PASSWORD_HASH'])) { http_response_code(503); exit('Dashboard login is not configured.'); }
require __DIR__ . '/inc/lib.php';
if (fqd_is_root()) { http_response_code(503); exit('Refusing to run as root. This dashboard is rootless by design.'); }

$home = fqd_home($config);
$cacheDir = fqd_private_dir(fqd_dir($config, 'CACHE_PATH', 'XDG_CACHE_HOME', '.cache'));
$dataDir = fqd_private_dir(fqd_dir($config, 'DATA_PATH', 'XDG_DATA_HOME', '.local/share'));

$script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/index.php';
$base = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.' || $base === '/') $base = '';
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$nonce = base64_encode(random_bytes(18));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

// Sessions live in the user's private data dir, not in a path shared with other shared-hosting users.
if ($dataDir !== '' && fqd_private_dir($dataDir . '/sessions') !== '') {
    session_save_path($dataDir . '/sessions');
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '50');
}
session_name('FQDSESSID');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $https ? '1' : '0');
if (PHP_VERSION_ID >= 70300) ini_set('session.cookie_samesite', 'Strict');
session_set_cookie_params(0, $base === '' ? '/' : $base . '/', '', $https, true);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function fqd_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if ($action === 'metrics' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['auth'])) fqd_json(array('error' => 'Authentication required'), 401);
    $out = fqd_metrics($config, $configFile);
    session_write_close();
    fqd_json(array('ok' => true, 'metrics' => $out));
}
if ($action === 'service') {
    if (empty($_SESSION['auth'])) fqd_json(array('error' => 'Authentication required'), 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fqd_json(array('error' => 'POST required'), 405);
    $tok = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!hash_equals((string) $_SESSION['csrf'], $tok)) fqd_json(array('ok' => false, 'message' => 'Session expired. Refresh the page.'), 400);
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 2048), true);
    $id = is_array($body) && isset($body['id']) ? (string) $body['id'] : '';
    $op = is_array($body) && isset($body['op']) ? (string) $body['op'] : '';
    session_write_close();
    $r = fqd_service_action($configFile, $home, $id, $op);
    fqd_json($r, $r['ok'] ? 200 : 422);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (!hash_equals((string) $_SESSION['csrf'], $token)) {
        http_response_code(400); $error = 'Session expired. Refresh and try again.';
    } elseif (isset($_POST['logout'])) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
        session_destroy(); header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 303); exit;
    } elseif (isset($_POST['password'])) {
        $pw = (string) $_POST['password'];
        $wait = $cacheDir !== '' ? fqd_throttle_check($cacheDir, $ip) : 0;
        if ($wait > 0) { http_response_code(429); $error = 'Too many attempts. Try again in ' . (int) ceil($wait / 60) . ' min.'; }
        elseif (strlen($pw) >= 12 && strlen($pw) <= 72 && password_verify($pw, (string) $config['PASSWORD_HASH'])) {
            if ($cacheDir !== '') fqd_throttle_record($cacheDir, $ip, true);
            session_regenerate_id(true);
            $_SESSION = array('auth' => true, 'csrf' => bin2hex(random_bytes(32)));
            header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 303); exit;
        } else {
            if ($cacheDir !== '') fqd_throttle_record($cacheDir, $ip, false);
            $error = 'Incorrect password.';
        }
    }
}
$auth = !empty($_SESSION['auth']);
$csrf = (string) $_SESSION['csrf'];
$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) $_SERVER['HTTP_HOST']) : 'shared slot';
$apps = $auth ? fqd_apps($config) : array();
$e = static function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1020"><title>Feral Quickbox Dashboard</title>
<link id="qb-theme-style" rel="stylesheet" href="<?php echo $e($base . '/assets/quickbox-defaulted.css'); ?>">
<link rel="stylesheet" href="<?php echo $e($base . '/assets/feral-overrides.css'); ?>">
<script nonce="<?php echo $e($nonce); ?>">try{if(localStorage.getItem('fqd-qb-theme')==='smoked'){document.getElementById('qb-theme-style').href=<?php echo json_encode($base . '/assets/quickbox-smoked.css'); ?>;}}catch(x){}</script>
</head><body>
<?php if (!$auth): ?>
<main class="login"><section class="loginbox"><div class="login-logo">FQ</div><p class="eyebrow">SHARED SERVER · ROOTLESS CONSOLE</p><h1>Welcome back<span class="acc">.</span></h1><p class="muted">Sign in to view your private slot dashboard.</p><?php if ($error !== ''): ?><p class="alert" role="alert"><?php echo $e($error); ?></p><?php endif; ?>
<form method="post" action="<?php echo $e($base . '/'); ?>"><input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>"><label for="pw">Dashboard password</label><input id="pw" name="password" type="password" minlength="12" maxlength="72" autocomplete="current-password" required autofocus><button type="submit">Sign in&nbsp; →</button></form><p class="fine">No root · No sudo · Only your own processes</p></section></main>
<?php else: ?>
<!-- QuickBox Lite dashboard composition with a Feral-safe PHP backend. -->
<div class="fqd-rootless">
  <header>
    <div class="headerpanel">
      <div class="logopanel">
        <h2><a href="#dashboard" aria-label="QuickBox Feral dashboard"><img src="<?php echo $e($base . '/img/logo-light.png'); ?>" alt="QuickBox" class="logo-image" height="50"></a></h2>
      </div>
      <div class="headerbar">
        <a href="#" id="menuToggle" class="menutoggle" aria-label="Toggle dashboard menu"><i class="glyphicon glyphicon-menu-hamburger"></i></a>
        <div class="header-right">
          <ul class="headermenu">
            <li>
              <div id="noticePanel" class="btn-group">
                <button id="quickboxMenuToggle" type="button" class="btn" aria-expanded="false"><i class="glyphicon glyphicon-th-large"></i> QuickBox Lite Menu <span class="caret"></span></button>
                <div id="noticeDropdown" class="dropdown-menu dm-notice pull-right" role="dialog" aria-label="QuickBox Lite menu">
                  <div role="tabpanel">
                    <ul class="nav nav-tabs nav-justified qb-menu-tabs" role="tablist">
                      <li class="active"><a href="#quickplus" data-qb-menu-tab="quickplus">QuickBox+</a></li>
                      <li><a href="#dashadjust" data-qb-menu-tab="dashadjust">Dashboard</a></li>
                      <li><a href="#configure" data-qb-menu-tab="configure">Config</a></li>
                    </ul>
                    <div class="tab-content">
                      <div role="tabpanel" class="tab-pane active qb-menu-pane" id="quickplus">
                        <div class="list-group"><div class="list-group-item">
                          <h5>QuickBox <span class="fqd-menu-version">Feral edition</span></h5>
                          <p class="fqd-menu-copy">QuickBox Lite-style dashboard layout adapted for a rootless Feral shared slot.</p>
                          <small><a href="https://github.com/amefs/quickbox-lite" target="_blank" rel="noopener noreferrer">QuickBox Lite upstream</a></small>
                          <small><a href="https://github.com/race-in/Feral-Quickbox-Dashboard" target="_blank" rel="noopener noreferrer">Feral project source</a></small>
                        </div></div>
                      </div>
                      <div role="tabpanel" class="tab-pane qb-menu-pane" id="dashadjust">
                        <div class="list-group"><div class="list-group-item">
                          <h5>Dashboard theme</h5>
                          <p class="fqd-menu-copy">Choose the QuickBox Lite Defaulted or Smoked theme.</p>
                          <button type="button" id="theme" class="btn btn-default btn-sm"><i class="glyphicon glyphicon-adjust"></i> Switch theme</button>
                          <span class="fqd-theme-hint">Current: <strong id="theme-name">Defaulted</strong></span>
                        </div></div>
                      </div>
                      <div role="tabpanel" class="tab-pane qb-menu-pane" id="configure">
                        <div class="list-group"><div class="list-group-item">
                          <h5>Feral compatibility</h5>
                          <p class="fqd-menu-copy">Monitoring is host-scoped where stated. Process control is restricted to services explicitly configured for this account.</p>
                          <span class="label label-warning">ROOTLESS MODE</span>
                        </div></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </li>
            <li>
              <div class="btn-group" id="accountPanel">
                <button type="button" id="accountMenuToggle" class="btn btn-logged" aria-expanded="false"><?php echo $e($host); ?> <span class="caret"></span></button>
                <div class="dropdown-menu pull-right fqd-account-menu">
                  <div class="fqd-account-host"><span class="fqd-online-dot"></span><span id="whoami"><?php echo $e($host); ?></span></div>
                  <form method="post" action="<?php echo $e($base . '/'); ?>">
                    <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
                    <button class="btn btn-danger btn-block" name="logout" value="1" type="submit"><i class="glyphicon glyphicon-log-out"></i> Sign out</button>
                  </form>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </header>

  <section>
    <div class="leftpanel" id="leftpanel">
      <div class="leftpanelinner">
        <ul class="nav nav-tabs nav-justified nav-sidebar" role="tablist">
          <li class="tooltips active"><a href="#mainmenu" data-qb-side-tab="mainmenu" title="Main menu"><i class="glyphicon glyphicon-th-list"></i></a></li>
          <li class="tooltips"><a href="#applications-menu" data-qb-side-tab="applications-menu" title="Applications"><i class="glyphicon glyphicon-link"></i></a></li>
          <li class="tooltips"><a href="#help-menu" data-qb-side-tab="help-menu" title="Help"><i class="glyphicon glyphicon-question-sign"></i></a></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane active" id="mainmenu">
            <h5 class="sidebar-title">MAIN MENU</h5>
            <ul class="nav nav-pills nav-stacked nav-quirk">
              <li><a href="#dashboard"><i class="glyphicon glyphicon-dashboard"></i> <span>Dashboard</span></a></li>
              <li><a href="#panel-server-bandwidth-interface"><i class="glyphicon glyphicon-signal"></i> <span>Bandwidth</span></a></li>
              <li><a href="#panel-server-service-control"><i class="glyphicon glyphicon-cog"></i> <span>Service Control Center</span></a></li>
              <li><a href="#panel-server-package-management"><i class="glyphicon glyphicon-th"></i> <span>Package Management Center</span></a></li>
              <li><a href="#panel-slot-status"><i class="glyphicon glyphicon-user"></i> <span>My Slot</span></a></li>
              <li><a href="#panel-applications"><i class="glyphicon glyphicon-link"></i> <span>Applications</span></a></li>
            </ul>
            <h5 class="sidebar-title">SYSTEM</h5>
            <ul class="nav nav-pills nav-stacked nav-quirk">
              <li><a href="#panel-server-load"><i class="glyphicon glyphicon-heart"></i> <span>Server Health</span></a></li>
              <li><a href="#panel-server-ram"><i class="glyphicon glyphicon-tasks"></i> <span>Memory Status</span></a></li>
              <li><a href="#panel-server-disk"><i class="glyphicon glyphicon-hdd"></i> <span>Disk Status</span></a></li>
            </ul>
          </div>
          <div class="tab-pane" id="applications-menu">
            <h5 class="sidebar-title">YOUR APPLICATIONS</h5>
            <ul class="nav nav-pills nav-stacked nav-quirk">
              <?php if ($apps): foreach ($apps as $a): ?>
                <li><a class="grayscale" href="<?php echo $e($a['url']); ?>" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-new-window"></i> <span><?php echo $e($a['label']); ?></span></a></li>
              <?php endforeach; else: ?>
                <li class="fqd-sidebar-empty">No application shortcuts configured.</li>
              <?php endif; ?>
            </ul>
          </div>
          <div class="tab-pane" id="help-menu">
            <h5 class="sidebar-title">QUICK SYSTEM TIPS</h5>
            <ul class="nav nav-pills nav-stacked nav-quirk nav-mail">
              <li class="fqd-help-item"><strong>Shared server = rootless</strong><small>No sudo, apt, systemctl, firewall or system-wide package actions.</small></li>
              <li class="fqd-help-item"><strong>Service controls</strong><small>Only entries configured in your private services.ini can be started or stopped.</small></li>
              <li class="fqd-help-item"><strong>Client shortcuts</strong><small>Add your qBittorrent, Deluge or ruTorrent URLs to the private APPS setting.</small></li>
              <li class="fqd-help-item"><strong>Metric scope</strong><small>Host figures may include other users; My Slot figures include only your own processes.</small></li>
            </ul>
            <h5 class="sidebar-title">DOCUMENTATION</h5>
            <ul class="nav nav-pills nav-stacked nav-quirk">
              <li><a href="https://github.com/race-in/Feral-Quickbox-Dashboard/tree/feral-classic-quickbox-rootless/rootless-package/Feral-Quickbox-Dashboard-rootless/docs" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-book"></i> Feral setup guide</a></li>
              <li><a href="https://github.com/amefs/quickbox-lite" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-info-sign"></i> QuickBox Lite upstream</a></li>
            </ul>
          </div>
        </div>
        <div class="fqd-sidebar-footer"><span class="fqd-online-dot"></span> Feral rootless dashboard</div>
      </div>
    </div>
    <div class="fqd-sidebar-overlay" id="sidebarOverlay"></div>

    <div class="mainpanel" id="dashboard">
      <div class="contentpanel">
        <div class="row fqd-page-heading">
          <div class="col-sm-8"><h3>Dashboard <small>Server overview</small></h3></div>
          <div class="col-sm-4 text-right"><span id="updated" class="fqd-updated"><span class="fqd-online-dot"></span> Connecting…</span></div>
        </div>
        <div class="row">
          <div class="col-md-8 dash-left">
            <div class="panel panel-main panel-inverse" id="panel-server-bandwidth-interface" data-inner-id="panel-server-bandwidth-interface">
              <div class="panel-heading"><h4 class="panel-title">BANDWIDTH DATA <span class="fqd-panel-tag">LIVE INTERFACES</span></h4></div>
              <div class="panel-body fqd-no-pad">
                <div class="table-responsive">
                  <table class="table table-hover table-bordered nomargin fqd-table">
                    <thead><tr><th>NETWORK</th><th class="text-right">UPLOAD SPEED</th><th class="text-right">DOWNLOAD SPEED</th><th class="text-right">TOTAL IN</th><th class="text-right">TOTAL OUT</th></tr></thead>
                    <tbody id="ifaces"><tr><td colspan="5" class="fqd-empty">Waiting for network counters…</td></tr></tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="panel panel-inverse" id="panel-server-bandwidth-details" data-inner-id="panel-server-bandwidth-details">
              <div class="panel-heading"><h4 class="panel-title">VIEW ADDITIONAL BANDWIDTH DETAILS</h4></div>
              <div class="panel-body">
                <div class="fqd-bandwidth-values">
                  <div><span><i class="glyphicon glyphicon-arrow-down"></i> DOWNLOAD</span><strong id="rx">— <em>/s</em></strong></div>
                  <div><span><i class="glyphicon glyphicon-arrow-up"></i> UPLOAD</span><strong id="tx">— <em>/s</em></strong></div>
                </div>
                <div class="fqd-chart-title">Recent throughput · last 30 samples</div>
                <div class="fqd-chart-wrap"><canvas id="network-chart" role="img" aria-label="Recent download and upload throughput chart"></canvas></div>
                <div class="fqd-chart-legend"><span><i class="fqd-download-key"></i> Download</span><span><i class="fqd-upload-key"></i> Upload</span></div>
              </div>
            </div>

            <div class="panel panel-inverse" id="panel-server-service-control" data-inner-id="panel-server-service-control">
              <div class="panel-heading"><h4 class="panel-title">SERVICE CONTROL CENTER <span class="fqd-panel-tag" id="svc-count">0 RUNNING</span></h4></div>
              <div class="panel-body fqd-no-pad">
                <div class="table-responsive">
                  <table class="table table-hover nomargin fqd-table">
                    <thead><tr><th>SERVICE STATUS</th><th class="text-right">PROCESS</th><th class="text-right">MEMORY</th><th class="text-right">UPTIME</th><th class="text-right">ACTIONS</th></tr></thead>
                    <tbody id="svc"><tr><td colspan="5" class="fqd-empty">Waiting for service status…</td></tr></tbody>
                  </table>
                </div>
              </div>
              <div class="panel-footer">
                <p id="svc-notice" class="fqd-inline-warning" hidden></p>
                <small>Only your own processes are shown. Start / stop is available only for services explicitly allow-listed in private <code>services.ini</code>.</small>
              </div>
            </div>

            <div class="panel panel-main panel-inverse" id="panel-server-package-management" data-inner-id="panel-server-package-management">
              <div class="panel-heading"><h4 class="panel-title">PACKAGE MANAGEMENT CENTER</h4></div>
              <div class="panel-body">
                <div class="alert alert-warning fqd-rootless-alert"><strong>Feral shared-slot limitation:</strong> QuickBox system package installation, removal and systemd management require root privileges. These actions are intentionally unavailable here.</div>
                <div class="table-responsive">
                  <table class="table table-bordered table-striped-col fqd-table">
                    <thead><tr><th>NAME</th><th>DETAILS</th><th class="text-center">AVAILABILITY</th></tr></thead>
                    <tbody>
                      <tr><td>qBittorrent</td><td>Use an existing user-owned qBittorrent instance and its configured WebUI shortcut.</td><td class="text-center"><span class="label label-success">User-space</span></td></tr>
                      <tr><td>Deluge</td><td>Use an existing deluged / deluge-web instance and its configured WebUI shortcut.</td><td class="text-center"><span class="label label-success">User-space</span></td></tr>
                      <tr><td>rTorrent</td><td>Use an existing rTorrent instance and a configured ruTorrent or Flood shortcut.</td><td class="text-center"><span class="label label-success">User-space</span></td></tr>
                      <tr><td>Transmission</td><td>Use an existing user-owned Transmission daemon and WebUI shortcut.</td><td class="text-center"><span class="label label-success">User-space</span></td></tr>
                      <tr><td>QuickBox system packages</td><td>Root-only package and service installation is not supported on shared slots.</td><td class="text-center"><span class="label label-danger">Unavailable</span></td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-4 dash-right">
            <div class="panel panel-side panel-inverse-full panel-updates" id="panel-server-load" data-inner-id="panel-server-load">
              <div class="panel-heading"><h4 class="panel-title text-success">SERVER LOAD</h4></div>
              <div class="panel-body">
                <div class="fqd-load-grid">
                  <div><span>LOAD 1 MIN</span><strong id="l1">—</strong></div>
                  <div><span>LOAD 5 MIN</span><strong id="l5">—</strong></div>
                  <div><span>LOAD 15 MIN</span><strong id="l15">—</strong></div>
                </div>
                <div class="fqd-uptime-line"><strong>UPTIME:</strong> <span id="uptime">—</span></div>
              </div>
            </div>
            <div class="panel panel-side panel-inverse" id="panel-server-cpu" data-inner-id="panel-server-cpu">
              <div class="panel-heading"><h4 class="panel-title">CPU STATUS</h4></div>
              <div class="panel-body">
                <div class="fqd-metric-head"><strong id="cpu">—<small>%</small></strong><span id="cpu-state">Sampling…</span></div>
                <div class="fqd-meter"><i id="cpu-bar"></i></div>
                <p class="fqd-metric-caption" id="cores">Shared processor</p>
                <p class="fqd-metric-detail" id="cpu-detail">Waiting for first sample</p>
              </div>
            </div>
            <div class="panel panel-side panel-inverse" id="panel-server-disk" data-inner-id="panel-server-disk">
              <div class="panel-heading"><h4 class="panel-title"><span id="disk-label">YOUR DISK STATUS</span></h4></div>
              <div class="panel-body">
                <div class="fqd-metric-head"><strong id="disk">—<small>%</small></strong><span id="disk-sub">Filesystem</span></div>
                <div class="fqd-meter"><i id="disk-bar"></i></div>
                <p class="fqd-metric-caption" id="disk-note">Capacity is not account quota</p>
                <p class="fqd-metric-detail" id="disk-detail">Waiting for disk information</p>
              </div>
            </div>
            <div class="panel panel-side panel-inverse" id="panel-server-ram" data-inner-id="panel-server-ram">
              <div class="panel-heading"><h4 class="panel-title">SYSTEM RAM STATUS</h4></div>
              <div class="panel-body">
                <div class="fqd-metric-head"><strong id="mem">—<small>%</small></strong><span>Host memory</span></div>
                <div class="fqd-meter"><i id="mem-bar"></i></div>
                <p class="fqd-metric-caption" id="mem-note">Waiting for memory</p>
                <p class="fqd-metric-detail" id="mem-detail">Waiting for RAM information</p>
              </div>
            </div>
            <div class="panel panel-inverse" id="panel-slot-status" data-inner-id="panel-slot-status">
              <div class="panel-heading"><h4 class="panel-title">MY SLOT</h4></div>
              <div class="panel-body">
                <div class="fqd-slot-grid">
                  <div><strong id="s-proc">—</strong><span>PROCESSES</span></div>
                  <div><strong id="s-mem">—</strong><span>MEMORY</span></div>
                  <div><strong id="s-cpu">—</strong><span>CPU</span></div>
                </div>
                <small class="fqd-metric-detail">Only processes owned by your Feral account.</small>
              </div>
            </div>
            <div class="panel panel-inverse" id="panel-applications" data-inner-id="panel-applications">
              <div class="panel-heading"><h4 class="panel-title">APPLICATIONS</h4></div>
              <div class="panel-body">
                <?php if ($apps): ?>
                  <ul class="fqd-app-list">
                    <?php foreach ($apps as $a): ?>
                      <li><a href="<?php echo $e($a['url']); ?>" target="_blank" rel="noopener noreferrer"><i class="glyphicon glyphicon-new-window"></i> <?php echo $e($a['label']); ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="fqd-metric-detail">No shortcuts configured. Add user-space links to APPS in your private config.env.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="alert alert-info fqd-scope-note"><strong>Shared-hosting metric scope:</strong> <span id="scope">Host CPU, memory, network, load and uptime may describe the whole server. My Slot includes only processes owned by your account. Disk is filesystem capacity unless a real account quota is configured.</span></div>
        <footer class="fqd-footer"><span>QuickBox Lite UI · Feral rootless adaptation</span><span>Independent community project · No privileged system actions</span></footer>
      </div>
    </div>
  </section>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>
<script nonce="<?php echo $e($nonce); ?>">
(function(){'use strict';
var base=<?php echo json_encode($base); ?>,csrf=<?php echo json_encode($csrf); ?>,last=null,history=[],lastIf={},maxPoints=30,busy=false;
function el(id){return document.getElementById(id)}
function mk(tag,cls,txt){var n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=txt;return n}
function bytes(n){if(typeof n!=='number'||!isFinite(n)||n<0)return '—';var u=['B','KB','MB','GB','TB','PB'],i=0;while(n>=1024&&i<5){n/=1024;i++}return n.toFixed(i?(n<10?2:1):0)+' '+u[i]}
function dur(s){if(typeof s!=='number')return '—';var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return d?d+'d '+h+'h':h?h+'h '+m+'m':m+'m'}
function bar(id,v){var b=el(id);b.style.width=(v===null?0:Math.max(0,Math.min(100,v)))+'%';b.className=v>=90?'hot':v>=75?'warm':''}
function pct(id,v){el(id).innerHTML='';el(id).appendChild(document.createTextNode(v===null?'—':v.toFixed(1)));el(id).appendChild(mk('small',null,'%'))}
function toast(msg,err){var t=el('toast');t.textContent=msg;t.className='toast show'+(err?' err':'');clearTimeout(t._t);t._t=setTimeout(function(){t.className='toast'},4500)}
function chart(){var c=el('network-chart');if(!c)return;var cs=getComputedStyle(document.documentElement),w=c.clientWidth,h=c.clientHeight,dpr=Math.max(1,window.devicePixelRatio||1);c.width=Math.round(w*dpr);c.height=Math.round(h*dpr);var x=c.getContext('2d');x.scale(dpr,dpr);x.clearRect(0,0,w,h);var pad=8,plotH=h-18,plotW=w-pad*2,max=1;history.forEach(function(p){max=Math.max(max,p.rx,p.tx)});max*=1.2;for(var g=0;g<4;g++){var y=pad+(plotH/3)*g;x.beginPath();x.strokeStyle='rgba(137,155,184,.18)';x.lineWidth=1;x.moveTo(pad,y);x.lineTo(w-pad,y);x.stroke()}x.fillStyle='rgba(137,155,184,.8)';x.font='9px sans-serif';x.fillText(bytes(max)+'/s',pad+2,pad+9);
function line(key,color){if(history.length<2)return;x.beginPath();history.forEach(function(p,i){var px=pad+(plotW*i/(maxPoints-1)),py=pad+plotH-(p[key]/max)*plotH;if(i===0)x.moveTo(px,py);else x.lineTo(px,py)});x.strokeStyle=color;x.lineWidth=2;x.lineJoin='round';x.lineCap='round';x.stroke();var p=history[history.length-1],px=pad+(plotW*(history.length-1)/(maxPoints-1)),py=pad+plotH-(p[key]/max)*plotH;x.beginPath();x.arc(px,py,3,0,Math.PI*2);x.fillStyle=color;x.fill()}
line('rx',cs.getPropertyValue('--purple').trim()||'#a895ff');line('tx',cs.getPropertyValue('--blue').trim()||'#69b9ff')}
function ifaces(m,now){var tb=el('ifaces');tb.innerHTML='';if(!m.network||!m.network.interfaces.length){var r=mk('tr'),c=mk('td','empty','Network counters are not readable on this host.');c.colSpan=5;r.appendChild(c);tb.appendChild(r);return}
m.network.interfaces.forEach(function(i){var p=lastIf[i.name],rs=0,ts=0;if(p){var dt=(now-p.t)/1000;if(dt>0&&dt<60){rs=Math.max(0,i.rx-p.rx)/dt;ts=Math.max(0,i.tx-p.tx)/dt}}lastIf[i.name]={rx:i.rx,tx:i.tx,t:now};var r=mk('tr');r.appendChild(mk('td','nm',i.name));[bytes(ts)+'/s',bytes(rs)+'/s',bytes(i.rx),bytes(i.tx)].forEach(function(v){r.appendChild(mk('td','num',v))});tb.appendChild(r)})}
function act(id,op,label){if((op==='stop'||op==='restart')&&!window.confirm(op.charAt(0).toUpperCase()+op.slice(1)+' '+label+'?'))return;if(busy)return;busy=true;toast(op+'… '+label);
fetch(base+'/?action=service',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id:id,op:op})}).then(function(r){return r.json().catch(function(){return{ok:false,message:'Unexpected response'}})}).then(function(d){toast(d.message||(d.ok?'Done':'Failed'),!d.ok);busy=false;poll()}).catch(function(){busy=false;toast('Request failed',true)})}
function services(list,caps){var nt=el('svc-notice');if(caps&&caps.exec===false){nt.textContent='Start/Stop unavailable: this host disables proc_open, so processes cannot be launched from PHP. Status monitoring still works.';nt.hidden=false}else if(caps&&caps.procfs===false){nt.textContent='/proc is not readable here, so process status cannot be shown.';nt.hidden=false}else nt.hidden=true;var tb=el('svc');tb.innerHTML='';var run=0;list.forEach(function(s){if(s.running)run++});el('svc-count').textContent=run+' RUNNING';
if(!list.length){var r0=mk('tr'),c0=mk('td','empty');c0.colSpan=5;c0.appendChild(document.createTextNode('No services configured or detected. Copy '));c0.appendChild(mk('code',null,'services.example.ini'));c0.appendChild(document.createTextNode(' to '));c0.appendChild(mk('code',null,'~/.config/feral-quickbox/services.ini'));c0.appendChild(document.createTextNode(' and edit it.'));r0.appendChild(c0);tb.appendChild(r0);return}
list.forEach(function(s){var r=mk('tr'),n=mk('td'),d=mk('span','dot'+(s.running?'':' off'));n.appendChild(d);var nm=mk('span','nm',s.label);if(s.url){var a=mk('a','nm',s.label);a.href=s.url;a.target='_blank';a.rel='noopener noreferrer';nm=a}n.appendChild(nm);n.appendChild(mk('span','sub',s.configured?(s.running?'running':'stopped'):'detected · read-only'));r.appendChild(n);
r.appendChild(mk('td','num',s.running?String(s.count):'—'));r.appendChild(mk('td','num',s.running?bytes(s.rss):'—'));r.appendChild(mk('td','num',s.running?dur(s.age):'—'));
var t=mk('td'),a=mk('div','acts');[['start','Start',!s.can_start||s.running],['restart','Restart',!s.can_start||!s.running],['stop','Stop',!s.can_stop||!s.running]].forEach(function(b){var btn=mk('button','btn'+(b[0]==='stop'?' danger':''),b[1]);btn.type='button';btn.disabled=!!b[2]||!s.configured;if(caps&&caps.exec===false&&s.configured)btn.title='Unavailable: proc_open is disabled on this host';btn.addEventListener('click',function(){act(s.id,b[0],s.label)});a.appendChild(btn)});t.appendChild(a);r.appendChild(t);tb.appendChild(r)})}
function poll(){fetch(base+'/?action=metrics',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(r.status===401){location.reload();throw Error('session')}if(!r.ok)throw Error('http');return r.json()}).then(function(d){var m=d.metrics,now=Date.now();
pct('cpu',m.cpu);bar('cpu-bar',m.cpu);el('cpu-state').textContent=m.cpu===null?'Sampling…':'Live sample';el('cores').textContent=m.cores+' cores · shared';el('cpu-detail').textContent=m.cpu===null?'First sample pending':m.cpu.toFixed(1)+'%';
var mem=m.memory;pct('mem',mem?mem.percent:null);bar('mem-bar',mem?mem.percent:null);el('mem-note').textContent=mem?bytes(mem.used)+' / '+bytes(mem.total):'—';el('mem-detail').textContent=mem?bytes(mem.used)+' of '+bytes(mem.total):'—';
var q=m.quota,dk=m.disk;if(q){el('disk-label').textContent='Account quota';el('disk-sub').textContent=q.stale?'Refreshing…':'Measured with du';pct('disk',q.percent);bar('disk-bar',q.percent);el('disk-note').textContent=bytes(q.used)+' / '+bytes(q.total);el('disk-detail').textContent='Quota '+bytes(q.used)+' of '+bytes(q.total)}else{el('disk-label').textContent='Home filesystem';el('disk-sub').textContent='Filesystem usage';pct('disk',dk?dk.percent:null);bar('disk-bar',dk?dk.percent:null);el('disk-note').textContent=dk?bytes(dk.used)+' / '+bytes(dk.total):'Not quota';el('disk-detail').textContent=dk?bytes(dk.used)+' of '+bytes(dk.total)+' (fs)':'—'}
el('uptime').textContent=dur(m.uptime);var l=m.load||[];['l1','l5','l15'].forEach(function(k,i){el(k).textContent=l.length?Number(l[i]).toFixed(2):'—'});
el('scope').textContent=m.scope;if(m.user)el('whoami').textContent=m.user+'@'+location.hostname;
var s=m.slot;el('s-proc').textContent=String(s.processes);el('s-mem').textContent=bytes(s.rss);el('s-cpu').textContent=s.cpu===null?'—':s.cpu.toFixed(1)+'%';
var rx=0,tx=0;if(m.network&&last){var dt=(now-last.t)/1000;if(dt>0&&dt<60){rx=Math.max(0,m.network.rx-last.rx)/dt;tx=Math.max(0,m.network.tx-last.tx)/dt}}
if(m.network){last={rx:m.network.rx,tx:m.network.tx,t:now};el('rx').innerHTML='';el('rx').appendChild(document.createTextNode(bytes(rx)+' '));el('rx').appendChild(mk('em',null,'/s'));el('tx').innerHTML='';el('tx').appendChild(document.createTextNode(bytes(tx)+' '));el('tx').appendChild(mk('em',null,'/s'));history.push({rx:rx,tx:tx});if(history.length>maxPoints)history.shift();chart()}else{el('rx').textContent='Unavailable';el('tx').textContent='Unavailable'}
ifaces(m,now);services(m.services||[],m.capabilities);var u=el('updated');u.innerHTML='';u.appendChild(mk('span','dot'));u.appendChild(document.createTextNode('Updated '+new Date(m.time*1000).toLocaleTimeString()))}).catch(function(e){if(e.message!=='session')el('updated').textContent='Connection issue · retrying'})}
function setTheme(name){var link=el('qb-theme-style');if(!link)return;link.href=base+'/assets/quickbox-'+name+'.css';var label=el('theme-name');if(label)label.textContent=name==='smoked'?'Smoked':'Defaulted';try{localStorage.setItem('fqd-qb-theme',name)}catch(x){}chart()}function wireMenu(){var toggle=el('menuToggle'),panel=el('leftpanel'),overlay=el('sidebarOverlay');if(toggle&&panel){toggle.addEventListener('click',function(e){e.preventDefault();panel.classList.toggle('fqd-open');if(overlay)overlay.classList.toggle('active')})}if(overlay)overlay.addEventListener('click',function(){panel.classList.remove('fqd-open');overlay.classList.remove('active')});[['quickboxMenuToggle','noticePanel'],['accountMenuToggle','accountPanel']].forEach(function(pair){var b=el(pair[0]),p=el(pair[1]);if(b&&p)b.addEventListener('click',function(e){e.stopPropagation();var open=p.classList.contains('open');document.querySelectorAll('.header-right .btn-group.open').forEach(function(n){n.classList.remove('open')});if(!open)p.classList.add('open')})});document.addEventListener('click',function(){document.querySelectorAll('.header-right .btn-group.open').forEach(function(n){n.classList.remove('open')})});document.querySelectorAll('[data-qb-menu-tab]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();var id=a.getAttribute('data-qb-menu-tab');document.querySelectorAll('[data-qb-menu-tab]').forEach(function(x){x.parentNode.classList.toggle('active',x===a)});document.querySelectorAll('.qb-menu-pane').forEach(function(x){x.classList.toggle('active',x.id===id)})})});document.querySelectorAll('[data-qb-side-tab]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();var id=a.getAttribute('data-qb-side-tab');document.querySelectorAll('[data-qb-side-tab]').forEach(function(x){x.parentNode.classList.toggle('active',x===a)});document.querySelectorAll('#leftpanel .tab-pane').forEach(function(x){x.classList.toggle('active',x.id===id)})})})}wireMenu();var savedTheme='defaulted';try{if(localStorage.getItem('fqd-qb-theme')==='smoked')savedTheme='smoked'}catch(x){}setTheme(savedTheme);el('theme').addEventListener('click',function(){setTheme(document.querySelector('#qb-theme-style').href.indexOf('smoked')>=0?'defaulted':'smoked')});
poll();var timer=setInterval(poll,5000);window.addEventListener('resize',chart);window.addEventListener('beforeunload',function(){clearInterval(timer)})
}());
</script>
<?php endif; ?></body></html>