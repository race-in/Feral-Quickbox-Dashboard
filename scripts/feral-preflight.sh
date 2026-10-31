#!/usr/bin/env bash
# Feral Quickbox Dashboard - read-only shared-slot preflight
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'
if (($#)); then [[ $# == 1 && ( "$1" == --help || "$1" == -h ) ]] && { echo 'Usage: bash scripts/feral-preflight.sh'; exit 0; }; echo 'ERROR: unsupported arguments' >&2; exit 2; fi
echo 'Feral Quickbox Dashboard — read-only preflight'
printf 'User: %s (uid %s)\n' "$(whoami)" "$(id -u)"
printf 'Home writable: '; [[ -d "$HOME" && -w "$HOME" ]] && echo yes || echo no
printf 'OS/kernel: %s / %s\n' "$(uname -s)" "$(uname -r)"
printf 'Bash: %s\n' "$BASH_VERSION"
printf 'Open-file limit: %s\nProcess limit: %s\n' "$(ulimit -n)" "$(ulimit -u)"
for c in git curl php python3 pgrep; do command -v "$c" >/dev/null 2>&1 && printf '%-8s available\n' "$c" || printf '%-8s missing\n' "$c"; done
if command -v php >/dev/null 2>&1; then php -r 'echo "PHP CLI: ".PHP_VERSION."\npassword_hash(): ".(function_exists("password_hash")?"yes":"no")."\n";'; fi
for p in /proc/self/status /proc/meminfo /proc/net/dev /proc/uptime; do [[ -r "$p" ]] && echo "Readable: $p" || echo "Unavailable: $p"; done
for d in "${XDG_CONFIG_HOME:-$HOME/.config}" "${XDG_DATA_HOME:-$HOME/.local/share}" "${XDG_CACHE_HOME:-$HOME/.cache}"; do [[ -d "$d" && -w "$d" ]] && echo "Writable: $d" || echo "Will need create permission: $d"; done
echo 'Preflight complete. No files or system settings changed.'
