<div align="center">

# Feral Quickbox Dashboard

**A rootless monitoring dashboard for Feral Hosting shared slots.**

[![CI](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml/badge.svg)](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](LICENSE)

</div>

> **Status: Experimental.** Tested locally (installer + PHP smoke tests as a non-root user). A live Feral deployment is still pending.

A QuickBox Lite / Classic-inspired, independent dashboard designed for rootless shared hosting. It uses the existing PHP-FPM web stack and is intended to open at `/Feral-Quickbox-Dashboard/` below the user's existing domain.

## Motto

**Shared server = rootless.** No root, no sudo, no systemctl, no apt. The app even refuses to run as root.

## Features (QuickBox Lite style)

- PHP-only runtime: no Node.js, npm, daemon, or extra TCP listener.
- Overview cards: host CPU, memory, disk (or real quota via `QUOTA_GB`), uptime.
- Live network chart plus per-interface speed/total table, load average.
- **My slot** panel: process count, RAM and CPU of *your own* processes only.
- **Service control**: Start / Restart / Stop for services you allow-list in `services.ini`. Commands run as argv arrays (no shell); the browser sends only a service id + action. Common daemons (rTorrent, qBittorrent, Deluge, Sonarr, ...) are auto-detected read-only.
- App shortcuts (`APPS=` in config), light/dark theme, responsive layout.
- Security: password hash outside webroot, CSRF, per-IP login lockout that survives cookie resets, sessions stored in your private data dir, strict CSP (no inline styles/scripts without nonce).

Host-level values describe the shared server, not only your account; the UI says so.

## Install

Run as the Feral slot user. Replace the host with the exact folder under `~/www/`:

    git clone https://github.com/race-in/Feral-Quickbox-Dashboard.git
    cd Feral-Quickbox-Dashboard
    bash scripts/feral-preflight.sh
    bash install.sh --webroot "$HOME/www/your-hostname.feralhosting.com/public_html"

On first deployment the installer asks for a 12–72 byte password and stores only its hash outside the public document root.

Open:

    https://your-hostname.feralhosting.com/Feral-Quickbox-Dashboard/

See [Feral deployment guide](docs/FERAL-DEPLOYMENT.md) for upgrades and removal.

## Configuration

Edit `~/.config/feral-quickbox/services.ini` (created by the installer from `config/services.example.ini`) to add services, and `config.env` for `QUOTA_GB`, `APPS`. See `.env.example`.

## Current scope

Package center, system updates, cache clearing and anything needing root from upstream QuickBox are intentionally **not** ported. qBittorrent/Deluge API integration is still on the roadmap.

## Upstream and license

Inspired by the QuickBox Lite dashboard and Classic QuickBox UI lineage. No upstream source or third-party assets are copied into the current MVP. Any future upstream-derived code must retain applicable notices and comply with GPL terms. See [upstream review](docs/UPSTREAM-REVIEW.md), [Classic reference audit](docs/CLASSIC-QUICKBOX-REFERENCE.md), and [security model](docs/SECURITY.md).

Independent community project; not affiliated with Feral Hosting or QuickBox maintainers.
