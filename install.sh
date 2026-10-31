#!/usr/bin/env bash
# Feral Quickbox Dashboard - conservative rootless bootstrap
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'

readonly APP_NAME="feral-quickbox"
CONFIG_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/$APP_NAME"
DATA_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/$APP_NAME"
CACHE_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/$APP_NAME"

usage() {
  cat <<'EOF'
Usage: bash install.sh [--help] [--prefix PATH]

Create the rootless Feral Quickbox Dashboard user-space directories.
This bootstrap does not install dependencies, modify system files, or start services.
EOF
}

prefix=""
while (($#)); do
  case "$1" in
    --help|-h) usage; exit 0 ;;
    --prefix)
      (($# >= 2)) || { printf 'ERROR: --prefix requires a path\n' >&2; exit 2; }
      prefix=$2
      shift
      ;;
    *) printf 'ERROR: Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
  printf 'ERROR: Refusing to run as root. Run as your Feral slot user.\n' >&2
  exit 1
fi

if [[ -n "$prefix" ]]; then
  [[ "$prefix" != "/" ]] || { printf 'ERROR: unsafe prefix /\n' >&2; exit 2; }
  CONFIG_DIR="$prefix/config"
  DATA_DIR="$prefix/data"
  CACHE_DIR="$prefix/cache"
fi

for dir in "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"; do
  if [[ -e "$dir" && ! -d "$dir" ]]; then
    printf 'ERROR: Expected a directory but found another file type: %s\n' "$dir" >&2
    exit 1
  fi
done

mkdir -p -- "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"
chmod 700 "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"

config_file="$CONFIG_DIR/config.env"
if [[ ! -e "$config_file" ]]; then
  umask 077
  cat >"$config_file" <<'EOF'
# Feral Quickbox Dashboard user configuration
# Keep this file private. Never commit real credentials.
HOST=127.0.0.1
PORT=8787
EOF
  chmod 600 "$config_file"
  printf 'Created configuration: %s\n' "$config_file"
else
  printf 'Preserved existing configuration: %s\n' "$config_file"
fi

printf '\nBootstrap complete. No services were started or system files changed.\n'
printf 'Config: %s\nData:   %s\nCache:  %s\n' "$CONFIG_DIR" "$DATA_DIR" "$CACHE_DIR"
