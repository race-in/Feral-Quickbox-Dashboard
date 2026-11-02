<?php
/**
 * Feral Quickbox Dashboard — rootless backend helpers.
 * Copyright (C) 2026 race-in contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Rules this file lives by (shared server = rootless):
 *  - never sudo, never root, never systemctl, never package managers;
 *  - only processes owned by the SAME uid as the web user are ever listed/signalled;
 *  - no browser-supplied string is ever executed: actions refer to a service id
 *    from the private services.ini allow-list, commands are argv arrays.
 */
declare(strict_types=1);

if (!defined('FQD_BOOTSTRAPPED')) { http_response_code(404); exit; }

/* ------------------------------------------------- host capability helpers */

/** True when the host allows spawning processes. Shared hosts often list proc_open in disable_functions. */
function fqd_can_exec(): bool
{
    return function_exists('proc_open');
}

function fqd_can_signal(): bool
{
    return function_exists('posix_kill') || fqd_can_exec();
}

function fqd_pid(): int
{
    if (function_exists('getmypid')) return (int) getmypid();
    $s = @file_get_contents('/proc/self/stat');
    return is_string($s) ? (int) $s : 0;
}

/** Numeric entries of /proc without depending on glob(). */
function fqd_proc_pids(): array
{
    $ls = function_exists('scandir') ? @scandir('/proc') : false;
    $out = array();
    if (is_array($ls)) foreach ($ls as $n) if ($n !== '' && ctype_digit($n)) $out[] = (int) $n;
    return $out;
}

/** Memory page size in bytes, derived from this process (statm pages vs VmRSS kB) - no exec, no assumption of 4 KiB. */
function fqd_page_size(): int
{
    static $ps = null;
    if ($ps !== null) return $ps;
    $ps = 4096;
    $statm = @file_get_contents('/proc/self/statm');
    $status = @file_get_contents('/proc/self/status');
    if (is_string($statm) && is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $m)) {
        $f = preg_split('/\s+/', trim($statm));
        $pages = isset($f[1]) ? (int) $f[1] : 0;
        if ($pages > 0 && (int) $m[1] > 0) {
            $est = ((int) $m[1] * 1024) / $pages;
            foreach (array(4096, 8192, 16384, 65536) as $c) if ($est >= $c * 0.75 && $est < $c * 1.5) { $ps = $c; break; }
        }
    }
    return $ps;
}

/* ------------------------------------------------------------------ paths */

/** UTF-8 safe substring that works even when the mbstring extension is not installed. */
function fqd_substr(string $s, int $start, int $len): string
{
    if (function_exists('mb_substr')) return mb_substr($s, $start, $len, 'UTF-8');
    if (preg_match_all('/./su', $s, $m)) return implode('', array_slice($m[0], $start, $len));
    return substr($s, $start, $len);
}


function fqd_dir(array $config, string $key, string $xdgEnv, string $xdgDefault): string
{
    if (!empty($config[$key]) && is_string($config[$key])) return rtrim($config[$key], '/');
    $base = getenv($xdgEnv);
    if (!is_string($base) || $base === '') $base = fqd_home($config) . '/' . $xdgDefault;
    return rtrim($base, '/') . '/feral-quickbox';
}

function fqd_home(array $config): string
{
    if (!empty($config['HOME_PATH']) && is_dir((string) $config['HOME_PATH'])) return rtrim((string) $config['HOME_PATH'], '/');
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir'])) return rtrim((string) $pw['dir'], '/');
    }
    return dirname(__DIR__, 2);
}

/** Create a private (0700) directory, refusing symlinks. Returns path or '' on failure. */
function fqd_private_dir(string $dir): string
{
    if ($dir === '' || is_link($dir)) return '';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
    @chmod($dir, 0700);
    return $dir;
}

function fqd_is_root(): bool
{
    return fqd_euid() === 0;
}

