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
header("Content-Security-Policy: default-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

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
<html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1020"><title>Feral Quickbox Dashboard</title>
<style nonce="<?php echo $e($nonce); ?>">
:root{color-scheme:dark;--bg:#080d19;--bg1:#332b63;--bg2:#102b43;--surface:#101a2b;--panel:#121e33;--panel2:#172640;--side:rgba(8,14,27,.94);--line:#253650;--line2:#2c405d;--text:#eef4ff;--text2:#bdcbe1;--muted:#899bb8;--faint:#667b9b;--purple:#a895ff;--blue:#69b9ff;--green:#55d6a2;--amber:#f2bd6b;--red:#ff8294;--track:#293a55;--shadow:#0003;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
:root[data-theme=light]{color-scheme:light;--bg:#eef2f9;--bg1:#e1dcff;--bg2:#d6ecff;--surface:#fff;--panel:#fff;--panel2:#f3f6fc;--side:rgba(255,255,255,.95);--line:#d6dfed;--line2:#c8d4e6;--text:#16213a;--text2:#34456a;--muted:#5d6f90;--faint:#7d8cab;--purple:#6c57e0;--blue:#1f7fd6;--green:#10976a;--amber:#b57812;--red:#d03c52;--track:#dfe6f2;--shadow:#1b2a4a14}
.a1{--accent:#a895ff}.a2{--accent:#69b9ff}.a3{--accent:#55d6a2}.a4{--accent:#f2bd6b}.acc{color:var(--purple)}.mt14{margin-top:14px}.note{margin-top:12px;font-size:10px}.hint{font-size:11px;line-height:1.6}.inl{display:inline}.alertnote{color:var(--amber)}.meter i.full{width:100%;opacity:.35}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 78% -18%,var(--bg1) 0,transparent 38%),radial-gradient(ellipse at 0 60%,var(--bg2) 0,transparent 30%),var(--bg);color:var(--text)}
a{color:inherit;text-decoration:none}button{font:inherit}
.sidebar{position:fixed;inset:0 auto 0 0;width:240px;padding:26px 16px;background:var(--side);border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:5;backdrop-filter:blur(16px)}
.brand{display:flex;align-items:center;gap:12px;padding:0 8px 34px}.brand-mark{width:39px;height:39px;display:grid;place-items:center;border-radius:13px;background:linear-gradient(140deg,#a895ff,#5e72dc);font-size:14px;font-weight:900;color:#fff;box-shadow:0 8px 26px #8d7aff44}.brand-name{font-weight:800;font-size:16px;letter-spacing:-.5px}.brand-name span{color:var(--purple)}.brand-sub{font-size:9px;color:var(--faint);letter-spacing:1.5px;margin-top:3px}
.nav-label{font-size:9px;font-weight:800;letter-spacing:1.8px;color:var(--faint);padding:0 12px 10px}.nav-link{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--muted);font-size:12px;margin:2px 0;transition:.2s}.nav-link:hover,.nav-link.active{color:var(--text);background:var(--panel2)}.nav-icon{width:20px;text-align:center;font-size:16px;color:var(--purple)}
.side-bottom{margin-top:auto;border:1px solid var(--line);background:var(--panel2);border-radius:13px;padding:14px}.side-bottom strong{display:block;font-size:11px;margin:0 0 6px}.side-bottom span{font-size:10px;color:var(--muted);line-height:1.5}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 10px #55d6a277;margin-right:7px;vertical-align:middle}.dot.off{background:var(--faint);box-shadow:none}
.main{margin-left:240px;max-width:1600px;padding:26px clamp(18px,3.4vw,52px) 34px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:20px;border-bottom:1px solid var(--line)}.crumb{font-size:9px;letter-spacing:1.8px;color:var(--faint);font-weight:800;margin-bottom:7px}.topbar h1{font-size:23px;letter-spacing:-.8px;margin:0}.top-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:flex-end}
.pill{border:1px solid var(--line2);background:var(--panel2);border-radius:30px;padding:8px 13px;font-size:10px;color:var(--text2);white-space:nowrap}.btn{border:1px solid var(--line2);background:var(--panel2);color:var(--text2);border-radius:9px;padding:8px 12px;font-size:10px;cursor:pointer;transition:.15s}.btn:hover:not(:disabled){border-color:var(--purple);color:var(--text)}.btn:disabled{opacity:.4;cursor:not-allowed}.btn.danger:hover:not(:disabled){border-color:var(--red);color:var(--red)}
.intro{display:flex;align-items:end;justify-content:space-between;gap:12px;padding:24px 0 16px}.intro h2{font-size:17px;letter-spacing:-.3px;margin:0 0 5px}.muted{color:var(--muted);font-size:11px;margin:0}.updated{font-size:10px;color:var(--muted);white-space:nowrap}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.metric{position:relative;overflow:hidden;border:1px solid var(--line);border-radius:15px;padding:16px;background:var(--panel);box-shadow:0 14px 34px var(--shadow)}.metric:after{content:"";position:absolute;width:100px;height:100px;border-radius:50%;right:-42px;top:-52px;background:var(--accent);opacity:.13}.metric-head{display:flex;justify-content:space-between;align-items:center}.metric-label{font-size:10px;color:var(--muted)}.metric-icon{width:30px;height:30px;display:grid;place-items:center;border:1px solid var(--line2);border-radius:9px;background:var(--panel2);color:var(--accent);font-size:14px}.metric-value{font-size:28px;font-weight:780;letter-spacing:-1.2px;margin:12px 0}.metric-value small{font-size:11px;color:var(--muted);font-weight:500;letter-spacing:0;margin-left:3px}
.meter{height:5px;background:var(--track);border-radius:9px;overflow:hidden}.meter i{display:block;height:100%;width:0;border-radius:9px;background:var(--accent);transition:width .45s ease}.meter i.hot{background:var(--red)}.meter i.warm{background:var(--amber)}.metric-foot{display:flex;justify-content:space-between;gap:8px;margin-top:9px;color:var(--faint);font-size:9px}.metric-foot span:last-child{color:var(--muted)}
.grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.85fr);gap:14px;margin-top:14px;align-items:start}.stack{display:grid;gap:14px;min-width:0}.panel{border:1px solid var(--line);border-radius:15px;padding:18px;background:var(--panel);box-shadow:0 14px 34px var(--shadow);min-width:0}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}.kicker{font-size:9px;letter-spacing:1.7px;color:var(--faint);font-weight:800;margin:0 0 6px}.panel h3{font-size:14px;margin:0;letter-spacing:-.2px}.tag{font-size:9px;border:1px solid #315a54;color:#3fbf8e;background:#16332f22;padding:6px 9px;border-radius:20px;white-space:nowrap}
.chart-wrap{height:180px;position:relative}.chart-wrap canvas{width:100%;height:100%;display:block}.legend{display:flex;gap:17px;color:var(--muted);font-size:9px;margin-top:7px}.legend i{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}.ld{background:var(--purple)}.lu{background:var(--blue)}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.cell{border:1px solid var(--line);background:var(--panel2);border-radius:11px;padding:12px}.cell span{display:block;color:var(--muted);font-size:9px;margin-bottom:7px}.cell strong{font-size:17px;letter-spacing:-.4px}.cell strong em{font-style:normal;font-size:9px;color:var(--muted);font-weight:500}
table{width:100%;border-collapse:collapse;font-size:11px}th{text-align:left;font-size:9px;letter-spacing:1.2px;color:var(--faint);font-weight:800;padding:0 8px 9px}td{padding:10px 8px;border-top:1px solid var(--line);vertical-align:middle}td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}.tw{overflow-x:auto}.nm{font-weight:650}.sub{display:block;font-size:9px;color:var(--faint);margin-top:2px;font-weight:400}.acts{display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap}.empty{padding:18px 8px;color:var(--muted);font-size:11px;line-height:1.6}.empty code,.notice code{background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:1px 5px;font-size:10px}
.three{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.three .cell strong{display:block;font-size:19px}.three .cell span{margin:5px 0 0;order:2}.rows{display:grid;gap:10px;margin-top:14px}.row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:9px;font-size:10px}.row span{color:var(--muted)}.row strong{font-weight:600;color:var(--text2);text-align:right}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}.app{display:flex;align-items:center;gap:9px;padding:11px;border:1px solid var(--line);background:var(--panel2);border-radius:11px;font-size:11px;transition:.15s}.app:hover{border-color:var(--purple)}.app b{width:24px;height:24px;border-radius:7px;display:grid;place-items:center;background:linear-gradient(140deg,#a895ff,#5e72dc);color:#fff;font-size:11px;flex:0 0 24px}
.notice{display:flex;gap:12px;align-items:flex-start;margin-top:14px;padding:13px 15px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--muted);font-size:10px;line-height:1.55}.notice-icon{width:23px;height:23px;flex:0 0 23px;border-radius:7px;display:grid;place-items:center;background:var(--panel2);color:var(--purple);font-weight:800}.notice strong{display:block;color:var(--text2);font-size:10px;margin-bottom:3px}
.footer{display:flex;justify-content:space-between;gap:12px;color:var(--faint);font-size:9px;padding-top:20px}
.toast{position:fixed;right:18px;bottom:18px;max-width:320px;padding:12px 15px;border-radius:11px;border:1px solid var(--line2);background:var(--panel);color:var(--text);font-size:11px;box-shadow:0 14px 40px #0006;opacity:0;transform:translateY(8px);transition:.25s;pointer-events:none;z-index:20}.toast.show{opacity:1;transform:none}.toast.err{border-color:var(--red)}
.login{min-height:100vh;display:grid;place-items:center;padding:20px}.loginbox{width:min(100%,400px);padding:36px;border:1px solid var(--line);border-radius:20px;background:var(--panel);box-shadow:0 25px 80px #0005}.login-logo{width:48px;height:48px;display:grid;place-items:center;border-radius:15px;background:linear-gradient(135deg,#a18cff,#5d73df);font-weight:900;color:#fff;margin-bottom:25px}.eyebrow{font-size:9px;letter-spacing:1.7px;color:var(--faint);font-weight:800;margin:0 0 9px}.loginbox h1{font-size:27px;margin:0 0 8px;letter-spacing:-.8px}.loginbox form{display:grid;gap:10px;margin-top:26px}.loginbox label{font-size:11px;color:var(--text2)}.loginbox input{height:45px;border:1px solid var(--line2);border-radius:9px;background:var(--surface);color:var(--text);padding:0 12px}.loginbox button{height:45px;border:0;border-radius:9px;background:linear-gradient(100deg,#7569dc,#a08bf5);color:#fff;font-weight:700;margin-top:5px;cursor:pointer}.alert{margin-top:15px;color:var(--red);font-size:11px}.fine{text-align:center;color:var(--faint);font-size:10px;margin-top:23px}
@media(max-width:1120px){.sidebar{width:200px}.main{margin-left:200px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}}
@media(max-width:680px){.sidebar{position:static;width:auto;height:auto;padding:13px 15px;border-right:0;border-bottom:1px solid var(--line)}.brand{padding:0}.nav-label,.nav-link,.side-bottom{display:none}.main{margin:0;padding:18px 13px 25px}.topbar{align-items:flex-start}.topbar h1{font-size:20px}.pill{font-size:9px;padding:7px}.intro{display:block;padding:20px 0 14px}.updated{display:block;margin-top:10px}.metrics{gap:9px}.metric{padding:13px}.metric-value{font-size:24px}.panel{padding:14px}.chart-wrap{height:150px}.footer{display:grid;gap:7px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{transition:none!important;scroll-behavior:auto!important}}
</style>
<script nonce="<?php echo $e($nonce); ?>">try{var t=localStorage.getItem('fqd-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t)}catch(x){}</script>
</head><body>
<?php if (!$auth): ?>
<main class="login"><section class="loginbox"><div class="login-logo">FQ</div><p class="eyebrow">SHARED SERVER · ROOTLESS CONSOLE</p><h1>Welcome back<span class="acc">.</span></h1><p class="muted">Sign in to view your private slot dashboard.</p><?php if ($error !== ''): ?><p class="alert" role="alert"><?php echo $e($error); ?></p><?php endif; ?>
<form method="post" action="<?php echo $e($base . '/'); ?>"><input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>"><label for="pw">Dashboard password</label><input id="pw" name="password" type="password" minlength="12" maxlength="72" autocomplete="current-password" required autofocus><button type="submit">Sign in&nbsp; →</button></form><p class="fine">No root · No sudo · Only your own processes</p></section></main>
<?php else: ?>
<aside class="sidebar"><div class="brand"><div class="brand-mark">FQ</div><div><div class="brand-name">Feral<span>Quickbox</span></div><div class="brand-sub">ROOTLESS · LITE</div></div></div><p class="nav-label">WORKSPACE</p><a class="nav-link active" href="#overview"><span class="nav-icon">▦</span>Overview</a><a class="nav-link" href="#network"><span class="nav-icon">⌁</span>Network</a><a class="nav-link" href="#services"><span class="nav-icon">⚙</span>Services</a><a class="nav-link" href="#apps"><span class="nav-icon">◫</span>Apps</a><a class="nav-link" href="#system"><span class="nav-icon">◈</span>System</a><div class="side-bottom"><strong><span class="dot"></span>Rootless mode</strong><span>Runs as your own user. Only your own processes can be listed or controlled — never root, never other users.</span></div></aside>
<main class="main" id="overview">
<header class="topbar"><div><div class="crumb"><?php echo $e(strtoupper($host)); ?> / OVERVIEW</div><h1>Dashboard</h1></div><div class="top-actions"><span class="pill"><span class="dot"></span><span id="whoami"><?php echo $e($host); ?></span></span><button class="btn" id="theme" type="button" aria-label="Toggle light / dark theme">◐ Theme</button><form class="inl" method="post" action="<?php echo $e($base . '/'); ?>"><input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>"><button class="btn" name="logout" value="1">Sign out</button></form></div></header>
<section class="intro"><div><h2>Welcome to your slot</h2><p class="muted">A live snapshot of host load, your own processes and your services.</p></div><p class="updated" id="updated"><span class="dot off"></span>Connecting to host…</p></section>
<section class="metrics" aria-label="Resource usage">
<article class="metric a1"><div class="metric-head"><span class="metric-label">Host CPU</span><span class="metric-icon">⌘</span></div><div class="metric-value" id="cpu">—<small>%</small></div><div class="meter"><i id="cpu-bar"></i></div><div class="metric-foot"><span id="cores">Shared processor</span><span id="cpu-state">Sampling…</span></div></article>
<article class="metric a2"><div class="metric-head"><span class="metric-label">Host memory</span><span class="metric-icon">▤</span></div><div class="metric-value" id="mem">—<small>%</small></div><div class="meter"><i id="mem-bar"></i></div><div class="metric-foot"><span>RAM utilisation</span><span id="mem-note">Waiting…</span></div></article>
<article class="metric a3"><div class="metric-head"><span class="metric-label" id="disk-label">Home filesystem</span><span class="metric-icon">▣</span></div><div class="metric-value" id="disk">—<small>%</small></div><div class="meter"><i id="disk-bar"></i></div><div class="metric-foot"><span id="disk-sub">Filesystem usage</span><span id="disk-note">Not quota</span></div></article>
<article class="metric a4"><div class="metric-head"><span class="metric-label">Host uptime</span><span class="metric-icon">◷</span></div><div class="metric-value" id="uptime">—</div><div class="meter"><i class="full"></i></div><div class="metric-foot"><span>Since last boot</span><span>Host-level</span></div></article>
</section>
<section class="grid">
<div class="stack">
<article class="panel" id="network"><div class="panel-head"><div><p class="kicker">TRAFFIC MONITOR</p><h3>Network activity</h3></div><span class="tag"><span class="dot"></span>LIVE · 5 SEC</span></div><div class="chart-wrap"><canvas id="network-chart" role="img" aria-label="Recent download and upload throughput chart"></canvas></div><div class="legend"><span><i class="ld"></i>Download</span><span><i class="lu"></i>Upload</span></div><div class="two"><div class="cell"><span>↓ DOWNLOAD SPEED</span><strong id="rx">— <em>/s</em></strong></div><div class="cell"><span>↑ UPLOAD SPEED</span><strong id="tx">— <em>/s</em></strong></div></div>
<div class="tw mt14"><table><thead><tr><th>INTERFACE</th><th class="num">↓ RECEIVE</th><th class="num">↑ SEND</th><th class="num">TOTAL IN</th><th class="num">TOTAL OUT</th></tr></thead><tbody id="ifaces"><tr><td colspan="5" class="empty">Waiting for data…</td></tr></tbody></table></div></article>
<article class="panel" id="services"><div class="panel-head"><div><p class="kicker">SERVICE CONTROL</p><h3>Your services</h3></div><span class="tag" id="svc-count">0 RUNNING</span></div><div class="tw"><table><thead><tr><th>SERVICE</th><th class="num">PROC</th><th class="num">MEMORY</th><th class="num">UPTIME</th><th class="num">ACTIONS</th></tr></thead><tbody id="svc"><tr><td colspan="5" class="empty">Waiting for data…</td></tr></tbody></table></div><p class="muted note alertnote" id="svc-notice" hidden></p><p class="muted note">Only processes owned by your own user are shown. Start/stop use fixed commands from your private <code>services.ini</code>; nothing typed in the browser is ever executed.</p></article>
</div>
<div class="stack">
<article class="panel" id="slot"><div class="panel-head"><div><p class="kicker">MY SLOT</p><h3>Your processes only</h3></div><span class="metric-icon">◉</span></div><div class="three"><div class="cell"><strong id="s-proc">—</strong><span>Processes</span></div><div class="cell"><strong id="s-mem">—</strong><span>Memory (PSS)</span></div><div class="cell"><strong id="s-cpu">—</strong><span>CPU (1 core=100%)</span></div></div></article>
<article class="panel" id="system"><div class="panel-head"><div><p class="kicker">HOST TELEMETRY</p><h3>System health</h3></div><span class="metric-icon">◈</span></div><div class="three"><div class="cell"><strong id="l1">—</strong><span>1 minute</span></div><div class="cell"><strong id="l5">—</strong><span>5 minutes</span></div><div class="cell"><strong id="l15">—</strong><span>15 minutes</span></div></div><div class="rows"><div class="row"><span>CPU sample</span><strong id="cpu-detail">Waiting</strong></div><div class="row"><span>Memory</span><strong id="mem-detail">Waiting</strong></div><div class="row"><span>Disk</span><strong id="disk-detail">Waiting</strong></div><div class="row"><span>Refresh interval</span><strong>5 seconds</strong></div></div></article>
<article class="panel" id="apps"><div class="panel-head"><div><p class="kicker">SHORTCUTS</p><h3>Apps</h3></div><span class="metric-icon">◫</span></div>
<?php if ($apps): ?><div class="apps"><?php foreach ($apps as $a): ?><a class="app" href="<?php echo $e($a['url']); ?>" target="_blank" rel="noopener noreferrer"><b><?php echo $e(strtoupper(fqd_substr($a['label'], 0, 1))); ?></b><?php echo $e($a['label']); ?></a><?php endforeach; ?></div>
<?php else: ?><p class="muted hint">No shortcuts yet. Add to your private <code>config.env</code>:<br><code>APPS="ruTorrent|/rutorrent/;qBittorrent|/qbittorrent/"</code></p><?php endif; ?></article>
</div>
</section>
<div class="notice"><span class="notice-icon">i</span><div><strong>Shared-hosting metric scope</strong><span id="scope">Host figures describe the whole shared server. "My slot" counts only your own processes.</span></div></div>
<footer class="footer"><span>Feral Quickbox Dashboard <span aria-hidden="true">·</span> QuickBox Lite style</span><span>Rootless <span aria-hidden="true">·</span> Own-user only <span aria-hidden="true">·</span> Auto refresh</span></footer>
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
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
m.network.interfaces.forEach(function(i){var p=lastIf[i.name],rs=0,ts=0;if(p){var dt=(now-p.t)/1000;if(dt>0&&dt<60){rs=Math.max(0,i.rx-p.rx)/dt;ts=Math.max(0,i.tx-p.tx)/dt}}lastIf[i.name]={rx:i.rx,tx:i.tx,t:now};var r=mk('tr');r.appendChild(mk('td','nm',i.name));[bytes(rs)+'/s',bytes(ts)+'/s',bytes(i.rx),bytes(i.tx)].forEach(function(v){r.appendChild(mk('td','num',v))});tb.appendChild(r)})}
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
el('theme').addEventListener('click',function(){var r=document.documentElement,n=r.getAttribute('data-theme')==='light'?'dark':'light';r.setAttribute('data-theme',n);try{localStorage.setItem('fqd-theme',n)}catch(x){}chart()});
poll();var timer=setInterval(poll,5000);window.addEventListener('resize',chart);window.addEventListener('beforeunload',function(){clearInterval(timer)})
}());
</script>
<?php endif; ?></body></html>
