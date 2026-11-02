#!/usr/bin/env bash
# PHP auth, subdirectory and rootless service-control smoke test
set -Eeuo pipefail
[[ ${EUID:-$(id -u)} -ne 0 ]] || { echo 'ERROR: run tests as a non-root user (the app refuses root by design).' >&2; exit 1; }
tmp="$(mktemp -d)"; pid=""; tail_bin=""
cleanup(){
  status=$?
  if (( status != 0 )); then
    echo "ERROR: PHP smoke test failed; diagnostics from $tmp follow:" >&2
    if [[ -f "$tmp/svc.out" ]]; then echo "--- service response ---" >&2; cat "$tmp/svc.out" >&2; echo >&2; fi
    if [[ -f "$tmp/server.log" ]]; then echo "--- PHP server log ---" >&2; tail -n 80 "$tmp/server.log" >&2; fi
    if [[ -f "$(dirname "$cfg")/services.ini" ]]; then echo "--- private services.ini ---" >&2; cat "$(dirname "$cfg")/services.ini" >&2; fi
    echo "--- parsed service argv ---" >&2
    # shellcheck disable=SC2016
    php -r 'define("FQD_BOOTSTRAPPED",true); require $argv[1]; $s=fqd_services_config($argv[2],$argv[3]); var_export($s["sleepy"]["start"] ?? array()); echo "\\n";' "$PWD/public/inc/lib.php" "$cfg" "$tmp" >&2
    echo "--- config-path marker ---" >&2
    [[ ! -f "$tmp/spawn.config" ]] || cat "$tmp/spawn.config" >&2
    echo "--- PHP spawn environment marker ---" >&2
    [[ ! -f "$tmp/spawn.php" ]] || cat "$tmp/spawn.php" >&2
    echo "--- spawn launch log ---" >&2
    [[ ! -f "$tmp/spawn.launch" ]] || cat "$tmp/spawn.launch" >&2
    echo "--- spawn stderr ---" >&2
    [[ ! -f "$tmp/spawn.stderr" ]] || cat "$tmp/spawn.stderr" >&2
    echo "--- detached PID ---" >&2
    if [[ -f "$tmp/spawn.pid" ]]; then p="$(cat "$tmp/spawn.pid")"; ps -p "$p" -o pid,ppid,stat,args >&2 || true; fi
    echo "--- matching process snapshot ---" >&2
    pgrep -afu "$(id -u)" '(^|/)(tail)([[:space:]]|$)' >&2 || true
  fi
  [[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true
  [[ -z "$tail_bin" ]] || pkill -u "$(id -u)" -f "^$tail_bin -f /dev/null$" 2>/dev/null || true
  if (( status == 0 )); then rm -rf "$tmp"; else echo "DEBUG files retained at: $tmp" >&2; fi
}
trap cleanup EXIT
web="$tmp/www"; app="$web/Feral-Quickbox-Dashboard"; cfg="$tmp/private/config.env"
tail_bin="$(command -v tail)"
[[ -x "$tail_bin" ]] || { echo 'ERROR: tail is required for the service smoke test.' >&2; exit 1; }
if pgrep -u "$(id -u)" -x tail >/dev/null 2>&1; then
  echo 'ERROR: a tail process already belongs to this account; refusing to signal an unrelated process.' >&2
  exit 1
fi
mkdir -p "$app" "$(dirname "$cfg")" "$tmp/cache" "$tmp/data"
cp -r public/. "$app/"
# shellcheck disable=SC2016
hash="$(printf '%s' 'SmokeTestPassword123!' | php -r '$p=stream_get_contents(STDIN); echo password_hash($p,PASSWORD_DEFAULT);')"
printf 'PASSWORD_HASH="%s"\nHOME_PATH=%s\nDATA_PATH=%s\nCACHE_PATH=%s\nAPPS="ruTorrent|/rutorrent/;Bad|javascript:alert(1)"\n' "$hash" "$tmp" "$tmp/data" "$tmp/cache" >"$cfg"
cat >"$(dirname "$cfg")/services.ini" <<INI
[sleepy]
label = Sleepy test
match = tail
start = $tail_bin -f /dev/null
[nostart]
label = No start
match = fqd-nothing-here
INI
chmod 700 "$(dirname "$cfg")"; chmod 600 "$cfg"
# shellcheck disable=SC2016
php -r 'echo "<?php\nif (!defined(\x27FQD_BOOTSTRAPPED\x27)) { http_response_code(404); exit; }\nputenv(\x27FQD_SPAWN_DEBUG=\x27 . " . var_export($argv[2], true) . ");\n@file_put_contents(" . var_export($argv[2] . ".config", true) . ", \x27config-path-loaded\\n\x27);\nreturn " . var_export($argv[1], true) . ";\n";' "$cfg" "$tmp/spawn" >"$app/config-path.php"
# shellcheck disable=SC2016
port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url("tcp://".stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
# shellcheck disable=SC2086
php ${FQD_PHP_ARGS:-} -S "127.0.0.1:$port" -t "$web" >"$tmp/server.log" 2>&1 & pid=$!
url="http://127.0.0.1:$port/Feral-Quickbox-Dashboard/"; ready=false
attempt=0
while ((attempt < 40)); do
  attempt=$((attempt + 1))
  if curl -fsS -c "$tmp/cookies" "$url" -o "$tmp/login" 2>/dev/null; then ready=true; break; fi
  sleep 0.25
done
[[ "$ready" == true ]]; grep -q 'Welcome back' "$tmp/login"
csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login" | head -1)"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]]
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url?action=metrics")" == 401 ]]
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$url?action=service")" == 401 ]]
curl -sS -D "$tmp/headers" -o /dev/null -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'password=SmokeTestPassword123!' "$url"
grep -q 303 "$tmp/headers"
curl -fsS -b "$tmp/cookies" "$url" -o "$tmp/dashboard"
grep -q 'SERVER CONTROL PANEL' "$tmp/dashboard"
grep -q 'network-chart' "$tmp/dashboard"
grep -q 'BANDWIDTH DATA' "$tmp/dashboard"
grep -q 'PACKAGE MANAGEMENT CENTER' "$tmp/dashboard"
grep -q 'ROOTLESS / FERAL' "$tmp/dashboard"
grep -q 'function poll' "$tmp/dashboard"
grep -q 'SERVICE CONTROL CENTER' "$tmp/dashboard"
grep -q 'href="/rutorrent/"' "$tmp/dashboard"
if grep -q 'javascript:' "$tmp/dashboard"; then echo 'ERROR: unsafe javascript URL rendered' >&2; exit 1; fi
csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/dashboard" | head -1)"
metrics(){ curl -fsS -b "$tmp/cookies" "$url?action=metrics"; }
# shellcheck disable=SC2016
metrics | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["ok"])||!array_key_exists("cpu",$d["metrics"])||!array_key_exists("slot",$d["metrics"])||!is_array($d["metrics"]["services"])) exit(1);'
# service control: CSRF required, unknown ids / ops rejected, start/stop work, only allow-listed names
svc(){ curl --max-time 10 -sS -o "$tmp/svc.out" -w '%{http_code}' -b "$tmp/cookies" -X POST -H 'Content-Type: application/json' "${@:2}" --data "$1" "$url?action=service"; }
[[ "$(svc '{"id":"sleepy","op":"start"}')" == 400 ]]
[[ "$(svc '{"id":"nope","op":"start"}' -H "X-CSRF-Token: $csrf")" == 422 ]]
[[ "$(svc '{"id":"sleepy","op":"rm -rf /"}' -H "X-CSRF-Token: $csrf")" == 422 ]]
[[ "$(svc '{"id":"nostart","op":"start"}' -H "X-CSRF-Token: $csrf")" == 422 ]]
if [[ "${FQD_DEGRADED:-0}" == 1 ]]; then
  # proc_open disabled: dashboard must stay up, report exec=false, refuse start/stop with a clear message
  [[ "$(svc '{"id":"sleepy","op":"start"}' -H "X-CSRF-Token: $csrf")" == 422 ]]
  grep -q 'proc_open' "$tmp/svc.out"
  # shellcheck disable=SC2016
  metrics | php -r '$d=json_decode(stream_get_contents(STDIN),true); $m=$d["metrics"]; if($m["capabilities"]["exec"]!==false) exit(1); foreach($m["services"] as $s) if($s["can_start"]) exit(1); if(!is_array($m["slot"])||$m["memory"]===null) exit(1);'