function fqd_euid(): int
{
    if (function_exists('posix_geteuid')) return posix_geteuid();
    $o = @fileowner('/proc/self');
    return $o === false ? -1 : (int) $o;
}

/* --------------------------------------------------------- login throttle */

/** Per-IP failed-login counter kept in the user's private cache dir (cannot be bypassed by dropping cookies). */
function fqd_throttle_file(string $cacheDir): string
{
    return $cacheDir . '/login-throttle.json';
}

function fqd_throttle_check(string $cacheDir, string $ip): int
{
    $f = fqd_throttle_file($cacheDir);
    $raw = is_file($f) ? @file_get_contents($f) : false;
    $d = is_string($raw) ? json_decode($raw, true) : null;
    $k = hash('sha256', $ip);
    if (!is_array($d) || !isset($d[$k])) return 0;
    $e = $d[$k];
    if (!is_array($e) || !isset($e['t'], $e['n']) || !is_numeric($e['t']) || !is_numeric($e['n'])) return 0;
    $elapsed = time() - (int) $e['t'];
    if ($elapsed < 0 || $elapsed > 900) return 0;
    return ((int) $e['n'] >= 5) ? max(1, 900 - $elapsed) : 0;
}

function fqd_throttle_record(string $cacheDir, string $ip, bool $ok)
{
    $f = fqd_throttle_file($cacheDir);
    $fh = @fopen($f, 'c+');
    if (!$fh) return;
    @chmod($f, 0600);
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $d = json_decode((string) $raw, true);
        if (!is_array($d)) $d = array();
        $now = time();
        foreach ($d as $key => $e) if ($now - (int) ($e['t'] ?? 0) > 900) unset($d[$key]);
        $k = hash('sha256', $ip);
        if ($ok) unset($d[$k]);
        else $d[$k] = array('n' => (int) ($d[$k]['n'] ?? 0) + 1, 't' => $now);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($d));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/* -------------------------------------------------------- safe proc_open */

/**
 * Start a fixed argv command on both modern PHP and PHP 7.0.
 *
 * PHP added array-form proc_open commands in 7.4. On older runtimes each
 * argument is independently quoted before passing a command string to the
 * platform shell. No browser input is accepted here; callers provide only
 * fixed program names and arguments loaded from trusted local configuration.
 *
 * FQD_FORCE_LEGACY_PROC_OPEN=1 exercises the compatibility path in CI.
 */
function fqd_proc_open_argv(array $argv, array $descriptors, &$pipes, $cwd = null)
{
    if (!$argv) return false;
    if (PHP_VERSION_ID >= 70400 && getenv('FQD_FORCE_LEGACY_PROC_OPEN') !== '1') {
        return @proc_open($argv, $descriptors, $pipes, $cwd);
    }
    if (!function_exists('escapeshellarg')) return false;
    $quoted = array();
    foreach ($argv as $arg) {
        if (!is_scalar($arg)) return false;
        $quoted[] = escapeshellarg((string) $arg);
    }
    return @proc_open(implode(' ', $quoted), $descriptors, $pipes, $cwd);
}

/* ---------------------------------------------------------------- /proc */

/** Clock ticks per second (getconf, cached). USER_HZ is 100 on all mainstream Linux; that is also the fallback when proc_open is disabled. */
function fqd_clk_tck(): int
{
    static $hz = null;
    if ($hz !== null) return $hz;
    $hz = 0;
    if (isset($_SESSION['clk_tck'])) $hz = (int) $_SESSION['clk_tck'];
    if ($hz < 1 && fqd_can_exec()) {
        $p = fqd_proc_open_argv(array('getconf', 'CLK_TCK'), array(array('file', '/dev/null', 'r'), array('pipe', 'w'), array('file', '/dev/null', 'w')), $pipes);
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($p);
            $hz = (int) trim((string) $out);
        }
    }
    if ($hz < 1 || $hz > 10000) $hz = 100;
    $_SESSION['clk_tck'] = $hz;
    return $hz;
}

