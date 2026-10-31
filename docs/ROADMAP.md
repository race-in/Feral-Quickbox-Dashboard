# Roadmap

## Phase 0 — safe foundation
- [x] Establish a rootless-only repository scaffold.
- [x] Add conservative install/uninstall scripts.
- [x] Add CI validation and security guidance.
- [ ] Validate scripts on an actual Feral shared slot.

## Phase 1 — upstream dashboard compatibility
- [ ] Pin and document the exact QuickBox Lite upstream revision.
- [ ] Review every backend route, shell command, dependency, and privileged operation.
- [ ] Separate the frontend from the upstream systemd service assumptions.
- [ ] Replace system-wide service management with user-owned process controls.
- [ ] Confirm Node.js availability and a persistent-process method on Feral.

## Phase 2 — read-only monitoring
- [ ] CPU and memory metrics with clear unavailable states.
- [ ] Disk usage limited to user-visible paths.
- [ ] Network/interface metrics only where readable and meaningful.
- [ ] No expensive polling loops; configurable polling interval.

## Phase 3 — application integrations
- [ ] qBittorrent Web API integration using user-provided local endpoint.
- [ ] Deluge RPC integration using user-provided local endpoint.
- [ ] Never expose API credentials to browser-side code.
- [ ] Redact credentials and tokens from logs.

## Phase 4 — controlled actions
- [ ] Explicit allow-list of user-owned start/stop scripts.
- [ ] Validate executable paths and reject arbitrary command input.
- [ ] Show action result and process state honestly.

## Phase 5 — release readiness
- [ ] Test install, upgrade, restart, and uninstall on Feral.
- [ ] Test supported shells and Node.js versions.
- [ ] Security review, dependency audit, documentation, and tagged release.
