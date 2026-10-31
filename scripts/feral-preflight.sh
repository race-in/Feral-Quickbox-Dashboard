#!/usr/bin/env bash
# Feral Quickbox Dashboard - read-only shared-slot preflight
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'

usage() {
  cat <<'EOF'
Usage: bash scripts/feral-preflight.sh [--help]

Read-only diagnostics for a rootless shared-hosting environment.
The script does not install packages, create files, start services, or change settings.
EOF
}

if (($# > 0)); then
  if (($# == 1)) && [[ "$1" == "--help" || "$1" == "-h" ]]; then
    usage
    exit 0
  fi
  printf 'ERROR: Unknown or unsupported arguments.\n' >&2
  usage >&2
  exit 2
fi

printf '%s\n' 'Feral Quickbox Dashboard — rootless preflight'
printf '%s\n' '=============================================='
printf 'User ID: %s\n' "$(id -u)"
printf 'Rootless execution: '
if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
  printf '%s\n' 'NO (run as the slot user)'
else
  printf '%s\n' 'YES'
fi

printf 'Home directory writable: '
if [[ -n "${HOME:-}" && -d "$HOME" && -w "$HOME" ]]; then
  printf '%s\n' 'yes'
else
  printf '%s\n' 'no'
fi

printf 'OS/kernel: %s / %s\n' "$(uname -s)" "$(uname -r)"
printf 'Bash: %s\n' "${BASH_VERSION:-unknown}"
printf 'Open-file limit: %s\n' "$(ulimit -n)"
printf 'Process limit: %s\n' "$(ulimit -u)"

for tool in git curl node npm python3 pgrep; do
  if command -v "$tool" >/dev/null 2>&1; then
    printf '%-10s %s\n' "$tool:" 'available'
  else
    printf '%-10s %s\n' "$tool:" 'not found'
  fi
done

if command -v node >/dev/null 2>&1; then
  printf 'Node version: %s\n' "$(node --version)"
fi
if command -v npm >/dev/null 2>&1; then
  printf 'npm version: %s\n' "$(npm --version)"
fi

printf 'Readable /proc/self/status: '
if [[ -r /proc/self/status ]]; then
  printf '%s\n' 'yes'
else
  printf '%s\n' 'no'
fi

printf 'Loopback TCP bind test: '
if command -v node >/dev/null 2>&1; then
  node <<'NODE'
const net = require("node:net");
const server = net.createServer();
server.once("error", () => {
  process.stdout.write("failed\n");
  process.exitCode = 1;
});
server.listen(0, "127.0.0.1", () => {
  const address = server.address();
  process.stdout.write(address && typeof address === "object"
    ? `available (ephemeral port ${address.port})\n`
    : "failed\n");
  server.close();
});
NODE
else
  printf '%s\n' 'not tested (Node.js missing)'
fi

printf '\n%s\n' 'Directory readiness (no directories are created):'
for label_path in \
  "config:${XDG_CONFIG_HOME:-$HOME/.config}" \
  "data:${XDG_DATA_HOME:-$HOME/.local/share}" \
  "cache:${XDG_CACHE_HOME:-$HOME/.cache}"; do
  label=${label_path%%:*}
  dir=${label_path#*:}
  if [[ -d "$dir" && -w "$dir" ]]; then
    state='writable'
  elif [[ -d "$(dirname -- "$dir")" && -w "$(dirname -- "$dir")" ]]; then
    state='parent-writable (directory can be created)'
  else
    state='not writable'
  fi
  printf '  %-7s %s\n' "$label:" "$state"
done

printf '\n%s\n' 'Preflight complete. No system settings or files were changed.'
