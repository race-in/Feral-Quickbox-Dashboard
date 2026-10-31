<div align="center">

# Feral Quickbox Dashboard

**A rootless monitoring dashboard for Feral Hosting shared slots.**

[![CI](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml/badge.svg)](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](LICENSE)

</div>

> **Status: Experimental MVP.** The PHP monitoring app and integration smoke test are implemented. A live Feral deployment and public-URL verification are still pending.

A QuickBox-inspired, independent dashboard designed for rootless shared hosting. The first version uses the existing PHP-FPM web stack and is intended to open at `/Feral-Quickbox-Dashboard/` below the user's existing domain.

## Features

- PHP-only runtime: no Node.js, npm, daemon, or extra TCP listener.
- Private password login with session-protected metrics endpoint and CSRF-protected login/logout.
- Live CPU, memory, home-filesystem, network, load-average and uptime metrics.
- Responsive dark interface; no third-party frontend dependencies.
- Rootless and non-destructive: no sudo, apt, systemd, Nginx edits, service restarts, or torrent-client changes.

Host-level values may describe the shared server, not only the account. Disk reports filesystem capacity and is not a Feral quota measurement. These limits are disclosed in the UI.

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

## Current scope

qBittorrent/Deluge API integration, service controls and arbitrary process actions are deliberately not included in this MVP. No torrent-client configuration is read or changed.

## Upstream and license

Inspired by the QuickBox Lite dashboard and Classic QuickBox UI lineage. No upstream source or third-party assets are copied into the current MVP. Any future upstream-derived code must retain applicable notices and comply with GPL terms. See [upstream review](docs/UPSTREAM-REVIEW.md), [Classic reference audit](docs/CLASSIC-QUICKBOX-REFERENCE.md), and [security model](docs/SECURITY.md).

Independent community project; not affiliated with Feral Hosting or QuickBox maintainers.
