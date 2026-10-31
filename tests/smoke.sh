#!/usr/bin/env bash
# Feral Quickbox Dashboard - user-space installer smoke tests
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_root="$(mktemp -d)"
trap 'rm -rf -- "$tmp_root"' EXIT

export HOME="$tmp_root/home"
export XDG_CONFIG_HOME="$tmp_root/xdg-config"
export XDG_DATA_HOME="$tmp_root/xdg-data"
export XDG_CACHE_HOME="$tmp_root/xdg-cache"
mkdir -p -- "$HOME"

bash "$repo_root/install.sh"

config_dir="$XDG_CONFIG_HOME/feral-quickbox"
data_dir="$XDG_DATA_HOME/feral-quickbox"
cache_dir="$XDG_CACHE_HOME/feral-quickbox"
config_file="$config_dir/config.env"

[[ -d "$config_dir" && -d "$data_dir" && -d "$cache_dir" ]]
[[ "$(stat -c '%a' "$config_dir")" == "700" ]]
[[ "$(stat -c '%a' "$config_file")" == "600" ]]
grep -q '^HOST=127.0.0.1$' "$config_file"

# An existing configuration must remain byte-for-byte unchanged.
printf '\n# preserve-this-setting\n' >>"$config_file"
cp -- "$config_file" "$tmp_root/config.before"
bash "$repo_root/install.sh"
cmp -- "$config_file" "$tmp_root/config.before"

# Cancellation must not delete the application data directory.
printf 'n\n' | bash "$repo_root/uninstall.sh"
[[ -d "$data_dir" ]]

# Default uninstall removes data only and preserves settings.
printf 'y\n' | bash "$repo_root/uninstall.sh"
[[ ! -e "$data_dir" ]]
[[ -f "$config_file" ]]

# --purge explicitly removes configuration and cache as well.
printf 'y\n' | bash "$repo_root/uninstall.sh" --purge
[[ ! -e "$config_dir" && ! -e "$cache_dir" ]]

# Explicit prefix must work without touching XDG locations.
prefix="$tmp_root/custom-prefix"
bash "$repo_root/install.sh" --prefix "$prefix"
[[ -f "$prefix/config/config.env" ]]
[[ -d "$prefix/data" && -d "$prefix/cache" ]]

# A symlinked configuration file must never be followed for writing.
rm -rf -- "$prefix/config/config.env"
printf 'outside\n' >"$tmp_root/outside"
ln -s -- "$tmp_root/outside" "$prefix/config/config.env"
if bash "$repo_root/install.sh" --prefix "$prefix"; then
  printf 'ERROR: installer accepted a symlinked config file\n' >&2
  exit 1
fi
[[ "$(cat "$tmp_root/outside")" == "outside" ]]

printf 'All rootless installer smoke tests passed.\n'