/**
 * Proportional set size in bytes (shared pages are split between the processes sharing them, so summing
 * several processes does not count shared memory more than once). Falls back to RSS when smaps_rollup is unreadable.
 */
function fqd_proc_pss(string $dir, int $rssFallback): int
{
    $r = @file_get_contents($dir . '/smaps_rollup');
    if (is_string($r) && preg_match('/^Pss:\s+(\d+)\s+kB/m', $r, $m)) return (int) $m[1] * 1024;
    return $rssFallback;
}

/**
 * Processes of the current uid only.
 * @param bool $fresh  bypass the per-request cache (used while waiting for start/stop)
 * @param bool $memory also compute PSS (reads smaps_rollup, a bit costlier)
 * @return array<int,array{comm:string,argv:array<int,string>,rss:int,pss:int,ticks:int,start:int}>
 */
function fqd_own_processes(bool $fresh = false, bool $memory = true): array
{
    static $cache = null;
    if (!$fresh && $memory && $cache !== null) return $cache;
    $out = array();
    $uid = fqd_euid();
    $page = fqd_page_size();
    $selfPid = fqd_pid();
    foreach (fqd_proc_pids() as $pid) {
        $d = '/proc/' . $pid;
        if ($pid === $selfPid || @fileowner($d) !== $uid) continue;
        $p = fqd_read_proc($d, $page, $memory);
        if ($p !== null) $out[$pid] = $p;
    }
    if (!$fresh && $memory) $cache = $out;
    return $out;
}

/** @return array{comm:string,argv:array<int,string>,rss:int,pss:int,ticks:int,start:int}|null */
function fqd_read_proc(string $d, int $page, bool $memory)
{
    $stat = @file_get_contents($d . '/stat');
    if (!is_string($stat)) return null;
    $rp = strrpos($stat, ')');
    $lp = strpos($stat, '(');
    if ($rp === false || $lp === false) return null;
    $f = preg_split('/\s+/', trim(substr($stat, $rp + 2)));
    if (!is_array($f) || count($f) < 22 || $f[0] === 'Z') return null; // zombies are not "running"
    $cmd = @file_get_contents($d . '/cmdline');
    $rss = max(0, (int) $f[21]) * $page;
    return array(
        'comm' => substr($stat, $lp + 1, $rp - $lp - 1),
        'argv' => is_string($cmd) && $cmd !== '' ? explode("\0", rtrim($cmd, "\0")) : array(),
        'rss' => $rss,
        'pss' => $memory ? fqd_proc_pss($d, $rss) : $rss,
        'ticks' => (int) $f[11] + (int) $f[12],
        'start' => (int) $f[19],
    );
}

function fqd_proc_matches(array $p, string $match): bool
{
    $m = strtolower(trim($match));
    if ($m === '' || strpos($m, '/') !== false || strpos($m, "\0") !== false) return false;

    // Linux comm is truncated to 15 bytes. Never treat a shared 15-byte prefix
    // as an identity: that can make Stop target an unrelated process.
    $comm = strtolower((string) ($p['comm'] ?? ''));
    if (strlen($m) <= 15 && $comm === $m) return true;

    // argv[0] is the executable identity. Do not scan arbitrary arguments:
    // a process must not match merely because its command line mentions a name.
    $argv0 = isset($p['argv'][0]) ? strtolower(basename((string) $p['argv'][0])) : '';
    if ($argv0 !== '' && $argv0 === $m) return true;

    // For names longer than Linux TASK_COMM_LEN, require an exact argv[0]
    // basename match; the truncated comm field alone is ambiguous.
    return false;
}

/* --------------------------------------------------------------- services */

/** Parse a command string into an argv array. No shell, no expansion; supports "double" and 'single' quotes. */
function fqd_split_args(string $s): array
{
    $out = array();
    if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            if (isset($x[3]) && $x[3] !== '') $out[] = $x[3];
            elseif (isset($x[2]) && $x[2] !== '') $out[] = $x[2];
            elseif (isset($x[1])) $out[] = stripslashes($x[1]);
        }
    }
    return $out;
}

