# Security model

- Installer and runtime are rootless. No sudo, apt, systemctl, firewall, or system-wide service operations.
- Web files deploy only below an existing user-owned public_html directory.
- Installer refuses root, symlink destinations and non-empty unmanaged destinations. Upgrades require the .fqd-managed marker.
- Password hash and HOME_PATH are stored outside the document root in a user-private config directory (0700 / file 0600).
- Passwords are limited to 12–72 bytes because the default hash may be bcrypt.
- Session ID rotates after login; login/logout use CSRF tokens. Cookies are HttpOnly, path-scoped and Secure on HTTPS; SameSite is set where supported.
- Metrics API requires authentication and sends no-store responses. A nonce-based CSP blocks third-party scripts and frames.
- /proc and load values may be host-wide. Filesystem capacity is not the same as a Feral account quota.
- MVP does not store qBittorrent/Deluge credentials and has no command execution or service controls.
- Uninstall removes only the marked dashboard and app data after confirmation. Private config/cache are preserved unless --purge is requested.
