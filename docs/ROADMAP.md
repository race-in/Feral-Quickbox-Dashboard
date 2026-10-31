# Roadmap

## Phase 0 — safe foundation
- [x] Rootless repository scaffold, safe bootstrap and CI.
- [x] QuickBox Lite backend compatibility review and Classic UI reference audit.
- [x] Read-only Feral preflight script and live environment review.

## Phase 1 — rootless web MVP
- [x] PHP-only dashboard for existing PHP-FPM vhosts.
- [x] Subdirectory deployment at `/Feral-Quickbox-Dashboard/`.
- [x] Private password hash, session authentication and CSRF-protected forms.
- [x] Marked-directory install/upgrade/uninstall.
- [ ] Verify deployment through the live Feral public URL.

## Phase 2 — read-only monitoring
- [x] CPU, memory, home filesystem, network, load and uptime.
- [x] Disclose host-vs-slot scope and filesystem-vs-quota limitation.
- [ ] Validate metric accuracy across Feral slot layouts.

## Phase 3 — application integrations
- [ ] qBittorrent Web API with server-side credentials.
- [ ] Deluge RPC with server-side credentials.
- [ ] Redact credentials and tokens from logs.

## Phase 4 — controlled actions
- [ ] Allow-list only named, user-owned scripts.
- [ ] Reject arbitrary command input and show action results honestly.

## Phase 5 — release readiness
- [ ] Live Feral install/upgrade/uninstall testing.
- [ ] Security review, compatibility matrix and tagged release.