function fqd_expand_home(string $s, string $home): string
{
    if ($s === '~') return $home;
    if (strncmp($s, '~/', 2) === 0) return $home . substr($s, 1);
    return str_replace('$HOME', $home, $s);
}

/**
 * Load the private service allow-list (services.ini next to config.env).
 * @return array<string,array{id:string,label:string,match:string,start:array<int,string>,stop:array<int,string>,url:string}>
 */
function fqd_services_config(string $configFile, string $home): array
{
    $file = dirname($configFile) . '/services.ini';
    $out = array();
    if (!is_file($file) || is_link($file) || !is_readable($file)) return $out;
    $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
    if (!is_array($ini)) return $out;
    foreach ($ini as $id => $s) {
        if (!is_array($s) || !preg_match('/^[a-z0-9_-]{1,32}$/', (string) $id)) continue;
        $strip = static function ($v) { return trim((string) $v, " \t\"'"); };
        $match = $strip($s['match'] ?? $id);
        if ($match === '') continue;
        $start = isset($s['start']) ? fqd_split_args(fqd_expand_home($strip($s['start']), $home)) : array();
        $stop = isset($s['stop']) ? fqd_split_args(fqd_expand_home($strip($s['stop']), $home)) : array();
        $url = $strip($s['url'] ?? '');
        if ($url !== '' && !preg_match('#^(/|https://)[^\s<>"\']*$#', $url)) $url = '';
        $out[(string) $id] = array(
            'id' => (string) $id,
            'label' => fqd_substr($strip($s['label'] ?? $id), 0, 40),
            'match' => $match,
            'start' => $start,
            'stop' => $stop,
            'url' => $url,
        );
    }
    return $out;
}

/** Well-known user-space daemons shown (read-only) when they happen to be running and not configured. */
function fqd_autodetect_names(): array
{
    return array(
        'rtorrent' => 'rTorrent', 'qbittorrent-nox' => 'qBittorrent', 'deluged' => 'Deluge daemon',
        'deluge-web' => 'Deluge web', 'transmission-daemon' => 'Transmission', 'sonarr' => 'Sonarr',
        'radarr' => 'Radarr', 'lidarr' => 'Lidarr', 'prowlarr' => 'Prowlarr', 'jackett' => 'Jackett',
        'bazarr' => 'Bazarr', 'plexmediaserver' => 'Plex', 'jellyfin' => 'Jellyfin', 'syncthing' => 'Syncthing',
        'autodl-irssi' => 'autodl-irssi', 'irssi' => 'irssi', 'flexget' => 'FlexGet',
    );
}

