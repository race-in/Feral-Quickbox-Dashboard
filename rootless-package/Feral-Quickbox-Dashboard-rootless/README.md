<div align="center">

# Feral QuickBox Dashboard

**QuickBox Lite dashboard UI, adapted for Feral rootless shared hosting.**

[![CI](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml/badge.svg)](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](LICENSE)

</div>

> **Status: Experimental.** This branch is the Classic UI port. PHP smoke tests and CI must pass before a live upgrade; existing deployments are not changed automatically.

The UI now follows QuickBox Lite's original fixed header and logo panel, three-tab left navigation, QuickBox Lite menu dropdown, Bootstrap/Quirk dashboard grid, left-side bandwidth/service/package panels and right-side load, CPU, disk and RAM panels. The Defaulted and Smoked themes, logo and Glyphicons asset are included with attribution. Authentication, metrics and service controls remain PHP-only and Feral-rootless; no upstream Node/WebSocket backend or privileged package scripts are used.

## Branch

Use **`feral-classic-quickbox-rootless`** for the Classic QuickBox-style Feral edition. It is intentionally separate from `main`, `rootless-package-test` and `ui/model-c-opsconsole`.

## Rootless guarantees

**Shared server = rootless.** No root, no sudo, no systemctl, no apt and no system-wide QuickBox installer scripts. The app refuses to run as root.

- PHP-only runtime: no Node.js, npm, WebSocket daemon or extra TCP listener.
- Host CPU, memory, disk, network, load and uptime, with scope clearly labelled.
- My Slot process count, CPU and memory are limited to processes owned by the current account.
- Live bandwidth chart and per-interface totals/speeds.
- Service Control Center with status and optional Start / Restart / Stop only for services explicitly allow-listed in the private `services.ini`. Commands are validated as argument arrays; PHP 7.4+ passes them directly to proc_open, while PHP 7.0–7.3 individually quotes every argument with escapeshellarg for compatibility. The browser submits only an ID and action.
- Application shortcuts configured through private `APPS=`.
- Classic Package Management Center presentation. It identifies compatible user-space applications and explains that root-only QuickBox package installation is unavailable on Feral shared slots; it does not pretend to install packages.
- Private password hash, CSRF protection, persistent per-IP login throttling, private session storage, strict Content Security Policy and safe HTML output.

Host-level values may describe the whole shared server, not only this account. Disk shows filesystem capacity unless a real quota is configured.

## Install this branch

Run as the Feral slot user. Replace the hostname with the exact directory under `~/www/`:

    git clone --branch feral-classic-quickbox-rootless --single-branch https://github.com/race-in/Feral-Quickbox-Dashboard.git
    cd Feral-Quickbox-Dashboard/rootless-package/Feral-Quickbox-Dashboard-rootless
    bash scripts/feral-preflight.sh
    bash install.sh --webroot "$HOME/www/your-hostname.feralhosting.com/public_html"

On first deployment the installer asks for a 12–72 byte password and stores only its hash outside the public document root. Existing managed installs are backed up by the operator before upgrading; this installer does not change Nginx, PHP-FPM or system services.

Open:

    https://your-hostname.feralhosting.com/Feral-Quickbox-Dashboard/

See [Feral deployment guide](docs/FERAL-DEPLOYMENT.md) for upgrades, backup and removal.

## Configuration

Edit `~/.config/feral-quickbox/services.ini` to configure user-owned services and `~/.config/feral-quickbox/config.env` for `QUOTA_GB` and `APPS`. The installer creates the private files with restrictive permissions. See `.env.example` and `config/services.example.ini`.

## Compatibility boundaries

QuickBox's system package installation/removal, system updates, cache cleanup, firewall management and privileged systemd service operations cannot be reproduced on a Feral shared slot. Those actions are intentionally unavailable. A service appears as controllable only when its configured start/stop command is safe, executable and belongs to the current slot user. If PHP disables `proc_open`, monitoring remains available while process controls are disabled.

qBittorrent, Deluge, rTorrent and Transmission API integration is separate work; links and process visibility do not imply authenticated client API access.

## Upstream and license

The visual layer includes the QuickBox Lite CSS themes, logo and Glyphicons asset from [QuickBox Lite](https://github.com/amefs/quickbox-lite), distributed under their applicable notices and license. The Feral authentication, metrics, service allow-list and process-control backend is independently implemented and rootless. No upstream Node/WebSocket backend or privileged package scripts are included. See [upstream review](docs/UPSTREAM-REVIEW.md), [Classic reference audit](docs/CLASSIC-QUICKBOX-REFERENCE.md) and [security model](docs/SECURITY.md).

Independent community project; not affiliated with Feral Hosting or QuickBox maintainers.
