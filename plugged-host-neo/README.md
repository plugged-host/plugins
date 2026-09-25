# plugged.host neo

neo (always lowercase) is plugged.host's support AI. It chats with users on the app and server
panels and can act on a server with that user's own permissions: read the console, list, read,
search, write, edit, move, delete, compress and extract files, send console commands, and
start/stop/restart (through the same start queue / waiting room / limbo chain as the Start button).

## Where things live

- **noprofit.space** holds the AI provider chain (keys, models, failover), the 5-hour and weekly
  token limits, and the usage ledger. This plugin never sees a provider key.
- **This plugin** owns the chat, the agent loop, the tools, approvals, conversation history and
  the profile tab. It talks to noprofit.space's `/api/v1/internal/neo/*` with HMAC-signed
  requests (core's `NoprofitClient`), identifying users by the noprofit account link.

## Settings

Admin → Plugins → neo → Settings edits both: panel values go to this panel's `.env`
(`NEO_ENABLED`, `NEO_GATEWAY_SECRET`, `NEO_MAX_STEPS`, …); providers and limits are saved on
noprofit.space. Use "Test provider" after saving to check a slot answers and can call tools.

## Per-user controls (profile → neo)

- **Enable neo** (default on): off hides neo and refuses every request for that user.
- **Let neo make changes without asking** (default off): while off, writes, deletes, console
  commands and power actions wait for Approve / Decline in the chat. Reads never ask.

## Safety

Owners and subusers only (admin override access does not enable neo); permissions re-checked
right before each action; secrets masked before anything reaches a provider; tool output is
treated as untrusted; every change is written to the server's activity log as `server:neo.*`.

## Extending

Other plugins can add tools with `PluggedHost\Neo\Tools\NeoToolRegistry::register(MyTool::class)`
from a provider's `boot()`; a tool implements `PluggedHost\Neo\Tools\Tool`.

## Commands

- `plugged-host-neo:chat {user} "message" --server=<uuid_short> [--approve] [--new]` — drive neo
  from the terminal as that user.
- `plugged-host-neo:prune` — scheduled daily; deletes conversations past `NEO_RETENTION_DAYS`.
