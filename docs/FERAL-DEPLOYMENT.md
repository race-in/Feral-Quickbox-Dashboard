# Feral shared-slot deployment

The first runnable version is a PHP-only, read-only monitoring dashboard. It uses the existing per-user PHP-FPM web stack and does not need Node.js, npm, a daemon, a new TCP port, or system-level access.

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

The dashboard shows CPU, memory, home-filesystem capacity, network counters/throughput, load average and uptime when the host exposes those values. Some `/proc` and load values may be host-wide. Disk reports filesystem capacity, not the Feral account quota. The UI discloses this.

The first version does not connect to qBittorrent or Deluge and has no service controls or command execution.

## Upgrade / uninstall

Repeat the install command from an updated checkout to upgrade. Only a directory carrying `.fqd-managed` is eligible; unrelated non-empty directories are not overwritten.

To uninstall:

    bash uninstall.sh --webroot "$HOME/www/your-hostname.feralhosting.com/public_html"

This removes the marked dashboard and application data after confirmation. Private configuration/cache remain unless `--purge` is supplied.

If the URL returns 404/403 or PHP source instead of the login page, stop and report the response. Do not restart the existing Nginx/PHP-FPM processes as a troubleshooting shortcut.
