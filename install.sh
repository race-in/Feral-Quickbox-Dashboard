#!/usr/bin/env bash
# Feral Quickbox Dashboard - rootless bootstrap and web deployment
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'
APP=feral-quickbox
CONFIG_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/$APP"
DATA_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/$APP"
CACHE_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/$APP"
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WEBROOT=""
usage(){ printf 'Usage: bash install.sh [--help] [--webroot PUBLIC_HTML]\nWithout --webroot, create private XDG directories only.\nWith --webroot, deploy to PUBLIC_HTML/Feral-Quickbox-Dashboard/.\n'; }
while (($#)); do case "$1" in
  --help|-h) usage; exit 0;;
  --webroot) (($#>=2)) || { echo 'ERROR: --webroot needs a path' >&2; exit 2; }; WEBROOT=$2; shift;;
  *) echo "ERROR: Unknown option: $1" >&2; usage >&2; exit 2;;
esac; shift; done
[[ ${EUID:-$(id -u)} -ne 0 ]] || { echo 'ERROR: Run as the slot user, not root.' >&2; exit 1; }
for d in "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"; do
  [[ ! -L "$d" ]] || { echo "ERROR: Refusing symlink: $d" >&2; exit 1; }
  [[ ! -e "$d" || -d "$d" ]] || { echo "ERROR: Not a directory: $d" >&2; exit 1; }
done
mkdir -p -- "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"
chmod 700 "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"
CFG="$CONFIG_DIR/config.env"
[[ ! -L "$CFG" && (! -e "$CFG" || -f "$CFG") ]] || { echo 'ERROR: Unsafe config path.' >&2; exit 1; }
if [[ ! -e "$CFG" ]]; then umask 077; printf '# Private Feral Quickbox settings\nHOST=127.0.0.1\nPORT=8787\n' >"$CFG"; fi
grep -q '^HOME_PATH=' "$CFG" || printf 'HOME_PATH=%s\n' "$HOME" >>"$CFG"
chmod 600 "$CFG"
if [[ -z "$WEBROOT" ]]; then
  echo 'Rootless bootstrap complete. No services or system files changed.'
  printf 'Config: %s\nData: %s\nCache: %s\n' "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"
  exit 0
fi
case "$WEBROOT" in "$HOME"/*) ;; *) echo 'ERROR: webroot must be inside HOME.' >&2; exit 2;; esac
[[ -d "$WEBROOT" && -w "$WEBROOT" && ! -L "$WEBROOT" ]] || { echo 'ERROR: webroot must exist, be writable and not be a symlink.' >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo 'ERROR: PHP CLI is required.' >&2; exit 1; }
php -r 'exit(function_exists("password_hash")?0:1);' || { echo 'ERROR: password_hash unavailable.' >&2; exit 1; }
[[ -f "$ROOT/public/index.php" ]] || { echo 'ERROR: public/index.php missing.' >&2; exit 1; }
DEST="$WEBROOT/Feral-Quickbox-Dashboard"
[[ ! -L "$DEST" ]] || { echo 'ERROR: destination is a symlink.' >&2; exit 1; }
if [[ -e "$DEST" && ! -f "$DEST/.fqd-managed" ]]; then
  [[ -d "$DEST" ]] || { echo 'ERROR: destination is not a directory.' >&2; exit 1; }
  [[ -z "$(find "$DEST" -mindepth 1 -maxdepth 1 -print -quit)" ]] || { echo 'ERROR: refusing to overwrite an unmanaged directory.' >&2; exit 1; }
fi
if ! grep -q '^PASSWORD_HASH=' "$CFG"; then
  while true; do
    IFS= read -r -s -p 'New dashboard password (12–72 bytes): ' P; echo
    IFS= read -r -s -p 'Confirm password: ' Q; echo
    [[ "$P" == "$Q" ]] || { echo 'Passwords do not match.' >&2; continue; }
    N="$(LC_ALL=C printf '%s' "$P" | wc -c)"
    ((N>=12 && N<=72)) || { echo 'Password must be 12–72 bytes.' >&2; continue; }
    break
  done
  H="$(printf '%s' "$P" | php -r '$p=stream_get_contents(STDIN); echo password_hash($p,PASSWORD_DEFAULT);')"
  unset P Q
  [[ -n "$H" ]] || { echo 'ERROR: Could not hash password.' >&2; exit 1; }
  printf '\nPASSWORD_HASH="%s"\n' "$H" >>"$CFG"
  unset H
fi
mkdir -p -- "$DEST"
cp -a -- "$ROOT/public/." "$DEST/"
php -r 'echo "<?php\nif (!defined(\x27FQD_BOOTSTRAPPED\x27)) { http_response_code(404); exit; }\nreturn " . var_export($argv[1], true) . ";\n";' "$CFG" >"$DEST/config-path.php.tmp"
chmod 644 "$DEST/config-path.php.tmp"
mv -f -- "$DEST/config-path.php.tmp" "$DEST/config-path.php"
printf '%s\n' 'Managed by Feral Quickbox Dashboard.' >"$DEST/.fqd-managed"
find "$DEST" -type d -exec chmod 755 {} +
find "$DEST" -type f -exec chmod 644 {} +
chmod 700 "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"; chmod 600 "$CFG"
printf 'Dashboard deployed: %s/Feral-Quickbox-Dashboard/\n' "$WEBROOT"
printf 'Private config: %s\nNginx/PHP-FPM were not changed or restarted.\n' "$CFG"