function fqd_services_status(string $configFile, string $home): array
{
    $procs = fqd_own_processes();
    $cfg = fqd_services_config($configFile, $home);
    $rows = array();
    $seen = array();
    $hz = fqd_clk_tck();
    $uptime = (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');
    $build = static function (string $id, string $label, string $match, bool $configured, array $svc) use ($procs, $hz, $uptime) {
        $pids = array(); $rss = 0; $oldest = null;
        foreach ($procs as $pid => $p) {
            if (fqd_proc_matches($p, $match)) {
                $pids[] = $pid; $rss += $p['pss'];
                $age = $uptime - $p['start'] / $hz;
                if ($oldest === null || $age > $oldest) $oldest = $age;
            }
        }
        return array(
            'id' => $id, 'label' => $label, 'running' => count($pids) > 0, 'pids' => array_slice($pids, 0, 8),
            'count' => count($pids), 'rss' => $rss, 'age' => $oldest === null ? null : (int) $oldest,
            'configured' => $configured,
            'can_start' => $configured && count($svc['start'] ?? array()) > 0 && fqd_can_exec(),
            'can_stop' => $configured && (empty($svc['stop']) ? fqd_can_signal() : fqd_can_exec()),
            'url' => $svc['url'] ?? '',
        );
    };
    foreach ($cfg as $id => $svc) {
        $rows[] = $build($id, $svc['label'], $svc['match'], true, $svc);
        $seen[strtolower($svc['match'])] = true;
    }
    foreach (fqd_autodetect_names() as $name => $label) {
        if (isset($seen[$name])) continue;
        $r = $build('auto:' . $name, $label, $name, false, array());
        if ($r['running']) $rows[] = $r;
    }
    return $rows;
}

/**
 * Signal a process only if it is STILL the one we matched: same uid, same start time (defeats PID reuse),
 * and it still matches the service. The residual window between this check and kill() is microseconds.
 */
function fqd_signal_verified(int $pid, int $startTicks, string $match, string $sig = 'TERM'): bool
{
    if ($pid <= 1 || $pid === fqd_pid()) return false;
    $d = '/proc/' . $pid;
    if (@fileowner($d) !== fqd_euid()) return false;
    $p = fqd_read_proc($d, fqd_page_size(), false);
    if ($p === null || $p['start'] !== $startTicks || !fqd_proc_matches($p, $match)) return false;
    $num = $sig === 'KILL' ? 9 : 15;
    if (function_exists('posix_kill')) return @posix_kill($pid, $num);
    if (!fqd_can_exec()) return false;
    $q = fqd_proc_open_argv(array('kill', '-' . $sig, (string) $pid), array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes);
    return is_resource($q) ? proc_close($q) === 0 : false;
}

/** Spawn a detached user-space command. argv is passed positionally; nothing is parsed by a shell. */
function fqd_spawn(array $argv, string $cwd): bool
{
    if (!$argv || !fqd_can_exec()) return false;
    $script = 'if command -v setsid >/dev/null 2>&1; then setsid nohup "$@" >/dev/null 2>&1 </dev/null & else nohup "$@" >/dev/null 2>&1 </dev/null & fi';
    $cmd = array_merge(array('/bin/sh', '-c', $script, 'fqd-start'), $argv);
    $p = fqd_proc_open_argv($cmd, array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes, $cwd);
    if (!is_resource($p)) return false;
    return proc_close($p) === 0;
}

/**
 * @return array{ok:bool,message:string}
 */
function fqd_service_action(string $configFile, string $home, string $id, string $op): array
{
    if (fqd_is_root()) return array('ok' => false, 'message' => 'Refusing to run as root.');
    $cfg = fqd_services_config($configFile, $home);
    if (!isset($cfg[$id])) return array('ok' => false, 'message' => 'Unknown service.');
    if (!in_array($op, array('start', 'stop', 'restart'), true)) return array('ok' => false, 'message' => 'Unknown action.');
    $svc = $cfg[$id];
    $noExec = 'Not available: this host disables proc_open (shared-hosting policy), so the dashboard cannot start or stop processes. Status monitoring still works.';
    if (!fqd_can_exec() && ($op !== 'stop' || $svc['stop'] || !fqd_can_signal())) return array('ok' => false, 'message' => $noExec);
    $running = static function () use ($svc) {
        $pids = array();
        foreach (fqd_own_processes(true, false) as $pid => $p) if (fqd_proc_matches($p, $svc['match'])) $pids[$pid] = $p['start'];
        return $pids;
    };
    if ($op === 'stop' || $op === 'restart') {
        $pids = $running();
        if ($svc['stop']) {
            if (!fqd_spawn($svc['stop'], $home)) return array('ok' => false, 'message' => 'Stop command failed to launch.');
        } else {
            foreach ($pids as $pid => $start) fqd_signal_verified($pid, $start, $svc['match'], 'TERM');
        }
        for ($i = 0; $i < 25 && $running(); $i++) usleep(200000);
        if ($running()) {
            if ($op === 'stop') return array('ok' => false, 'message' => $svc['label'] . ' is still shutting down (SIGTERM sent).');
            return array('ok' => false, 'message' => $svc['label'] . ' did not stop in time; not starting a second copy.');
        }
        if ($op === 'stop') return array('ok' => true, 'message' => $svc['label'] . ' stopped.');
    }
    if ($op === 'start' || $op === 'restart') {
        if ($op === 'start' && $running()) return array('ok' => true, 'message' => $svc['label'] . ' is already running.');
        if (!$svc['start']) return array('ok' => false, 'message' => 'No start command configured in services.ini.');
        if (!fqd_spawn($svc['start'], $home)) return array('ok' => false, 'message' => 'Start command failed to launch.');
        for ($i = 0; $i < 15 && !$running(); $i++) usleep(200000);
        return $running()
            ? array('ok' => true, 'message' => $svc['label'] . ($op === 'restart' ? ' restarted.' : ' started.'))
            : array('ok' => false, 'message' => $svc['label'] . ' was launched but is not visible yet. Check again in a moment.');
    }
    return array('ok' => false, 'message' => 'Nothing to do.');
}

/* ----------------------------------------------------------------- quota */

/**
 * Exec-free disk usage: walks HOME (never following symlinks), counting allocated blocks like du does,
 * for at most $budget seconds per call and resumes where it stopped on the next call.
 * Writes du.kb when the walk completes. Returns true when finished.
 */
function fqd_quota_scan_step(string $home, string $cacheDir, float $budget): bool
{
    $stateFile = $cacheDir . '/du.state';
    $fh = @fopen($stateFile, 'c+');
    if (!$fh) return false;
    @chmod($stateFile, 0600);
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; } // another request is scanning
    $st = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($st) || !isset($st['stack'], $st['bytes'])) $st = array('stack' => array($home), 'bytes' => 0);
    $t0 = microtime(true); $done = false;
    while (true) {
        if (!$st['stack']) { $done = true; break; }
        $dir = array_pop($st['stack']);
        $ls = @lstat($dir);
        if (is_array($ls)) $st['bytes'] += (int) $ls['blocks'] * 512;
        $dh = @opendir($dir);
        if (!$dh) continue;
        while (($e = readdir($dh)) !== false) {
            if ($e === '.' || $e === '..') continue;
            $path = $dir . '/' . $e;
            $l = @lstat($path);
            if (!is_array($l)) continue;
            if (($l['mode'] & 0170000) === 0040000) { $st['stack'][] = $path; continue; }
            $st['bytes'] += (int) $l['blocks'] * 512;
        }
        closedir($dh);
        if (microtime(true) - $t0 > $budget) break; // budget is only checked between directories, so no entry is counted twice
    }
    if ($done) {
        $tmp = $cacheDir . '/du.kb.tmp';
        if (@file_put_contents($tmp, (string) (int) ceil($st['bytes'] / 1024)) !== false) @rename($tmp, $cacheDir . '/du.kb');
        ftruncate($fh, 0); flock($fh, LOCK_UN); fclose($fh); @unlink($stateFile);
        return true;
    }
    // Do not truncate the traversal stack: dropping queued directories silently
    // under-reports account usage on large homes.
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($st)); fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return false;
}

