#!/usr/bin/env bash
# Feral Quickbox Dashboard - safe user-space removal
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'
purge=false; WEBROOT=""
usage(){ echo 'Usage: bash uninstall.sh [--purge] [--webroot PUBLIC_HTML]'; }
while (($#)); do case "$1" in
  --help|-h) usage; exit 0;;
  --purge) purge=true;;
  --webroot) (($#>=2)) || { echo 'ERROR: --webroot needs a path' >&2; exit 2; }; WEBROOT=$2; shift;;
  *) echo "ERROR: Unknown option: $1" >&2; usage >&2; exit 2;;
esac; shift; done
[[ ${EUID:-$(id -u)} -ne 0 ]] || { echo 'ERROR: Refusing to run as root.' >&2; exit 1; }
DATA="${XDG_DATA_HOME:-$HOME/.local/share}/feral-quickbox"
CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/feral-quickbox"
CACHE="${XDG_CACHE_HOME:-$HOME/.cache}/feral-quickbox"
DEST=""
if [[ -n "$WEBROOT" ]]; then
  case "$WEBROOT" in "$HOME"/*) ;; *) echo 'ERROR: webroot must be inside HOME.' >&2; exit 2;; esac
  [[ -d "$WEBROOT" && ! -L "$WEBROOT" ]] || { echo 'ERROR: Invalid webroot.' >&2; exit 1; }
  DEST="$WEBROOT/Feral-Quickbox-Dashboard"
  if [[ -e "$DEST" ]]; then [[ ! -L "$DEST" && -f "$DEST/.fqd-managed" ]] || { echo 'ERROR: refusing to remove an unmarked directory.' >&2; exit 1; }; fi
fi
printf 'Remove app data: %s\n' "$DATA"
[[ -z "$DEST" ]] || printf 'Remove dashboard: %s\n' "$DEST"
[[ "$purge" != true ]] || printf 'Also purge config/cache: %s, %s\n' "$CONFIG" "$CACHE"
read -r -p 'Continue? [y/N] ' answer
case "$answer" in y|Y|yes|YES) ;; *) echo 'Cancelled.'; exit 0;; esac
[[ ! -L "$DATA" ]] && rm -rf -- "$DATA"
[[ -z "$DEST" || ! -d "$DEST" ]] || rm -rf -- "$DEST"
if [[ "$purge" == true ]]; then
  [[ ! -L "$CONFIG" ]] && rm -rf -- "$CONFIG"
  [[ ! -L "$CACHE" ]] && rm -rf -- "$CACHE"
fi
echo 'Removal complete. Configuration is preserved unless --purge was used.'