else
start_code="$(svc '{"id":"sleepy","op":"start"}' -H "X-CSRF-Token: $csrf")"
if [[ "$start_code" != 200 ]]; then
  echo "ERROR: sleepy service start returned HTTP $start_code (expected 200)." >&2
  exit 1
fi
# shellcheck disable=SC2016
php -r '$d=json_decode(file_get_contents($argv[1]),true); if(!is_array($d)||empty($d["ok"])) { fwrite(STDERR,"ERROR: sleepy service start returned an empty/invalid success body.\\n"); exit(1); }' "$tmp/svc.out"
# shellcheck disable=SC2016
metrics | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["metrics"]["services"] as $s) if($s["id"]==="sleepy"&&$s["running"]) exit(0); exit(1);'
[[ "$(svc '{"id":"sleepy","op":"stop"}' -H "X-CSRF-Token: $csrf")" == 200 ]]
# shellcheck disable=SC2016
metrics | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["metrics"]["services"] as $s) if($s["id"]==="sleepy"&&$s["running"]) exit(1); exit(0);'
fi
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/config-path.php")" == 404 ]]
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/inc/lib.php")" == 404 ]]
[[ "$(stat -c '%a' "$tmp/data/sessions")" == 700 ]]
curl -sS -D "$tmp/logout" -o /dev/null -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'logout=1' "$url"
grep -q 303 "$tmp/logout"
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$url?action=metrics")" == 401 ]]
# brute-force lockout is per client IP and survives cookie resets
for _ in 1 2 3 4 5 6; do
  rm -f "$tmp/c2"; curl -sS -c "$tmp/c2" "$url" -o "$tmp/l2"
  c2="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/l2" | head -1)"
  code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/c2" --data-urlencode "csrf=$c2" --data-urlencode 'password=wrong-password-123' "$url")"
done
[[ "$code" == 429 ]]
# shellcheck disable=SC2086
php ${FQD_PHP_ARGS:-} tests/lib-test.php
echo 'PHP authentication, subdirectory and rootless service-control smoke tests passed.'
