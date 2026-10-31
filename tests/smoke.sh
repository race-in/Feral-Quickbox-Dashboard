#!/usr/bin/env bash
# Feral Quickbox Dashboard - rootless installer/deployment tests
# Copyright (C) 2026 race-in contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -Eeuo pipefail
IFS=$'\n\t'
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
export HOME="$tmp/home" XDG_CONFIG_HOME="$tmp/config" XDG_DATA_HOME="$tmp/data" XDG_CACHE_HOME="$tmp/cache"
mkdir -p "$HOME"
bash "$repo/install.sh"
cfg="$XDG_CONFIG_HOME/feral-quickbox/config.env"
[[ -f "$cfg" && "$(stat -c '%a' "$cfg")" == 600 ]]
grep -q "^HOME_PATH=$HOME$" "$cfg"
printf '\n# preserved\n' >> "$cfg"
cp "$cfg" "$tmp/config.before"
bash "$repo/install.sh"
cmp "$cfg" "$tmp/config.before"
prefix="$tmp/custom"
mkdir -p "$prefix/feral-quickbox" "$tmp/data/feral-quickbox" "$tmp/cache/feral-quickbox"
printf 'outside\n' > "$tmp/outside"
ln -s "$tmp/outside" "$prefix/feral-quickbox/config.env"
if XDG_CONFIG_HOME="$prefix" bash "$repo/install.sh"; then echo 'ERROR: symlink config accepted' >&2; exit 1; fi
[[ "$(cat "$tmp/outside")" == outside ]]
web="$HOME/www/test.feralhosting.com/public_html"
mkdir -p "$web"
printf 'SmokeTestPassword123!\nSmokeTestPassword123!\n' | bash "$repo/install.sh" --webroot "$web"
app="$web/Feral-Quickbox-Dashboard"
[[ -f "$app/index.php" && -f "$app/.fqd-managed" && -f "$app/config-path.php" ]]
grep -q '^PASSWORD_HASH=' "$cfg"
[[ "$(stat -c '%a' "$cfg")" == 600 ]]
php -l "$app/index.php" >/dev/null
cp "$cfg" "$tmp/deployed-config"
bash "$repo/install.sh" --webroot "$web"
cmp "$cfg" "$tmp/deployed-config"
other="$HOME/www/other.feralhosting.com/public_html"
mkdir -p "$other/Feral-Quickbox-Dashboard"
echo keep > "$other/Feral-Quickbox-Dashboard/keep.txt"
if bash "$repo/install.sh" --webroot "$other"; then echo 'ERROR: unmanaged destination accepted' >&2; exit 1; fi
[[ "$(cat "$other/Feral-Quickbox-Dashboard/keep.txt")" == keep ]]
printf 'n\n' | bash "$repo/uninstall.sh" --webroot "$web"
[[ -f "$app/index.php" ]]
printf 'y\n' | bash "$repo/uninstall.sh" --webroot "$web"
[[ ! -e "$app" && -f "$cfg" ]]
echo 'All rootless installer/deployment smoke tests passed.'
