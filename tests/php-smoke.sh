#!/usr/bin/env bash
# PHP auth and subdirectory smoke test
set -Eeuo pipefail
tmp="$(mktemp -d)"; pid=""
cleanup(){ [[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
web="$tmp/www"; app="$web/Feral-Quickbox-Dashboard"; cfg="$tmp/private/config.env"
mkdir -p "$app" "$(dirname "$cfg")"
cp public/index.php "$app/"
# shellcheck disable=SC2016
hash="$(printf '%s' 'SmokeTestPassword123!' | php -r '$p=stream_get_contents(STDIN); echo password_hash($p,PASSWORD_DEFAULT);')"
printf 'PASSWORD_HASH="%s"\nHOME_PATH=%s\n' "$hash" "$tmp" >"$cfg"
chmod 700 "$(dirname "$cfg")"; chmod 600 "$cfg"
# shellcheck disable=SC2016
php -r 'echo "<?php\nif (!defined(\x27FQD_BOOTSTRAPPED\x27)) { http_response_code(404); exit; }\nreturn " . var_export($argv[1], true) . ";\n";' "$cfg" >"$app/config-path.php"
# shellcheck disable=SC2016
port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url("tcp://".stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
php -S "127.0.0.1:$port" -t "$web" >"$tmp/server.log" 2>&1 & pid=$!
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
curl -sS -D "$tmp/headers" -o /dev/null -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'password=SmokeTestPassword123!' "$url"
grep -q 303 "$tmp/headers"
curl -fsS -b "$tmp/cookies" "$url" -o "$tmp/dashboard"
grep -q 'Good to see you' "$tmp/dashboard"
grep -q 'network-chart' "$tmp/dashboard"
grep -q 'Network activity' "$tmp/dashboard"
grep -q 'resource-grid' "$tmp/dashboard"
grep -q 'monitor-grid' "$tmp/dashboard"
grep -q 'SYSTEM DIAGNOSTICS' "$tmp/dashboard"
[[ "$(grep -o 'id=\"mem-detail\"' "$tmp/dashboard" | wc -l)" -eq 1 ]]
[[ "$(grep -o 'id=\"disk-detail\"' "$tmp/dashboard" | wc -l)" -eq 1 ]]
csrf="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/dashboard" | head -1)"
# shellcheck disable=SC2016
curl -fsS -b "$tmp/cookies" "$url?action=metrics" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["ok"])||!array_key_exists("cpu",$d["metrics"])||!array_key_exists("memory",$d["metrics"])) exit(1);'
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/config-path.php")" == 404 ]]
curl -sS -D "$tmp/logout" -o /dev/null -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'logout=1' "$url"
grep -q 303 "$tmp/logout"
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$url?action=metrics")" == 401 ]]
echo 'PHP authentication and subdirectory smoke tests passed.'
