# Security model

## Rootless operation
The installer and runtime must execute as the unprivileged slot user. They must never call `sudo`, `su`, `apt`, `systemctl`, or write under system-owned directories.

## Network exposure
- Default bind address: `127.0.0.1`.
- Never bind to `0.0.0.0` by default.
- Do not assume the provider exposes arbitrary ports publicly.
- Any reverse proxy or tunnel is configured by the user using provider-supported mechanisms.

## Credentials
- Credentials belong in a user-owned configuration file with restrictive permissions.
- Do not place API secrets in frontend JavaScript, URLs, command-line arguments, or logs.
- Do not commit real credentials or tracker details.
- Use redaction for all diagnostic output.

## Command execution
- Do not build shell commands by concatenating browser input.
- Only support explicitly configured, user-owned executable scripts.
- Resolve and validate paths before execution.
- Do not provide arbitrary command execution through the dashboard.

## File operations
- Confine file browsing to explicitly configured roots.
- Reject path traversal and symlink escapes.
- Do not follow symlinks outside an allowed root.

## Uninstall
- Never remove unrelated user files.
- Require explicit confirmation before deletion.
- Preserve configuration by default.
