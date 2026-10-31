<?php
/**
 * Feral Quickbox Dashboard — rootless read-only monitoring MVP.
 * Copyright (C) 2026 race-in contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
declare(strict_types=1);

if (!defined('FQD_BOOTSTRAPPED')) define('FQD_BOOTSTRAPPED', true);
$pathFile = __DIR__ . '/config-path.php';
if (!is_file($pathFile) || is_link($pathFile)) { http_response_code(503); exit('Dashboard is not configured.'); }
$configFile = require $pathFile;
if (!is_string($configFile) || !is_file($configFile) || is_link($configFile) || !is_readable($configFile)) { http_response_code(503); exit('Private configuration unavailable.'); }
$config = parse_ini_file($configFile, false, INI_SCANNER_RAW);
if (!is_array($config) || empty($config['PASSWORD_HASH'])) { http_response_code(503); exit('Dashboard login is not configured.'); }

$script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/index.php';
$base = rtrim(str_replace('\\\\', '/', dirname($script)), '/');
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
function fqd_metrics($config) {
    $cpu = null;
    $stat = @file('/proc/stat');
    if (is_array($stat) && isset($stat[0]) && strpos($stat[0], 'cpu ') === 0) {
        $v = preg_split('/\\s+/', trim($stat[0]));
        array_shift($v);
        $v = array_map('intval', array_slice($v, 0, 8));
        $now = array('total' => array_sum($v), 'idle' => $v[3] + (isset($v[4]) ? $v[4] : 0));
        if (isset($_SESSION['cpu']) && $now['total'] > $_SESSION['cpu']['total']) {
            $dt = $now['total'] - $_SESSION['cpu']['total'];
            $di = $now['idle'] - $_SESSION['cpu']['idle'];
            if ($di >= 0) $cpu = round(max(0, min(100, (1 - $di / $dt) * 100)), 1);
        }
        $_SESSION['cpu'] = $now;
    }
    $mem = null;
    $raw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
    if (is_array($raw)) {
        $m = array();
        foreach ($raw as $line) if (preg_match('/^(MemTotal|MemAvailable|MemFree|Buffers|Cached):\\s+(\\d+)/', $line, $x)) $m[$x[1]] = (int) $x[2] * 1024;
        if (!empty($m['MemTotal'])) {
            $avail = isset($m['MemAvailable']) ? $m['MemAvailable'] : ((isset($m['MemFree']) ? $m['MemFree'] : 0) + (isset($m['Buffers']) ? $m['Buffers'] : 0) + (isset($m['Cached']) ? $m['Cached'] : 0));
            $mem = array('total' => $m['MemTotal'], 'used' => max(0, $m['MemTotal'] - $avail), 'available' => max(0, $avail), 'percent' => round(100 * max(0, $m['MemTotal'] - $avail) / $m['MemTotal'], 1));
        }
    }
    $home = !empty($config['HOME_PATH']) && is_dir($config['HOME_PATH']) ? $config['HOME_PATH'] : (function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid())['dir'] : dirname(dirname(__DIR__)));
    $disk = null;
    $total = @disk_total_space($home);
    $free = @disk_free_space($home);
    if ($total && $free !== false) $disk = array('total' => (int) $total, 'used' => (int) ($total - $free), 'free' => (int) $free, 'percent' => round(100 * ($total - $free) / $total, 1));
    $net = null;
    $lines = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $rx = 0; $tx = 0; $found = false;
        foreach (array_slice($lines, 2) as $line) {
            if (strpos($line, ':') === false) continue;
            list($iface, $values) = explode(':', $line, 2);
            if (trim($iface) === '' || trim($iface) === 'lo') continue;
            $n = preg_split('/\\s+/', trim($values));
            if (count($n) < 9) continue;
            $rx += (int) $n[0]; $tx += (int) $n[8]; $found = true;
        }
        if ($found) $net = array('rx' => $rx, 'tx' => $tx);
    }
    $up = @file_get_contents('/proc/uptime');
    $uptime = $up !== false ? (int) floor((float) strtok($up, ' ')) : null;
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    $cpuInfo = @file('/proc/stat', FILE_IGNORE_NEW_LINES); $cpuCores = 0; if (is_array($cpuInfo)) foreach ($cpuInfo as $line) if (preg_match('/^cpu[0-9]+\\s/', $line)) $cpuCores++;
    return array('time' => time(), 'cpu' => $cpu, 'cpu_cores' => $cpuCores, 'memory' => $mem, 'disk' => $disk, 'network' => $net, 'uptime' => $uptime, 'load' => is_array($load) ? $load : null, 'platform' => array('kernel' => php_uname('r'), 'system' => php_uname('s'), 'architecture' => php_uname('m'), 'php' => PHP_VERSION),
        'scope' => 'CPU, memory, network and load may be host-wide on shared hosting. Disk shows home filesystem capacity, not your Feral quota.');
}
if (isset($_GET['action']) && $_GET['action'] === 'metrics') {
    if (empty($_SESSION['auth'])) fqd_json(array('error' => 'Authentication required'), 401);
    fqd_json(array('ok' => true, 'metrics' => fqd_metrics($config)));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!hash_equals((string) $_SESSION['csrf'], $token)) {
        http_response_code(400); $error = 'Session expired. Refresh and try again.';
    } elseif (isset($_POST['logout'])) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
        session_destroy(); header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 303); exit;
    } elseif (isset($_POST['password'])) {
        $pw = (string) $_POST['password'];
        $attempts = isset($_SESSION['attempts']) ? (int) $_SESSION['attempts'] : 0;
        $last = isset($_SESSION['last_attempt']) ? (int) $_SESSION['last_attempt'] : 0;
        if ($attempts >= 5 && time() - $last < 60) $error = 'Too many attempts. Wait one minute.';
        elseif (strlen($pw) >= 12 && strlen($pw) <= 72 && password_verify($pw, (string) $config['PASSWORD_HASH'])) {
            session_regenerate_id(true);
            $_SESSION = array('auth' => true, 'csrf' => bin2hex(random_bytes(32)));
            header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 303); exit;
        } else {
            $_SESSION['attempts'] = $attempts + 1; $_SESSION['last_attempt'] = time(); $error = 'Incorrect password.';
        }
    }
}
$auth = !empty($_SESSION['auth']);
$csrf = (string) $_SESSION['csrf'];
$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) $_SERVER['HTTP_HOST']) : 'Feral slot';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1020"><title>Feral Quickbox Dashboard</title>
<style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
:root{color-scheme:dark;--bg:#080d19;--surface:#101a2b;--surface2:#142139;--line:#253650;--text:#eef4ff;--muted:#899bb8;--purple:#9b8cff;--blue:#68b7ff;--green:#55d6a2;--amber:#f2bd6b;--red:#ff8294;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 78% -18%,#332b63 0,transparent 38%),radial-gradient(ellipse at 0 60%,#102b43 0,transparent 30%),var(--bg);color:var(--text)}
a{color:inherit;text-decoration:none}.shell{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:248px;padding:27px 17px;background:rgba(8,14,27,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:5;backdrop-filter:blur(16px)}
.brand{display:flex;align-items:center;gap:12px;padding:0 9px 38px}.brand-mark{width:39px;height:39px;display:grid;place-items:center;border-radius:13px;background:linear-gradient(140deg,#a895ff,#5e72dc);font-size:14px;font-weight:900;box-shadow:0 8px 26px #8d7aff44}.brand-name{font-weight:800;font-size:16px;letter-spacing:-.5px}.brand-name span{color:#a99aff}.brand-sub{font-size:9px;color:#7285a5;letter-spacing:1.5px;margin-top:3px}
.nav-label{font-size:9px;font-weight:800;letter-spacing:1.8px;color:#647794;padding:0 12px 12px}.nav-link{display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;color:#94a5c1;font-size:12px;margin:2px 0;transition:.2s}.nav-link:hover,.nav-link.active{color:#fff;background:linear-gradient(100deg,#302b58,#1c2941)}.nav-icon{width:20px;text-align:center;font-size:16px;color:#a99aff}.side-bottom{margin-top:auto;border:1px solid #263752;background:#101c30;border-radius:13px;padding:14px}.side-bottom strong{display:block;font-size:11px;margin:0 0 6px}.side-bottom span{font-size:10px;color:#8da0bd;line-height:1.5}.online-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 10px #55d6a277;margin-right:7px}
.main{margin-left:248px;max-width:1600px;padding:27px clamp(20px,3.5vw,54px) 35px}.topbar{display:flex;justify-content:space-between;align-items:center;padding-bottom:22px;border-bottom:1px solid #223149}.crumb{font-size:9px;letter-spacing:1.8px;color:#7e91b0;font-weight:800;margin-bottom:7px}.topbar h1{font-size:24px;letter-spacing:-.8px;margin:0}.top-actions{display:flex;align-items:center;gap:10px}.host-pill{border:1px solid #2c405d;background:#142139;border-radius:30px;padding:9px 13px;font-size:10px;color:#bdcbe1;white-space:nowrap}.logout{border:1px solid #31415c;background:#111d30;color:#bdcbe1;border-radius:9px;padding:9px 12px;font-size:10px;cursor:pointer}.logout:hover{border-color:#8f82e7;color:#fff}
.intro{display:flex;align-items:end;justify-content:space-between;padding:26px 0 17px}.intro h2{font-size:17px;letter-spacing:-.3px;margin:0 0 5px}.muted{color:var(--muted);font-size:11px;margin:0}.updated{font-size:10px;color:#9aabc5;white-space:nowrap}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.metric{position:relative;overflow:hidden;border:1px solid #263852;border-radius:15px;padding:17px;background:linear-gradient(145deg,rgba(24,38,63,.96),rgba(14,24,42,.96));box-shadow:0 14px 34px #0002}.metric:after{content:"";position:absolute;width:100px;height:100px;border-radius:50%;right:-42px;top:-52px;background:var(--glow);filter:blur(2px);opacity:.13}.metric-head{display:flex;justify-content:space-between;align-items:center}.metric-label{font-size:10px;color:#a6b5ce}.metric-icon{width:30px;height:30px;display:grid;place-items:center;border:1px solid #354568;border-radius:9px;background:#202e4a;color:var(--accent);font-size:14px}.metric-value{font-size:29px;font-weight:780;letter-spacing:-1.2px;margin:13px 0 12px}.metric-value small{font-size:11px;color:#92a3c0;font-weight:500;letter-spacing:0;margin-left:3px}.meter{height:5px;background:#293a55;border-radius:9px;overflow:hidden}.meter i{display:block;height:100%;width:0;border-radius:9px;background:var(--accent);transition:width .45s ease}.metric-foot{display:flex;justify-content:space-between;gap:8px;margin-top:9px;color:#7f92b1;font-size:9px}.metric-foot span:last-child{color:#aab9d1}
.content-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(260px,.85fr);gap:14px;margin-top:14px}.panel{border:1px solid #263852;border-radius:15px;padding:19px;background:linear-gradient(145deg,rgba(20,33,55,.97),rgba(13,23,40,.97));box-shadow:0 14px 34px #0002;min-width:0}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:17px}.panel-kicker{font-size:9px;letter-spacing:1.7px;color:#8195b6;font-weight:800;margin:0 0 6px}.panel h3{font-size:14px;margin:0;letter-spacing:-.2px}.live-tag{font-size:9px;border:1px solid #315a54;color:#78d9b1;background:#16332f;padding:6px 9px;border-radius:20px;white-space:nowrap}.chart-wrap{height:180px;position:relative}.chart-wrap canvas{width:100%;height:100%;display:block}.chart-legend{display:flex;gap:17px;color:#8fa1bd;font-size:9px;margin-top:7px}.legend-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}.legend-down{background:#9d8cff}.legend-up{background:#63b8ff}
.bandwidth{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.band{border:1px solid #2a3c58;background:#172640;border-radius:11px;padding:12px}.band span{display:block;color:#8fa2c0;font-size:9px;margin-bottom:8px}.band strong{font-size:17px;letter-spacing:-.4px}.band strong em{font-style:normal;font-size:9px;color:#91a2bd;font-weight:500}
.load-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.load-cell{padding:13px 10px;border:1px solid #2b3d59;background:#172640;border-radius:11px}.load-cell strong{display:block;font-size:19px;letter-spacing:-.5px}.load-cell span{font-size:9px;color:#8fa1bd;display:block;margin-top:5px}.system-meta{display:grid;gap:10px;margin-top:14px}.meta-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #263750;padding-bottom:9px;font-size:10px}.meta-row span{color:#8fa1bd}.meta-row strong{font-weight:600;color:#d4deee;text-align:right}
.notice{display:flex;gap:12px;align-items:flex-start;margin-top:14px;padding:13px 15px;border:1px solid #34445e;border-radius:12px;background:#111e32;color:#91a2bd;font-size:10px;line-height:1.55}.notice-icon{width:23px;height:23px;flex:0 0 23px;border-radius:7px;display:grid;place-items:center;background:#273652;color:#b8aaff;font-weight:800}.notice strong{display:block;color:#d3ddef;font-size:10px;margin-bottom:3px}.footer{display:flex;justify-content:space-between;gap:12px;color:#667b9b;font-size:9px;padding-top:20px}
.login{min-height:100vh;display:grid;place-items:center;padding:20px}.loginbox{width:min(100%,400px);padding:36px;border:1px solid #2a3b59;border-radius:20px;background:linear-gradient(145deg,#172540,#101a2c);box-shadow:0 25px 80px #0007}.login-logo{width:48px;height:48px;display:grid;place-items:center;border-radius:15px;background:linear-gradient(135deg,#a18cff,#5d73df);font-weight:900;margin-bottom:25px}.eyebrow{font-size:9px;letter-spacing:1.7px;color:#8a9dbc;font-weight:800;margin:0 0 9px}.loginbox h1{font-size:27px;margin:0 0 8px;letter-spacing:-.8px}.loginbox form{display:grid;gap:10px;margin-top:26px}.loginbox label{font-size:11px;color:#b8c4da}.loginbox input{height:45px;border:1px solid #30425f;border-radius:9px;background:#0a1222;color:#fff;padding:0 12px}.loginbox button{height:45px;border:0;border-radius:9px;background:linear-gradient(100deg,#7569dc,#a08bf5);color:#fff;font-weight:700;margin-top:5px;cursor:pointer}.alert{margin-top:15px;color:#ff9da8;font-size:11px}.fine{text-align:center;color:#7182a1;font-size:10px;margin-top:23px}
@media(max-width:1120px){.sidebar{width:210px}.main{margin-left:210px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.content-grid{grid-template-columns:1fr}}
@media(max-width:680px){.sidebar{position:static;width:auto;height:auto;padding:13px 15px;border-right:0;border-bottom:1px solid var(--line)}.brand{padding:0}.nav-label,.nav-link,.side-bottom{display:none}.main{margin:0;padding:19px 13px 25px}.topbar{align-items:flex-start}.topbar h1{font-size:21px}.top-actions{gap:6px}.host-pill{font-size:9px;padding:8px}.logout{padding:8px}.intro{display:block;padding:21px 0 15px}.updated{display:block;margin-top:10px}.metrics{gap:9px}.metric{padding:13px;min-height:132px}.metric-value{font-size:25px}.metric-foot{font-size:8px}.panel{padding:15px}.chart-wrap{height:150px}.footer{display:grid;gap:7px}.band strong{font-size:15px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{transition:none!important;scroll-behavior:auto!important}}
</style><link rel="stylesheet" href="<?php echo htmlspecialchars($base . '/dashboard-theme.css', ENT_QUOTES, 'UTF-8'); ?>"></head><body>
<?php if (!$auth): ?>
<main class="login"><section class="loginbox"><div class="login-logo">FQ</div><p class="eyebrow">FERAL HOSTING · PRIVATE CONSOLE</p><h1>Welcome back<span style="color:#a99aff">.</span></h1><p class="muted">Sign in to view your private slot dashboard.</p><?php if ($error !== ''): ?><p class="alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="post" action="<?php echo htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><label for="pw">Dashboard password</label><input id="pw" name="password" type="password" minlength="12" maxlength="72" autocomplete="current-password" required autofocus><button type="submit">Sign in&nbsp; →</button></form><p class="fine">Private read-only monitoring · No system changes</p></section></main>
<?php else: ?>
<div class="app-shell">
  <aside class="app-sidebar" aria-label="Primary navigation">
    <a class="brand-lockup" href="#overview" aria-label="Feral Quickbox home">
      <span class="brand-symbol">FQ<span class="brand-symbol-dot"></span></span>
      <span class="brand-copy"><strong>FERAL<span>QUICKBOX</span></strong><small>SERVER CONTROL PLANE</small></span>
    </a>
    <div class="sidebar-caption">CONTROL</div>
    <nav class="primary-nav">
      <a class="nav-item is-active" href="#overview" data-view-target="overview"><span class="nav-glyph">◫</span><span>Overview</span><i></i></a>
      <a class="nav-item" href="#resources" data-view-target="resources"><span class="nav-glyph">◉</span><span>Resources</span></a>
      <a class="nav-item" href="#bandwidth" data-view-target="network"><span class="nav-glyph">⌁</span><span>Network monitor</span></a>
      <a class="nav-item" href="#system" data-view-target="system"><span class="nav-glyph">⌘</span><span>System telemetry</span></a>
    </nav>
    <div class="sidebar-spacer"></div>
    <div class="sidebar-status">
      <div class="status-orbit"><span></span></div>
      <div><strong>Monitor online</strong><small>Read-only session</small></div>
      <span class="status-check">✓</span>
    </div>
    <div class="sidebar-foot">FQ DASHBOARD <span>·</span> PHASE 01</div>
  </aside>

  <main class="workspace" id="overview">
    <header class="topbar">
      <div class="topbar-left"><button class="mobile-menu" type="button" aria-label="Menu">☰</button><div><div class="breadcrumb">WORKSPACE <span>/</span> OVERVIEW</div><h1>System overview</h1></div></div>
      <div class="topbar-right">
        <div class="connection-state"><span class="online-dot"></span><span>LIVE MONITORING</span></div>
        <div class="host-chip"><span class="host-chip-icon">⌂</span><span><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <form class="logout-form" method="post" action="<?php echo htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><button class="logout" name="logout" value="1">Sign out <span>↗</span></button></form>
      </div>
    </header>

    <div class="workspace-view is-visible" data-view="overview" id="view-overview">
    <section class="welcome-row">
      <div><div class="eyebrow"><span class="eyebrow-line"></span> YOUR HOSTING ENVIRONMENT</div><h2>Good to see you<span class="welcome-period">.</span></h2><p class="welcome-copy">Here’s what’s happening across your server right now.</p></div>
      <div class="refresh-status" id="updated"><span class="online-dot"></span><span>Establishing connection…</span></div>
    </section>

    <section class="resource-section" id="resources" aria-label="Resource utilization">
      <div class="section-heading"><div><span class="section-index">01</span><h3>Resource utilization</h3></div><span class="section-note">LIVE HOST METRICS</span></div>
      <div class="resource-grid">
        <article class="resource-card cpu-card">
          <div class="resource-card-top"><span class="resource-name"><span class="resource-icon">⌘</span> PROCESSOR</span><span class="resource-tag">CPU</span></div>
          <div class="resource-main"><div class="resource-reading" id="cpu">—<small>%</small></div><div class="resource-orb cpu-orb"><span>CPU</span><b>CORE</b></div></div>
          <div class="resource-track"><i id="cpu-bar"></i></div><div class="resource-bottom"><span>Host processor load</span><strong id="cpu-state">Sampling…</strong></div>
        </article>
        <article class="resource-card memory-card">
          <div class="resource-card-top"><span class="resource-name"><span class="resource-icon">▤</span> MEMORY</span><span class="resource-tag">RAM</span></div>
          <div class="resource-main"><div class="resource-reading" id="mem">—<small>%</small></div><div class="resource-orb memory-orb"><span>RAM</span><b>MEM</b></div></div>
          <div class="resource-track"><i id="mem-bar"></i></div><div class="resource-bottom"><span id="mem-note">Memory utilization</span><strong id="mem-card-detail">Waiting</strong></div>
        </article>
        <article class="resource-card storage-card">
          <div class="resource-card-top"><span class="resource-name"><span class="resource-icon">▣</span> STORAGE</span><span class="resource-tag">HOME FS</span></div>
          <div class="resource-main"><div class="resource-reading" id="disk">—<small>%</small></div><div class="resource-orb storage-orb"><span>DISK</span><b>FS</b></div></div>
          <div class="resource-track"><i id="disk-bar"></i></div><div class="resource-bottom"><span id="disk-note">Filesystem usage</span><strong id="disk-card-detail">Waiting</strong></div>
        </article>
        <article class="resource-card uptime-card">
          <div class="resource-card-top"><span class="resource-name"><span class="resource-icon">◷</span> AVAILABILITY</span><span class="resource-tag">UPTIME</span></div>
          <div class="uptime-reading" id="uptime">—</div><div class="uptime-subtitle">Host operating time</div>
          <div class="uptime-rail"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
          <div class="resource-bottom"><span>Since last boot</span><strong>HOST LEVEL</strong></div>
        </article>
      </div>
    </section>

    <section class="monitor-grid">
      <article class="monitor-panel network-panel" id="bandwidth">
        <div class="panel-heading"><div><div class="panel-overline"><span class="panel-spark"></span> BANDWIDTH ANALYTICS</div><h3>Network activity</h3><p>Incoming and outgoing traffic over time</p></div><span class="live-pill"><span class="online-dot"></span> LIVE <b>·</b> 5 SEC</span></div>
        <div class="chart-labels"><span>TRANSFER RATE</span><span>RECENT ACTIVITY</span></div>
        <div class="chart-wrap"><canvas id="network-chart" role="img" aria-label="Recent download and upload throughput chart"></canvas></div>
        <div class="network-legend"><span><i class="legend-down"></i> DOWNLOAD</span><span><i class="legend-up"></i> UPLOAD</span></div>
        <div class="traffic-readouts"><div class="traffic-box download-box"><span><i>↓</i> DOWNLOAD SPEED</span><strong id="rx">— <em>/s</em></strong><small>Inbound traffic</small></div><div class="traffic-box upload-box"><span><i>↑</i> UPLOAD SPEED</span><strong id="tx">— <em>/s</em></strong><small>Outbound traffic</small></div></div>
      </article>

      <article class="monitor-panel health-panel" id="system">
        <div class="panel-heading"><div><div class="panel-overline"><span class="panel-spark health-spark"></span> SYSTEM DIAGNOSTICS</div><h3>Host health</h3><p>Load average and resource details</p></div><span class="health-emblem">⌘</span></div>
        <div class="load-title">SYSTEM LOAD AVERAGE <span>· 1 / 5 / 15 MIN</span></div>
        <div class="load-readings"><div class="load-reading load-one"><strong id="l1">—</strong><small>1 MIN</small></div><div class="load-reading load-five"><strong id="l5">—</strong><small>5 MIN</small></div><div class="load-reading load-fifteen"><strong id="l15">—</strong><small>15 MIN</small></div></div>
        <div class="diagnostic-list"><div class="diagnostic-row"><span>CPU utilization</span><strong id="cpu-detail">Waiting</strong></div><div class="diagnostic-row"><span>Memory allocation</span><strong id="mem-detail">Waiting</strong></div><div class="diagnostic-row"><span>Filesystem capacity</span><strong id="disk-detail">Waiting</strong></div><div class="diagnostic-row"><span>Polling interval</span><strong>5 seconds</strong></div></div>
      </article>
    </section>

    <section class="scope-banner"><span class="scope-symbol">i</span><div><strong>Shared-hosting visibility notice</strong><p id="scope">Some values may describe the shared host rather than only your account. Disk capacity is not a Feral quota measurement.</p></div><span class="scope-readonly">READ ONLY</span></section>
    <footer class="workspace-footer"><span><b>FERAL QUICKBOX</b> <i>·</i> PRIVATE SERVER DASHBOARD</span><span>ROOTLESS DEPLOYMENT <i>·</i> NO SERVICE CHANGES</span></footer>
    </div>

    <section class="workspace-view" data-view="resources" id="view-resources" hidden>
      <div class="view-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> RESOURCE EXPLORER</div><h2>Resource details</h2><p>Live measurements reported by the host operating system.</p></div><span class="view-live"><span class="online-dot"></span> 5 SECOND POLL</span></div>
      <div class="detail-grid">
        <article class="detail-panel"><div class="detail-top"><span>PROCESSOR</span><b>CPU</b></div><strong class="detail-number" id="resource-cpu">—</strong><p>Host CPU utilization</p><div class="detail-stat"><span>Logical CPU cores</span><strong id="resource-cores">—</strong></div><div class="detail-stat"><span>Current load · 1 min</span><strong id="resource-load">—</strong></div></article>
        <article class="detail-panel"><div class="detail-top"><span>MEMORY</span><b>RAM</b></div><strong class="detail-number" id="resource-memory">—</strong><p>Used memory / total memory</p><div class="detail-stat"><span>Available memory</span><strong id="resource-memory-free">—</strong></div><div class="detail-stat"><span>Memory usage</span><strong id="resource-memory-percent">—</strong></div></article>
        <article class="detail-panel"><div class="detail-top"><span>FILESYSTEM</span><b>HOME FS</b></div><strong class="detail-number" id="resource-disk">—</strong><p>Used space / filesystem capacity</p><div class="detail-stat"><span>Free filesystem space</span><strong id="resource-disk-free">—</strong></div><div class="detail-stat"><span>Filesystem usage</span><strong id="resource-disk-percent">—</strong></div></article>
      </div>
      <div class="detail-callout"><strong>Measurement scope</strong><p>On shared hosting, CPU and memory figures may describe the host rather than your individual slot. Filesystem capacity is not a Feral account quota.</p></div>
    </section>

    <section class="workspace-view" data-view="network" id="view-network" hidden>
      <div class="view-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> TRAFFIC INSPECTOR</div><h2>Network monitor</h2><p>Aggregate receive and transmit throughput sampled every five seconds.</p></div><span class="view-live"><span class="online-dot"></span> LIVE SAMPLING</span></div>
      <article class="detail-panel network-detail-panel"><div class="detail-top"><span>THROUGHPUT HISTORY</span><b>LAST 2 MINUTES</b></div><div class="large-chart"><canvas id="network-chart-detail" role="img" aria-label="Detailed network throughput chart"></canvas></div><div class="network-detail-readouts"><div><span>DOWNLOAD</span><strong id="network-download-detail">—</strong></div><div><span>UPLOAD</span><strong id="network-upload-detail">—</strong></div></div></article>
      <div class="detail-callout"><strong>Network scope</strong><p>Traffic is aggregated from visible non-loopback interfaces in <code>/proc/net/dev</code>. On a shared host, these values may include other users’ traffic. The chart history starts when this dashboard session opens.</p></div>
    </section>

    <section class="workspace-view" data-view="system" id="view-system" hidden>
      <div class="view-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> HOST TELEMETRY</div><h2>System diagnostics</h2><p>Kernel and runtime information plus live load averages.</p></div><span class="view-live">READ ONLY</span></div>
      <div class="detail-grid system-detail-grid">
        <article class="detail-panel"><div class="detail-top"><span>LOAD AVERAGE</span><b>1 / 5 / 15 MIN</b></div><div class="load-detail-grid"><div><strong id="system-load-1">—</strong><span>1 MIN</span></div><div><strong id="system-load-5">—</strong><span>5 MIN</span></div><div><strong id="system-load-15">—</strong><span>15 MIN</span></div></div><p>Host-wide load average; not a per-account CPU percentage.</p></article>
        <article class="detail-panel"><div class="detail-top"><span>PLATFORM</span><b>RUNTIME</b></div><div class="detail-stat"><span>Operating system</span><strong id="platform-system">—</strong></div><div class="detail-stat"><span>Kernel release</span><strong id="platform-kernel">—</strong></div><div class="detail-stat"><span>Architecture</span><strong id="platform-arch">—</strong></div><div class="detail-stat"><span>PHP runtime</span><strong id="platform-php">—</strong></div></article>
      </div>
      <div class="detail-callout"><strong>No service controls</strong><p>This dashboard remains read-only. It does not start, stop, restart, reconfigure or inspect qBittorrent, Deluge, Nginx or system services.</p></div>
    </section>
  </main>
</div>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
(function(){'use strict';
var base=<?php echo json_encode($base); ?>,last=null,history=[],maxPoints=30;
function el(id){return document.getElementById(id)}
function bytes(n){if(!isFinite(n)||n<0)return '—';var u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<4){n/=1024;i++}return n.toFixed(i?1:0)+' '+u[i]}
function percent(id,bar,obj,note,detail){var v=obj&&typeof obj.percent==='number'?obj.percent:null;el(id).innerHTML=v===null?'—<small>%</small>':v.toFixed(1)+'<small>%</small>';el(bar).style.width=(v===null?0:Math.max(0,Math.min(100,v)))+'%';if(note&&obj)el(note).textContent=bytes(obj.used)+' / '+bytes(obj.total);if(detail&&obj)el(detail).textContent=bytes(obj.used)+' of '+bytes(obj.total)}
function uptime(s){if(typeof s!=='number')return '—';var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return(d?d+'d ':'')+h+'h '+m+'m'}
function chartOne(id){var c=el(id);if(!c||!c.clientWidth)return;var w=c.clientWidth,h=c.clientHeight,dpr=Math.max(1,window.devicePixelRatio||1);c.width=Math.round(w*dpr);c.height=Math.round(h*dpr);var x=c.getContext('2d');x.setTransform(dpr,0,0,dpr,0,0);x.clearRect(0,0,w,h);var pad=8,plotH=h-18,plotW=w-pad*2,max=1;history.forEach(function(p){max=Math.max(max,p.rx,p.tx)});max*=1.2;for(var g=0;g<4;g++){var y=pad+(plotH/3)*g;x.beginPath();x.strokeStyle='rgba(137,155,184,.13)';x.lineWidth=1;x.moveTo(pad,y);x.lineTo(w-pad,y);x.stroke()}function line(key,color){if(history.length<2)return;x.beginPath();history.forEach(function(p,i){var px=pad+(plotW*i/Math.max(1,history.length-1)),py=pad+plotH-(p[key]/max)*plotH;if(i===0)x.moveTo(px,py);else x.lineTo(px,py)});x.strokeStyle=color;x.lineWidth=2;x.lineJoin='round';x.lineCap='round';x.stroke();var p=history[history.length-1],px=pad+plotW,py=pad+plotH-(p[key]/max)*plotH;x.beginPath();x.arc(px,py,3,0,Math.PI*2);x.fillStyle=color;x.fill()}line('rx','#a895ff');line('tx','#69b9ff')}function chart(){chartOne('network-chart');chartOne('network-chart-detail')}
function put(id,value){var node=el(id);if(node)node.textContent=value}
function poll(){
 var endpoint=new URL(window.location.href);
 endpoint.search='?action=metrics';endpoint.hash='';
 fetch(endpoint.toString(),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
 .then(function(r){if(r.status===401){location.reload();throw Error('session')}if(!r.ok)throw Error('HTTP '+r.status);return r.json()})
 .then(function(d){
  if(!d||d.ok!==true||!d.metrics)throw Error('Invalid metrics response');
  var m=d.metrics;
  el('cpu').innerHTML=m.cpu===null?'—<small>%</small>':m.cpu.toFixed(1)+'<small>%</small>';
  el('cpu-bar').style.width=(m.cpu===null?0:m.cpu)+'%';
  el('cpu-state').textContent=m.cpu===null?'Sampling…':'Live sample';
  el('cpu-detail').textContent=m.cpu===null?'First sample pending':m.cpu.toFixed(1)+'%';
  percent('mem','mem-bar',m.memory,'mem-note','mem-detail');
  percent('disk','disk-bar',m.disk,'disk-note','disk-detail');
  el('uptime').textContent=uptime(m.uptime);
  var l=m.load||[];
  el('l1').textContent=l.length?Number(l[0]).toFixed(2):'—';
  el('l5').textContent=l.length?Number(l[1]).toFixed(2):'—';
  el('l15').textContent=l.length?Number(l[2]).toFixed(2):'—';
  put('system-load-1',l.length?Number(l[0]).toFixed(2):'—');
  put('system-load-5',l.length?Number(l[1]).toFixed(2):'—');
  put('system-load-15',l.length?Number(l[2]).toFixed(2):'—');
  el('scope').textContent=m.scope;
  put('resource-cpu',m.cpu===null?'Waiting for sample':m.cpu.toFixed(1)+'%');
  put('resource-cores',m.cpu_cores||'Unavailable');
  put('resource-load',l.length?Number(l[0]).toFixed(2):'Unavailable');
  put('resource-memory',m.memory?bytes(m.memory.used)+' / '+bytes(m.memory.total):'Unavailable');
  put('resource-memory-free',m.memory?bytes(m.memory.available):'Unavailable');
  put('resource-memory-percent',m.memory?m.memory.percent.toFixed(1)+'%':'Unavailable');
  put('resource-disk',m.disk?bytes(m.disk.used)+' / '+bytes(m.disk.total):'Unavailable');
  put('resource-disk-free',m.disk?bytes(m.disk.free):'Unavailable');
  put('resource-disk-percent',m.disk?m.disk.percent.toFixed(1)+'%':'Unavailable');
  var p=m.platform||{};
  put('platform-system',p.system||'Unavailable');put('platform-kernel',p.kernel||'Unavailable');
  put('platform-arch',p.architecture||'Unavailable');put('platform-php',p.php||'Unavailable');
  var now=Date.now(),rx=0,tx=0;
  if(m.network&&last){var dt=(now-last.t)/1000;if(dt>0&&dt<60){rx=Math.max(0,m.network.rx-last.rx)/dt;tx=Math.max(0,m.network.tx-last.tx)/dt}}
  if(m.network){last={rx:m.network.rx,tx:m.network.tx,t:now};el('rx').innerHTML=bytes(rx)+' <em>/s</em>';el('tx').innerHTML=bytes(tx)+' <em>/s</em>';history.push({rx:rx,tx:tx});if(history.length>maxPoints)history.shift();chart();put('network-download-detail',bytes(rx)+'/s');put('network-upload-detail',bytes(tx)+'/s')}
  else{el('rx').textContent='Unavailable';el('tx').textContent='Unavailable'}
  el('updated').innerHTML='<span class="online-dot"></span>Updated '+new Date(m.time*1000).toLocaleTimeString();
 })
 .catch(function(e){if(e.message!=='session'){el('updated').textContent='Live data unavailable · '+(e&&e.message?e.message:'Unknown error')}});
}
var nav=document.querySelectorAll('[data-view-target]');function showView(name){document.querySelectorAll('[data-view]').forEach(function(v){var active=v.getAttribute('data-view')===name;v.hidden=!active;v.classList.toggle('is-visible',active)});nav.forEach(function(a){var active=a.getAttribute('data-view-target')===name;a.classList.toggle('is-active',active);if(active)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current')});var title={overview:'System overview',resources:'Resource details',network:'Network monitor',system:'System diagnostics'};var h=document.querySelector('.topbar h1');if(h)h.textContent=title[name]||title.overview;var crumb=document.querySelector('.breadcrumb');if(crumb)crumb.innerHTML='WORKSPACE <span>/</span> '+(name==='network'?'NETWORK':name.toUpperCase());if(name==='network')chart()}nav.forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();showView(a.getAttribute('data-view-target'))})});var menu=document.querySelector('.mobile-menu');if(menu)menu.addEventListener('click',function(){document.querySelector('.app-sidebar').classList.toggle('mobile-nav-open')});poll();var timer=setInterval(poll,5000);window.addEventListener('resize',chart);window.addEventListener('beforeunload',function(){clearInterval(timer)})
}());
</script>
<?php endif; ?></body></html>
