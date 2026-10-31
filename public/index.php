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
header("Content-Security-Policy: default-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
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
            $mem = array('total' => $m['MemTotal'], 'used' => max(0, $m['MemTotal'] - $avail), 'percent' => round(100 * max(0, $m['MemTotal'] - $avail) / $m['MemTotal'], 1));
        }
    }
    $home = !empty($config['HOME_PATH']) && is_dir($config['HOME_PATH']) ? $config['HOME_PATH'] : (function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid())['dir'] : dirname(dirname(__DIR__)));
    $disk = null;
    $total = @disk_total_space($home);
    $free = @disk_free_space($home);
    if ($total && $free !== false) $disk = array('total' => (int) $total, 'used' => (int) ($total - $free), 'percent' => round(100 * ($total - $free) / $total, 1));
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
    return array('time' => time(), 'cpu' => $cpu, 'memory' => $mem, 'disk' => $disk, 'network' => $net, 'uptime' => $uptime, 'load' => is_array($load) ? $load : null,
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
:root{color-scheme:dark;--bg:#0b1020;--panel:#131e34;--line:#26344e;--text:#edf3ff;--muted:#91a0bd;--purple:#9b8cff;--blue:#64a9ff;--green:#52d6a0;--amber:#f4bd68;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 80% -20%,#29264d 0,transparent 42%),var(--bg);color:var(--text);min-height:100vh}.shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}.side{padding:28px 18px;border-right:1px solid var(--line);background:#0a1020}.brand{font-size:17px;font-weight:800;padding:0 8px 42px}.brand b{color:var(--purple)}.tag{font-size:9px;letter-spacing:1.7px;color:#7182a4;font-weight:800;margin:0 10px 13px}.nav{padding:13px 12px;border-radius:9px;background:#211f40;color:#fff;font-size:13px}.sidefoot{position:absolute;bottom:25px;color:#8392ad;font-size:11px}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:8px}.main{max-width:1450px;width:100%;padding:34px clamp(18px,4vw,58px);margin:auto}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:22px}.eyebrow{font-size:9px;letter-spacing:1.7px;color:#8293b3;font-weight:800;margin:0 0 8px}.top h1{font-size:25px;margin:0}.pill{font-size:11px;color:#b9c6df;border:1px solid var(--line);border-radius:30px;padding:9px 13px}.logout{background:transparent;border:1px solid var(--line);color:#b9c6df;border-radius:8px;padding:9px 12px;cursor:pointer}.welcome{display:flex;justify-content:space-between;align-items:end;padding:27px 0 17px}.welcome h2{font-size:17px;margin:0 0 5px}.muted{font-size:12px;color:var(--muted);margin:0}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{border:1px solid var(--line);border-radius:14px;background:linear-gradient(145deg,#17233c,#111a2e);padding:18px;box-shadow:0 12px 30px #0002}.card label{font-size:11px;color:#aab8d2}.value{font-size:30px;font-weight:750;letter-spacing:-1px;margin:14px 0 11px}.value small{font-size:12px;color:#8292b0}.meter{width:100%;height:5px;border-radius:9px;background:#2a3852;overflow:hidden}.meter i{display:block;height:100%;width:0;background:var(--purple)}.card:nth-child(2) .meter i{background:var(--blue)}.card:nth-child(3) .meter i{background:var(--green)}.note{font-size:10px;color:#8191ad;margin:9px 0 0}.lower{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-top:14px}.panel h3{font-size:15px;margin:0 0 22px}.rates{display:flex;gap:55px}.rates span{display:block;font-size:10px;color:#8b9ab5;margin-bottom:8px}.rates strong{font-size:19px}.load{display:flex;gap:22px}.load div{display:grid;gap:7px}.load strong{font-size:23px}.load span{font-size:10px;color:#8292b0}.notice{margin-top:14px;padding:15px 17px;border:1px solid #33415c;border-radius:11px;background:#18243b;color:#91a0bd;font-size:10px;line-height:1.6}.notice b{display:block;color:#d2dced;font-size:11px;margin-bottom:4px}.foot{display:flex;justify-content:space-between;color:#667895;font-size:9px;padding-top:23px}.login{min-height:100vh;display:grid;place-items:center;padding:20px}.loginbox{width:min(100%,390px);padding:34px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 25px 70px #0005}.logo{width:48px;height:48px;border-radius:15px;background:linear-gradient(135deg,#a18cff,#5d73df);display:grid;place-items:center;font-weight:900;margin-bottom:25px}.loginbox h1{font-size:28px;margin:0 0 8px}.loginbox form{display:grid;gap:10px;margin-top:26px}.loginbox label{font-size:11px;color:#b8c4da}.loginbox input{height:44px;border:1px solid var(--line);border-radius:8px;background:#0a1224;color:#fff;padding:0 12px}.loginbox button{height:44px;border:0;border-radius:8px;background:linear-gradient(100deg,#7569dc,#a08bf5);color:#fff;font-weight:700;margin-top:5px}.alert{margin-top:15px;color:#ff9da8;font-size:11px}.fine{text-align:center;color:#7182a1;font-size:10px;margin-top:23px}@media(max-width:1050px){.cards{grid-template-columns:repeat(2,1fr)}.lower{grid-template-columns:1fr}}@media(max-width:650px){.shell{grid-template-columns:1fr}.side{padding:15px;border:0;border-bottom:1px solid var(--line)}.brand{padding:0}.side .tag,.nav,.sidefoot{display:none}.main{padding:22px 14px}.top{align-items:flex-start}.top h1{font-size:21px}.pill{font-size:9px}.welcome{display:block}.welcome .muted{margin-top:5px}.cards{gap:9px}.card{padding:13px;min-height:135px}.value{font-size:25px}.lower{gap:9px}.panel{padding:16px}.rates{gap:28px}.foot{display:grid;gap:8px}}
</style></head><body>
<?php if (!$auth): ?>
<main class="login"><section class="loginbox"><div class="logo">FQ</div><p class="eyebrow">FERAL HOSTING · PRIVATE CONSOLE</p><h1>Welcome back<span style="color:#9b8cff">.</span></h1><p class="muted">Sign in to view your slot dashboard.</p><?php if ($error !== ''): ?><p class="alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="post" action="<?php echo htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><label for="pw">Dashboard password</label><input id="pw" name="password" type="password" minlength="12" maxlength="72" autocomplete="current-password" required autofocus><button type="submit">Sign in →</button></form><p class="fine">Private read-only monitoring · No system changes</p></section></main>
<?php else: ?>
<div class="shell"><aside class="side"><div class="brand">Feral<b>Quickbox</b></div><p class="tag">WORKSPACE</p><div class="nav">◫ &nbsp; Overview</div><p class="sidefoot"><span class="dot"></span>Monitoring active<br><br>Read-only mode</p></aside>
<main class="main"><header class="top"><div><p class="eyebrow">SERVER OVERVIEW</p><h1>Dashboard</h1></div><div><span class="pill"><span class="dot"></span><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></span> <form style="display:inline" method="post" action="<?php echo htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><button class="logout" name="logout" value="1">Sign out</button></form></div></header>
<section class="welcome"><div><h2>Your slot at a glance</h2><p class="muted">Live system telemetry from the Feral host.</p></div><p class="muted" id="updated"><span class="dot"></span>Connecting…</p></section>
<section class="cards">
<article class="card"><label>CPU usage</label><div class="value" id="cpu">—<small>%</small></div><div class="meter"><i id="cpu-bar"></i></div><p class="note">Host CPU · first sample pending</p></article>
<article class="card"><label>Memory usage</label><div class="value" id="mem">—<small>%</small></div><div class="meter"><i id="mem-bar"></i></div><p class="note" id="mem-note">Waiting for sample</p></article>
<article class="card"><label>Home filesystem</label><div class="value" id="disk">—<small>%</small></div><div class="meter"><i id="disk-bar"></i></div><p class="note" id="disk-note">Filesystem, not quota</p></article>
<article class="card"><label>System uptime</label><div class="value" id="uptime">—</div><div class="meter"><i style="width:100%;background:#f4bd68"></i></div><p class="note">Host uptime</p></article>
</section>
<section class="lower"><article class="panel"><p class="eyebrow">BANDWIDTH</p><h3>Network activity</h3><div class="rates"><div><span>↓ Download</span><strong id="rx">—</strong></div><div><span>↑ Upload</span><strong id="tx">—</strong></div></div></article><article class="panel"><p class="eyebrow">SYSTEM</p><h3>Load average</h3><div class="load"><div><strong id="l1">—</strong><span>1 min</span></div><div><strong id="l5">—</strong><span>5 min</span></div><div><strong id="l15">—</strong><span>15 min</span></div></div></article></section>
<div class="notice"><b>Shared-hosting metric scope</b><span id="scope">Some values may describe the shared host rather than only your account. Disk capacity is not a Feral quota measurement.</span></div>
<footer class="foot"><span>Feral Quickbox Dashboard · Phase 1 monitoring</span><span>Read-only · refreshes every 5 seconds</span></footer></main></div>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
(function(){'use strict';var base=<?php echo json_encode($base); ?>,last=null;function el(id){return document.getElementById(id)}function bytes(n){if(!isFinite(n)||n<0)return '—';var u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<4){n/=1024;i++}return n.toFixed(i?1:0)+' '+u[i]}function percent(id,bar,obj,note){var v=obj&&typeof obj.percent==='number'?obj.percent:null;el(id).innerHTML=v===null?'—<small>%</small>':v.toFixed(1)+'<small>%</small>';el(bar).style.width=(v===null?0:Math.max(0,Math.min(100,v)))+'%';if(note&&obj)el(note).textContent=bytes(obj.used)+' used of '+bytes(obj.total)}function uptime(s){if(typeof s!=='number')return '—';var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return(d?d+'d ':'')+h+'h '+m+'m'}function poll(){fetch(base+'/?action=metrics',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(r.status===401){location.reload();throw Error('session')}if(!r.ok)throw Error('http');return r.json()}).then(function(d){var m=d.metrics;el('cpu').innerHTML=m.cpu===null?'—<small>%</small>':m.cpu.toFixed(1)+'<small>%</small>';el('cpu-bar').style.width=(m.cpu===null?0:m.cpu)+'%';percent('mem','mem-bar',m.memory,'mem-note');percent('disk','disk-bar',m.disk,'disk-note');el('uptime').textContent=uptime(m.uptime);var l=m.load||[];el('l1').textContent=l.length?Number(l[0]).toFixed(2):'—';el('l5').textContent=l.length?Number(l[1]).toFixed(2):'—';el('l15').textContent=l.length?Number(l[2]).toFixed(2):'—';el('scope').textContent=m.scope;var now=Date.now(),rx=0,tx=0;if(last){var dt=(now-last.t)/1000;if(dt>0&&dt<60){rx=Math.max(0,m.network.rx-last.rx)/dt;tx=Math.max(0,m.network.tx-last.tx)/dt}}if(m.network){last={rx:m.network.rx,tx:m.network.tx,t:now};el('rx').textContent=bytes(rx)+'/s';el('tx').textContent=bytes(tx)+'/s'}else{el('rx').textContent='Unavailable';el('tx').textContent='Unavailable'}el('updated').textContent='Updated '+new Date(m.time*1000).toLocaleTimeString()}).catch(function(e){if(e.message!=='session')el('updated').textContent='Connection issue · retrying'})}poll();var timer=setInterval(poll,5000);addEventListener('beforeunload',function(){clearInterval(timer)})}());
</script>
<?php endif; ?></body></html>
