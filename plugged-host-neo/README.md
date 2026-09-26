# plugged.host neo

neo (always lowercase) is plugged.host's agentic assistant. Users give it a task in the chat on the
app and server panels and it carries the task out with that user's own permissions: it looks
(console, files, status, account), acts through its tools, checks the result and reports back.

- **On a server:** console and commands, files (list, read, search, write, edit, move, delete,
  compress, extract), power (through the same start queue / waiting room / limbo chain as the
  Start button), startup variables and Docker image, reinstall, backups.
- **Anywhere:** account overview, create / resize / rename / delete servers (plugged-host-servers),
  and reading logs shared on mclo.gs.
- **Minecraft servers:** plugins, mods and modpacks from Modrinth / CurseForge, Geyser, switching
  software (plugged-host-minecraft).

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

Owners, and subusers the owner gave the **neo → access** permission (admin override access does
not enable neo); permissions re-checked right before each action; secrets masked before anything
reaches a provider; tool output is treated as untrusted; every change is written to the server's
activity log as `server:neo.*`.

## mclo.gs logs ("Ask neo")

With the upstream `mclogs-uploader` plugin installed, neo watches for its "Upload logs" console
action (Laravel's HTTP `ResponseReceived` event, so that plugin stays byte-for-byte upstream).
When mclo.gs finds problems or errors in the upload, the user also gets a notification listing
them with **Ask neo to fix it**. That opens a new chat which starts with `read_mclogs` already run
on the upload (fenced as untrusted tool output, never pasted into the user's message). Only
uploads the user made from that server in the last two hours can be handed over.

## Extending

Other plugins can add tools with `PluggedHost\Neo\Tools\NeoToolRegistry::register(MyTool::class)`
from a provider's `boot()`; a tool implements `PluggedHost\Neo\Tools\Tool`.

## Commands

- `plugged-host-neo:chat {user} "message" --server=<uuid_short> [--approve] [--new]` — drive neo
  from the terminal as that user.
- `plugged-host-neo:prune` — scheduled daily; deletes conversations past `NEO_RETENTION_DAYS`.
