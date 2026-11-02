# Feral shared-slot deployment

The dashboard is PHP-only and rootless: live monitoring plus Start/Stop/Restart for services you allow-list. It uses the existing per-user PHP-FPM web stack and does not need Node.js, npm, a daemon, a new TCP port, or system-level access.

## Install

Run as the Feral slot user, not root. Replace the example host with the exact directory name under `~/www/`:

    git clone https://github.com/race-in/Feral-Quickbox-Dashboard.git
    cd Feral-Quickbox-Dashboard
    bash scripts/feral-preflight.sh
    bash install.sh --webroot "$HOME/www/your-hostname.feralhosting.com/public_html"

On first web deployment the installer prompts for a password (12–72 bytes). It stores only the password hash in `~/.config/feral-quickbox/config.env`, outside the public document root, with mode 0600. The config directory is mode 0700.

Open:

    https://your-hostname.feralhosting.com/Feral-Quickbox-Dashboard/

The installer does not edit Nginx/PHP-FPM configuration, restart services, or touch torrent-client settings. The app derives its URL prefix from `SCRIPT_NAME`, so the login form, assets, session cookie and metrics endpoint remain inside the requested subdirectory.

## Metrics and limitations

- Host figures (CPU, memory, network, load, uptime) come from `/proc` and may describe the whole shared server. The UI labels them as host-level.
- **My slot** counts only processes owned by your user. Memory is PSS (shared pages split between processes, so shared memory is not counted twice); it falls back to RSS if `smaps_rollup` is unreadable. CPU uses the system's real `CLK_TCK` (via `getconf`, fallback 100).
- Disk shows filesystem capacity. Set `QUOTA_GB` in `config.env` to show account usage measured by a throttled, low-priority background `du` of `HOME_PATH` (cached, refreshed every `QUOTA_REFRESH` seconds, default 900). `du` runs asynchronously, so the first view shows a placeholder.

## Service control

Edit `~/.config/feral-quickbox/services.ini` (copied from `config/services.example.ini`). Each section is an allow-listed service: `match` (process name), optional `start` / `stop` commands, optional `url`.

- Commands are split into an argument list. PHP 7.4+ passes that list directly to `proc_open`; PHP 7.0–7.3 use a compatibility fallback that independently applies `escapeshellarg` to every argument before building the command string. The browser sends only a section name and `start|stop|restart`.
- Only processes of your own uid are listed. Before any signal is sent the process identity is re-verified (same uid, same start time, still matches the service), which protects against PID reuse. A tiny window between the check and `kill()` cannot be removed from PHP.
- Without a `stop` command the dashboard sends SIGTERM and never escalates to SIGKILL.
- Common daemons that are running but not configured are shown read-only.

## When the host restricts PHP

Shared hosts often list functions in `disable_functions`. The dashboard degrades instead of crashing:

| Disabled | Effect |
| --- | --- |
| `proc_open` | Start/Stop/Restart are disabled in the UI with an explanation (Stop without a custom command still works if `posix_kill` exists). CPU ticks fall back to 100/s. Quota switches from background `du` to a pure-PHP, time-boxed, resumable scan (the first numbers appear after a few refreshes on large homes). Monitoring is unaffected. |
| `posix_*`, `getmypid`, `glob`, `sys_getloadavg`, `disk_*` | Replaced by `/proc`-based fallbacks or shown as unavailable. |
| `/proc` unreadable (`hidepid`, `open_basedir`) | Per-process and host metrics show as unavailable; the page still loads. |

Page size is detected from the running process (not assumed to be 4 KiB).

## Verification status

Automated (`tests/`, including `tests/degraded-smoke.sh`): installer, login/CSRF, subdirectory routing, lockout, service start/stop, PID-reuse refusal, quota `du` caching, non-root execution, PHP 7.0-compatible escaped-argument `proc_open` fallback (including CLI signal fallback), and degraded hosts with `proc_open`, `posix_*`, `getmypid`, `glob` and other optional functions disabled.

**Not yet proven:** a real Feral PHP-FPM slot (`disable_functions`, `open_basedir` and `hidepid` can hide `/proc` or block `proc_open`), quota numbers against Feral's own accounting, and start/stop with each real daemon (rTorrent under screen, qBittorrent, Deluge, ...). Run `bash scripts/feral-preflight.sh` first; it reports `proc_open`, `smaps_rollup`, `CLK_TCK` and PHP extensions. After install, check each service's Start/Stop once by hand and compare quota with `quota -s` or your control panel.

## Upgrade / uninstall

Repeat the install command from an updated checkout to upgrade. Only a directory carrying `.fqd-managed` is eligible; unrelated non-empty directories are not overwritten.

To uninstall:

    bash uninstall.sh --webroot "$HOME/www/your-hostname.feralhosting.com/public_html"

This removes the marked dashboard and application data after confirmation. Private configuration/cache remain unless `--purge` is supplied.

If the URL returns 404/403 or PHP source instead of the login page, stop and report the response. Do not restart the existing Nginx/PHP-FPM processes as a troubleshooting shortcut.
