# QuickBox Classic dashboard reference audit

Review date: 2026-11-01

Reference: [QuickBox/QB — dashboard](https://github.com/QuickBox/QB/tree/master/dashboard)  
Reference type: Classic PHP/jQuery QuickBox dashboard  
Related project: [amefs/quickbox-lite — setup/dashboard](https://github.com/amefs/quickbox-lite/tree/master/setup/dashboard)

## Lineage and relationship

The Classic QuickBox dashboard is a useful design and feature reference for QuickBox Lite. QuickBox Lite describes its dashboard as a modified QuickBox CE dashboard, retaining common elements such as the package management center and service controller while reducing or changing parts of the original experience.

The two implementations should not be treated as identical source trees:
- Classic QuickBox uses PHP includes, PHP widget endpoints, jQuery/AJAX and a PHP session/config layer.
- QuickBox Lite's current dashboard uses a React/TypeScript server-rendered page, an Express router and Socket.IO/WebSocket handlers.
- Lite has its own package/config schemas and runtime assumptions. Classic PHP widgets are not drop-in replacements for Lite components.

## Useful Classic UI references

These areas are worth consulting for layout and visual ideas:
- `dashboard/index.php`: two-column dashboard composition, bandwidth chart, system load, uptime, disk, RAM and CPU panels.
- `dashboard/inc/panel.header.php`, `dashboard/inc/panel.menu.php`: header, navigation, brand and theme structure.
- `dashboard/skins/quick.css`: the visual style and panel treatment.
- `dashboard/widgets/bw_tables.php`, `dashboard/widgets/load.php`, `dashboard/widgets/up.php`: useful feature concepts for bandwidth, load and uptime.
- `dashboard/img/brands/`, `dashboard/img/favicon/`: asset references, subject to checking each file's license/provenance before copying.
- `dashboard/lang/`: localization structure and translations, subject to preserving attribution and validating content.

## Why Classic backend code must not be copied unchanged

Source inspection identified environment and security assumptions that do not fit a Feral shared slot:

| Classic file/component | Observed assumption | Required adaptation |
| --- | --- | --- |
| `dashboard/inc/config.php` | Includes `/srv/rutorrent/php/util.php`, reads `/srv/rutorrent/home/db/master.txt`, reads `/home/<username>` configs | Replace with explicit per-user config and XDG paths |
| `dashboard/widgets/disk_data.php` | Reads `/install/*.lock`, assumes `/home/<username>`, shells out to count torrents, optionally runs `sudo repquota` | Use configured, slot-owned paths; use client APIs for torrent counts; no sudo |
| `dashboard/widgets/sys_data.php` | Runs QuickBox system maintenance commands through sudo | Remove system maintenance actions |
| `dashboard/widgets/service_control.php` | Service controls are wired to QuickBox/OS process-management routes | Replace with fixed allow-listed user-owned process actions, or read-only status |
| `dashboard/widgets/pmc.php` | Installs/removes packages using QuickBox package scripts and lock files | Remove package installation/removal center |
| `dashboard/widgets/stat.php` | Reads a fixed network interface constant from host paths | Detect readable interfaces safely and label shared-host metrics accurately |
| `dashboard/widgets/cpu.php`, `load.php`, `ram_stats.php` | Reads host CPU/memory/load values and invokes shell commands in places | Treat data as host-visible metrics, never imply it is a slot-only resource view |
| `dashboard/inc/panel.app_status.ajax.js` | Polls many individual service status endpoints at a 1-second cadence | Prefer a single bounded poll, API health checks and configurable intervals |

Other older source constructs also require a security pass before any reuse: shell command strings are assembled from variables, legacy PHP helpers make broad system assumptions, and several endpoints are intended for privileged QuickBox maintenance.

## Recommended approach for Feral Quickbox Dashboard

1. Use Classic QuickBox as the visual/feature reference for dashboard layout, themes and panel hierarchy.
2. Use QuickBox Lite as the closer source for the more modernized dashboard components and localization.
3. Keep a fresh rootless backend; do not embed either upstream project's unrestricted command-execution model.
4. Start with a read-only interface: app links, qBittorrent/Deluge API connectivity, process status only for the current user, disk usage under user-accessible paths, and honest host-level metric labels.
5. Add controls only for explicit user-owned scripts with fixed executable paths, validated arguments, and no shell interpolation.
6. Pin upstream revisions, preserve file-level notices, include full GPLv3-or-later text, and audit third-party asset licenses before vendoring.

## Licensing note

The GitHub repository metadata identifies QuickBox/QB as **GPL-3.0** (GPL version 3 only), and its root license file is `LICENSE.md`. QuickBox Lite's dashboard package declares **GPL-3.0-or-later**. This initial Feral repository currently carries a GPL-3.0-or-later notice, but no Classic dashboard source files or binary assets have been copied into it as part of this review.

Before importing or adapting actual Classic QuickBox source code, reconcile the whole combined derivative under GPL-3.0 (version 3), preserve original copyright/license notices, and audit each bundled third-party asset separately. GPL-3.0-or-later components can be used under GPL version 3; the GPL-3.0-only Classic source cannot be relicensed as a whole under an "or later" grant merely by combining it with Lite code.

## Conclusion

The Classic dashboard is useful and arguably the better visual/layout reference; QuickBox Lite is the closer technical starting point for its modern dashboard rendering. Neither upstream backend can be installed unchanged on a Feral shared slot. The project should combine Classic's UI ideas with selected Lite presentation components and a purpose-built rootless backend.

This is a source review, not a live Feral-host compatibility test.