/**
 * Optional account quota: QUOTA_GB in config.env. Usage is measured by a throttled background `du`
 * whose result is cached; the web request itself never blocks on it.
 * @return array{total:int,used:int,percent:float,stale:bool}|null
 */
function fqd_quota(array $config, string $home, string $cacheDir)
{
    $gb = isset($config['QUOTA_GB']) ? (float) $config['QUOTA_GB'] : 0.0;
    if ($gb <= 0 || $cacheDir === '') return null;
    $res = $cacheDir . '/du.kb';
    $lock = $cacheDir . '/du.lock';
    $every = max(120, (int) ($config['QUOTA_REFRESH'] ?? 900));
    $age = is_file($res) ? time() - (int) @filemtime($res) : PHP_INT_MAX;
    $scanning = false;
    $inProgress = is_file($cacheDir . '/du.state');
    if (($age > $every || $inProgress) && !(fqd_can_exec() && is_file($lock) && time() - (int) @filemtime($lock) <= 900)) {
        $launched = false;
        if (fqd_can_exec() && !$inProgress) {
            @touch($lock);
            // One shell level; home/result/lock are positional parameters ($1,$2,$3), never interpolated into the script.
            $script = '( nice -n 19 du -sk -- "$1" >"$2.tmp" 2>/dev/null; [ -s "$2.tmp" ] && mv -f -- "$2.tmp" "$2"; rm -f -- "$2.tmp" "$3" ) >/dev/null 2>&1 &';
            $p = fqd_proc_open_argv(array('/bin/sh', '-c', $script, 'fqd-du', $home, $res, $lock),
                array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes);
            if (is_resource($p)) { proc_close($p); $launched = true; }
        }
        if (!$launched) $scanning = !fqd_quota_scan_step($home, $cacheDir, 1.0); // pure-PHP fallback, resumable, time-boxed
    }
    $kb = is_file($res) ? (int) trim((string) @file_get_contents($res)) : null;
    if ($kb === null) return array('total' => (int) ($gb * 1073741824), 'used' => 0, 'percent' => 0.0, 'stale' => true, 'scanning' => true);
    $total = (int) ($gb * 1073741824);
    $used = $kb * 1024;
    return array('total' => $total, 'used' => $used, 'percent' => round(min(999, 100 * $used / max(1, $total)), 1), 'stale' => $age > $every * 3 || $scanning, 'scanning' => $scanning);
}

