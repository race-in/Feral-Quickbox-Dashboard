# QuickBox Lite upstream compatibility review

Review date: 2026-11-03

Reviewed upstream: [amefs/quickbox-lite](https://github.com/amefs/quickbox-lite)  
Reviewed branch: `master`  
Reviewed commit: [`e0b522b8ccced7a07d0ae9cb575c052af6054d7e`](https://github.com/amefs/quickbox-lite/commit/e0b522b8ccced7a07d0ae9cb575c052af6054d7e)  
Dashboard backend version: `1.6.1`

## Finding

The upstream dashboard is not a drop-in rootless application. Its UI can be a design/reference source, but the original backend and setup assumptions must not be installed unchanged on a Feral shared slot.

The upstream dashboard README describes the package-management center and service controller as preserved features. Its WebSocket service unit runs a Node process from `/srv/dashboard/ws/dist/server.js`, which is a system-wide deployment layout rather than a per-user install.

## Reviewed root/system dependencies

| Upstream component | Behaviour found | Rootless implication |
| --- | --- | --- |
| `setup/dashboard/ws/quickbox-ws.service` | system-wide systemd unit; starts Node from `/srv/dashboard` | Cannot be installed or managed as a system service by a slot user |
| `src/handlers/exec.ts` | Socket.IO event invokes a command after template validation | Must not be exposed with upstream command definitions unchanged |
| `config/commands.json` | Includes `systemctl`, QuickBox package install/remove scripts under `/usr/local/bin/quickbox`, and `box` operations | Those actions are system-wide and not valid in a shared rootless environment |
| `src/widgets/service-control.tsx` / `src/widgets/panels.tsx` | Reads package lock files and systemd enablement paths | Status/control panel needs replacement with user-process-aware logic |
| `src/router.tsx` | Theme and plugin POST routes call host-side commands | Disable or replace with safe user-owned configuration writes |
| `src/controllers/dashboard.tsx` and `src/widgets/package-management-center.tsx` | Renders package and service management UI | Do not show controls that cannot safely work |
| `src/handlers/exec.ts` | Default DB path is `/srv/dashboard/db`; username data is expected from QuickBox-specific setup | Move runtime state to XDG user directories and remove dependency on master.txt |
| `src/widgets/disk-data.tsx` | Uses `/install/*.lock` and `/home/<username>` assumptions | Must use explicitly configured, slot-owned paths |
| `src/widgets/memory-stats.tsx` / `src/widgets/load.ts` | Reads systeminformation and OS-wide metrics; the memory widget's clear-cache button also invokes a QuickBox `box` command | On a shared host, metrics may be host-level rather than slot-level; remove the cache-clearing action and label scope honestly |

## Security boundary

The upstream command builder validates command, operation and target against an allow-list. That is useful input validation, but it does **not** make the configured commands suitable for a shared slot: the allow-list itself contains system-wide operations. We must remove the privileged operations rather than rely on the template validator.

The Socket.IO command execution feature must remain disabled until a new interface exists that accepts only named, user-owned actions with fixed executable paths and argument arrays. No browser-provided string may be passed to a shell.

The backend must also be bound to `127.0.0.1` by default, use a user-owned port, keep credentials server-side, and avoid exposing other users' process or filesystem information.

## Reuse plan

1. Pin the reviewed upstream revision instead of following a moving branch.
2. Reuse eligible dashboard assets/layout only after preserving upstream license and attribution.
3. Replace systemd, package installation/removal, QuickBox updater, theme shell commands, and plugin shell commands.
4. Move state/config/log files to the slot user's XDG directories.
5. Implement read-only metrics and torrent-client integrations with explicit disclosure of host-vs-slot scope.
6. Add a user-owned process controller only for configured processes that the slot user owns.
7. Test on an actual Feral slot before calling the project Feral-compatible.

## Conclusion

The correct path is a controlled rootless adaptation, not a one-line upstream installer. This review is based on source inspection; it is not a live-host compatibility test.


## Feral Classic UI branch status

The `feral-classic-quickbox-rootless` branch uses a purpose-built PHP page with a Classic QuickBox-style top bar, desktop sidebar, responsive navigation, bandwidth panel, Service Control Center and informational Package Management Center. It does not bundle the upstream Node/WebSocket daemon or execute the upstream `systemctl`, package-install, package-remove or host-maintenance command templates. User-process actions remain subject to the Feral branch's private services allow-list and per-user process checks.

The visual port is not a claim of full operational parity: root-level package installation/removal, systemd enablement, firewall and host maintenance are intentionally unavailable. The remaining feature gaps are tracked in `ROADMAP.md`.
