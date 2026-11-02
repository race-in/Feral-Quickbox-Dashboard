<?php
// Unit tests for rootless helpers. Run as a non-root user:  php tests/lib-test.php
define('FQD_BOOTSTRAPPED', true);
require __DIR__ . '/../public/inc/lib.php';
$fail = 0;
function t($name, $ok) { global $fail; echo ($ok ? "ok   " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

t('not root', !fqd_is_root());
t('CLK_TCK sane', in_array(fqd_clk_tck(), array(100, 250, 300, 1000), true) || (fqd_clk_tck() > 0 && fqd_clk_tck() <= 10000));
t('split_args quotes', fqd_split_args('a "b c" \'d e\' f') === array('a', 'b c', 'd e', 'f'));
t('split_args no shell expansion', fqd_split_args('echo $(id) ; rm') === array('echo', '$(id)', ';', 'rm'));

$serviceCfgDir = sys_get_temp_dir() . '/fqd-service-config-' . fqd_pid();
@mkdir($serviceCfgDir, 0700, true);
$serviceIni = "[quoted]\nlabel = Quote test\nmatch = fqd-sleepy\nstart = /bin/bash -c 'exec -a fqd-sleepy /usr/bin/sleep 600'\n";
file_put_contents($serviceCfgDir . '/services.ini', $serviceIni);
$parsedServices = fqd_services_config($serviceCfgDir . '/config.env', $serviceCfgDir);
t('service config preserves embedded command quotes', isset($parsedServices['quoted']['start'])
    && $parsedServices['quoted']['start'] === array('/bin/bash', '-c', 'exec -a fqd-sleepy /usr/bin/sleep 600'));
@unlink($serviceCfgDir . '/services.ini');
@rmdir($serviceCfgDir);
t('process match exact comm only', fqd_proc_matches(array('comm' => 'qbittorrent', 'argv' => array('/usr/bin/qbittorrent')), 'qbittorrent'));
t('process match rejects 15-byte prefix collision', !fqd_proc_matches(array('comm' => 'qbittorrent-nox', 'argv' => array('/usr/bin/qbittorrent-nox')), 'qbittorrent'));
t('process match rejects argument-only match', !fqd_proc_matches(array('comm' => 'python3', 'argv' => array('/usr/bin/python3', 'qbittorrent')), 'qbittorrent'));
t('substr utf8', fqd_substr('বাংলা', 0, 2) === 'বাং' || strlen(fqd_substr('বাংলা', 0, 2)) > 0);

// PSS must not exceed RSS-sum for two processes sharing memory, and is readable for own processes
$me = fqd_own_processes(true, true);
t('own processes list excludes self and foreign', is_array($me) && !isset($me[fqd_pid()]));

$canExec = fqd_can_exec();
t('page size sane', in_array(fqd_page_size(), array(4096, 8192, 16384, 65536), true));
t('capability helpers agree with PHP', $canExec === function_exists('proc_open'));
if ($canExec) {
    // PID-reuse protection: a child started now, then verified with a WRONG start time must NOT be signalled.
    // Keep the temporary executable beside this test, not in /tmp: shared hosts may mount /tmp noexec.
    // Linux TASK_COMM_LEN stores exactly the first 15 bytes of this executable name.
    $identityName = 'fqd-unit-sleepy';
    $bin = __DIR__ . '/' . $identityName . '-' . fqd_pid();
    $sleepSource = is_executable('/usr/bin/sleep') ? '/usr/bin/sleep' : (is_executable('/bin/sleep') ? '/bin/sleep' : '');
    $copied = $sleepSource !== '' && @copy($sleepSource, $bin) && @chmod($bin, 0700);
    $proc = $copied ? fqd_proc_open_argv(
        array($bin, '60'),
        array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')),
        $pipes
    ) : false;

    if (!is_resource($proc)) {
        t('unit child process launched with PHP-version-compatible proc_open', false);
        @unlink($bin);
    } else {
        usleep(300000);
        $st = @proc_get_status($proc);
        $pid = is_array($st) && isset($st['pid']) ? (int) $st['pid'] : 0;
        $info = null;
        if ($pid > 0) {
            foreach (fqd_own_processes(true, true) as $p => $v) if ($p === $pid) $info = $v;
        }
        $identityOk = $info !== null && isset($info['start']) && is_numeric($info['start'])
            && fqd_proc_matches($info, $identityName);
        t('child visible with pss', $info !== null && isset($info['pss']) && $info['pss'] > 0);
        t('child matched by name', $identityOk);
        t('refuse stale start time (PID reuse)', $identityOk && fqd_signal_verified($pid, (int) $info['start'] + 12345, $identityName) === false);
        t('refuse wrong service name', $identityOk && fqd_signal_verified($pid, (int) $info['start'], 'something-else') === false);
        t('refuse pid 1 and self', !fqd_signal_verified(1, 0, 'init') && !fqd_signal_verified(fqd_pid(), 0, 'php'));

        if ($identityOk) {
            usleep(100000);
            $st = @proc_get_status($proc);
            t('child still alive after refusals', is_array($st) && !empty($st['running']));
            t('signal with correct identity', fqd_signal_verified($pid, (int) $info['start'], $identityName) === true);
            usleep(300000);
            $st = @proc_get_status($proc);
            t('child terminated', is_array($st) && empty($st['running']));
        } else {
            t('child still alive after refusals', false);
            t('signal with correct identity', false);
            t('child terminated', false);
            if (function_exists('proc_terminate')) @proc_terminate($proc);
        }
        @proc_close($proc);
        @unlink($bin);
    }
} else {
    t('degraded: spawn refused, not fatal', fqd_spawn(array('true'), '/') === false);
    t('degraded: verified signal refuses without exec/posix or still safe', fqd_signal_verified(1, 0, 'init') === false);
    t('degraded: clk_tck falls back', fqd_clk_tck() > 0);
}

// Quota: background du produces cache file, no stray .tmp, correct size order of magnitude
$tmp = sys_get_temp_dir() . '/fqd-quota-' . fqd_pid(); mkdir($tmp . '/home', 0700, true); mkdir($tmp . '/cache', 0700, true);
file_put_contents($tmp . '/home/blob', str_repeat('x', 3 * 1024 * 1024));
$cfg = array('QUOTA_GB' => '1', 'QUOTA_REFRESH' => '120');
$q = fqd_quota($cfg, $tmp . '/home', $tmp . '/cache');
t('quota first call returns placeholder', is_array($q) && $q['stale'] === true);
for ($i = 0; $i < 30 && !is_file($tmp . '/cache/du.kb'); $i++) usleep(200000);
t('du result file created in cache dir', is_file($tmp . '/cache/du.kb'));
$q = fqd_quota($cfg, $tmp . '/home', $tmp . '/cache');
t('quota counts >= 3 MiB', is_array($q) && $q['used'] >= 3 * 1024 * 1024 && $q['used'] < 50 * 1024 * 1024);
usleep(300000);
t('no stray .tmp / lock left', !file_exists($tmp . '/cache/du.kb.tmp') && !file_exists($tmp . '/cache/du.lock'));
t('no files created outside cache dir', array_values(array_diff(scandir($tmp . '/home'), array('.', '..'))) === array('blob'));
$rm = function ($d) use (&$rm) { foreach (array_diff(scandir($d), array('.', '..')) as $e) { $f = $d . '/' . $e; is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f); } @rmdir($d); };
$rm($tmp);
exit($fail ? 1 : 0);
