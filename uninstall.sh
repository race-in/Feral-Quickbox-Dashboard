#!/usr/bin/env bash
# Feral Quickbox Dashboard - safe user-space removal
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'

usage() {
  cat <<'EOF'
Usage: bash uninstall.sh [--help] [--purge]

Default: remove only the application data directory.
--purge: also remove configuration and cache directories.
EOF
}

purge=false
while (($#)); do
  case "$1" in
    --help|-h) usage; exit 0 ;;
    --purge) purge=true ;;
    *) printf 'ERROR: Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
  printf 'ERROR: Refusing to run as root.\n' >&2
  exit 1
fi

data_dir="${XDG_DATA_HOME:-$HOME/.local/share}/feral-quickbox"
config_dir="${XDG_CONFIG_HOME:-$HOME/.config}/feral-quickbox"
cache_dir="${XDG_CACHE_HOME:-$HOME/.cache}/feral-quickbox"

printf 'This will remove: %s\n' "$data_dir"
if [[ "$purge" == true ]]; then
  printf 'And configuration/cache: %s, %s\n' "$config_dir" "$cache_dir"
fi
read -r -p 'Continue? [y/N] ' answer
case "$answer" in
  y|Y|yes|YES) ;;
  *) printf 'Cancelled.\n'; exit 0 ;;
esac

rm -rf -- "$data_dir"
if [[ "$purge" == true ]]; then
  rm -rf -- "$config_dir" "$cache_dir"
fi
printf 'Removal complete.\n'