/* ---------------------------------------------------------------- metrics */

function fqd_metrics(array $config, string $configFile): array
{
    $home = fqd_home($config);
    $cacheDir = fqd_dir($config, 'CACHE_PATH', 'XDG_CACHE_HOME', '.cache');

    // Host CPU (from /proc/stat, delta between polls of this session)
    $cpu = null;
    $stat = @file('/proc/stat');
    if (is_array($stat) && isset($stat[0]) && strpos($stat[0], 'cpu ') === 0) {
        $v = preg_split('/\s+/', trim($stat[0]));
        array_shift($v);
        $v = array_map('intval', array_slice($v, 0, 8));
        $now = array('total' => array_sum($v), 'idle' => $v[3] + ($v[4] ?? 0));
        if (isset($_SESSION['cpu']) && $now['total'] > $_SESSION['cpu']['total']) {
            $dt = $now['total'] - $_SESSION['cpu']['total'];
            $di = $now['idle'] - $_SESSION['cpu']['idle'];
            if ($di >= 0) $cpu = round(max(0, min(100, (1 - $di / $dt) * 100)), 1);
        }
        $_SESSION['cpu'] = $now;
    }
    $cores = 0;
    foreach ((array) @file('/proc/cpuinfo') as $l) if (strpos((string) $l, 'processor') === 0) $cores++;
    if ($cores < 1) $cores = 1;

    // Host memory
    $mem = null;
    $m = array();
    foreach ((array) @file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^(MemTotal|MemAvailable|MemFree|Buffers|Cached):\s+(\d+)/', (string) $line, $x)) $m[$x[1]] = (int) $x[2] * 1024;
    }
    if (!empty($m['MemTotal'])) {
        $avail = $m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0));
        $used = max(0, $m['MemTotal'] - $avail);
        $mem = array('total' => $m['MemTotal'], 'used' => $used, 'percent' => round(100 * $used / $m['MemTotal'], 1));
    }

    // Home filesystem
    $disk = null;
    $total = function_exists('disk_total_space') ? @disk_total_space($home) : false;
    $free = function_exists('disk_free_space') ? @disk_free_space($home) : false;
    if ($total && $free !== false) $disk = array('total' => (int) $total, 'used' => (int) ($total - $free), 'percent' => round(100 * ($total - $free) / $total, 1));

    // Network (per interface)
    $ifaces = array();
    foreach (array_slice((array) @file('/proc/net/dev', FILE_IGNORE_NEW_LINES), 2) as $line) {
        if (strpos((string) $line, ':') === false) continue;
        list($name, $values) = explode(':', (string) $line, 2);
        $name = trim($name);
        if ($name === '' || $name === 'lo') continue;
        $n = preg_split('/\s+/', trim($values));
        if (!is_array($n) || count($n) < 9) continue;
        $ifaces[] = array('name' => $name, 'rx' => (int) $n[0], 'tx' => (int) $n[8]);
    }
    $net = null;
    if ($ifaces) {
        $net = array('rx' => array_sum(array_column($ifaces, 'rx')), 'tx' => array_sum(array_column($ifaces, 'tx')), 'interfaces' => $ifaces);
    }

    $up = @file_get_contents('/proc/uptime');
    $uptime = $up !== false ? (int) floor((float) strtok($up, ' ')) : null;
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    if (!is_array($load)) {
        $la = @file_get_contents('/proc/loadavg');
        $load = is_string($la) && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $la, $mm) ? array((float) $mm[1], (float) $mm[2], (float) $mm[3]) : false;
    }

    // Slot-only view: processes owned by this uid
    $procs = fqd_own_processes();
    $rss = 0; $ticks = 0;
    foreach ($procs as $p) { $rss += $p['pss']; $ticks += $p['ticks']; }
    $slotCpu = null;
    $nowT = microtime(true);
    if (isset($_SESSION['slot']) && $nowT - $_SESSION['slot']['t'] > 0.5) {
        $dTicks = $ticks - $_SESSION['slot']['ticks'];
        if ($dTicks >= 0) $slotCpu = round(100 * ($dTicks / fqd_clk_tck()) / ($nowT - $_SESSION['slot']['t']), 1);
    }
    $_SESSION['slot'] = array('t' => $nowT, 'ticks' => $ticks);
    $slot = array(
        'processes' => count($procs), 'rss' => $rss,
        'cpu' => $slotCpu, 'cores' => $cores,
        'mem_percent' => $mem ? round(100 * $rss / $mem['total'], 1) : null,
    );

    return array(
        'time' => time(), 'cpu' => $cpu, 'cores' => $cores, 'memory' => $mem, 'disk' => $disk,
        'quota' => fqd_quota($config, $home, $cacheDir),
        'network' => $net, 'uptime' => $uptime, 'load' => is_array($load) ? $load : null,
        'slot' => $slot,
        'capabilities' => array('exec' => fqd_can_exec(), 'signal' => fqd_can_signal(), 'procfs' => is_readable('/proc/self/stat')),
        'services' => fqd_services_status($configFile, $home),
        'user' => function_exists('posix_getpwuid') ? (@posix_getpwuid(fqd_euid())['name'] ?? '') : '',
        'scope' => 'Host figures (CPU, memory, network, load, uptime) describe the whole shared server. "My slot" counts only processes owned by your own user. Disk shows filesystem capacity unless a quota is configured.',
    );
}

/* ------------------------------------------------------------- quick apps */

/** Quick links from config.env: APPS="ruTorrent|/rutorrent/;qBittorrent|/qbittorrent/" */
function fqd_apps(array $config): array
{
    $out = array();
    $raw = (string) ($config['APPS'] ?? '');
    foreach (explode(';', trim($raw, " \t\"'")) as $item) {
        $parts = explode('|', $item, 2);
        if (count($parts) !== 2) continue;
        $label = trim($parts[0]); $url = trim($parts[1]);
        if ($label === '' || !preg_match('#^(/|https://)[^\s<>"\']*$#', $url)) continue;
        $out[] = array('label' => fqd_substr($label, 0, 30), 'url' => $url);
        if (count($out) >= 16) break;
    }
    return $out;
}
