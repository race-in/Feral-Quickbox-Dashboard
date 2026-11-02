#!/usr/bin/env bash
# Runs the PHP smoke test under typical shared-hosting restrictions. Run as a non-root user.
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
echo '== PHP 7.0-compatible escaped proc_open argv fallback =='
FQD_FORCE_LEGACY_PROC_OPEN=1 bash tests/php-smoke.sh
echo '== legacy fallback when posix_kill is unavailable =='
FQD_FORCE_LEGACY_PROC_OPEN=1 FQD_PHP_ARGS='-d disable_functions=posix_kill' bash tests/php-smoke.sh
echo '== proc_open/exec family disabled =='
FQD_DEGRADED=1 FQD_PHP_ARGS='-d disable_functions=proc_open,popen,exec,shell_exec,system,passthru' bash tests/php-smoke.sh
echo '== posix_* and getmypid/glob disabled as well =='
FQD_DEGRADED=1 FQD_PHP_ARGS='-d disable_functions=proc_open,popen,exec,shell_exec,system,passthru,posix_kill,posix_geteuid,posix_getpwuid,getmypid,glob,sys_getloadavg,disk_free_space,disk_total_space' bash tests/php-smoke.sh
echo 'Degraded-host smoke tests passed.'
