<div align="center">

# Feral Quickbox Dashboard

**A rootless dashboard experiment for Feral Hosting shared slots.**

[![ShellCheck](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml/badge.svg)](https://github.com/race-in/Feral-Quickbox-Dashboard/actions/workflows/ci.yml)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](LICENSE)

</div>

> **Status: Experimental — initial compatibility scaffold.** No claim is made yet that the dashboard has been tested on a live Feral slot.

Feral Quickbox Dashboard is a user-space adaptation project inspired by the dashboard in [QuickBox Lite](https://github.com/amefs/quickbox-lite). The goal is to evaluate and adapt its dashboard experience for shared hosting where root access and system-wide service managers are unavailable.

## Project principles

- **Rootless by design:** install and run as the slot user; never request `sudo` or root.
- **Non-destructive:** do not overwrite existing application configuration or service files.
- **Least privilege:** bind only to loopback by default and avoid privileged ports.
- **Feral-aware:** use documented host capabilities rather than assuming systemd, apt, Nginx, or unrestricted process control.
- **Transparent:** clearly report unsupported features instead of pretending they work.

## What this project will and will not do

| Area | Direction |
| --- | --- |
| Dashboard UI | Adapt the upstream dashboard frontend after compatibility review |
| Host metrics | Detect available, user-readable metrics; show unavailable values honestly |
| qBittorrent / Deluge | Integrate through their existing APIs where configured |
| Process controls | Use explicit user-owned commands only; no system-wide service control |
| Package manager | Out of scope on shared hosting |
| Nginx / firewall / system services | Out of scope; require provider/root administration |

## Current stage

This first commit establishes the project structure, safe installer/uninstaller foundations, and CI checks. It does **not** install the upstream QuickBox Lite dashboard yet. That integration will happen only after the original backend dependencies and privileged operations have been reviewed and replaced.

## Requirements

- Linux shell environment with Bash
- `curl` or `wget` for future source downloads
- A user-writable home directory
- An available unprivileged TCP port
- Optional: Node.js (the dashboard runtime will be selected after upstream compatibility testing)

## Quick start

Clone the repository and inspect the installer:

```bash
git clone https://github.com/race-in/Feral-Quickbox-Dashboard.git
cd Feral-Quickbox-Dashboard
bash install.sh --help
bash scripts/feral-preflight.sh
```

The installer is intentionally conservative. The initial scaffold only creates a project-owned directory and configuration template; it does not start a dashboard server.

## Configuration

The future runtime configuration will live under the user's home directory, not in `/etc`:

```text
~/.config/feral-quickbox/config.env
~/.local/share/feral-quickbox/
~/.cache/feral-quickbox/
```

See [docs/ROADMAP.md](docs/ROADMAP.md), [docs/SECURITY.md](docs/SECURITY.md), and the [upstream compatibility review](docs/UPSTREAM-REVIEW.md). Run `bash scripts/feral-preflight.sh` on the slot to collect non-invasive environment diagnostics before any dashboard runtime is installed.

## Upstream acknowledgement and licensing

This project is inspired by and intends to adapt parts of the QuickBox Lite dashboard from [amefs/quickbox-lite](https://github.com/amefs/quickbox-lite), which is licensed under **GNU GPL v3 or later**. The upstream project and its authors retain their copyrights. Any upstream-derived code incorporated here will retain the applicable copyright notices and GPL terms. This repository's licensing and attribution will be updated alongside each upstream-derived component.

- Upstream repository: https://github.com/amefs/quickbox-lite
- Upstream dashboard notes: https://github.com/amefs/quickbox-lite/tree/master/setup/dashboard

## Contributing

Please open an issue before proposing changes that affect process control, authentication, network exposure, or application credentials. Never submit real slot credentials, API passwords, cookies, or private tracker details.

## Disclaimer

This is an independent community adaptation project. It is not affiliated with or endorsed by Feral Hosting or the QuickBox Lite maintainers. Use at your own risk and follow your hosting provider's terms.
