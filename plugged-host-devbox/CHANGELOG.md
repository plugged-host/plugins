# Changelog

## 2.2.1 (2026-10-23)

- For panels other than plugged.host:
  - The web domain may be anything but the panel's own registrable domain (from `APP_URL`) or
    `SESSION_DOMAIN`, a name under or above them; it used to refuse only `plugged.host`. The
    settings form, saving and `plugged-host:devbox-web-setup` check it.
  - The egg's name, devbox servers' descriptions, new CA and edge certificates' common names and
    the expired-link page name the panel by `APP_NAME` ("plugged.host Devbox" etc. on
    plugged.host). The egg and the system user are still found by UUID and username.
  - Settings, config comments, the doctor and `devbox-web-setup` point at
    <https://docs.plugged.host/nodes/devboxes/> instead of private scripts; the doctor names each
    node's data directory to add to Wings' `allowed_mounts`, and `ph` no longer assumes
    `/var/lib/pelican/volumes`.
  - README: the node guard lets containers reach Wings' API port, which `ph logs`, `ph cmd` and
    `ph console` use (the guard as written dropped it).

## 2.2.0 (2026-10-17)

- **New plugin: VS Code on the server for Plus.** A devbox is a hidden server Wings runs next to
  a game server, with its files at `/server`; `ph serve` inside is the SSH server (connect codes
  from the panel, account keys), VS Code in the browser through the edge, `ph` for the game
  server's status, logs, console and power.
- Security pass before production:
  - Starting a devbox checks everything again in `DevboxPower::start()` (who asks resolves to
    exactly that devbox, Plus, the running cap, the game server, abuse, the node), and the
    card's pending start is `#[Locked]` and re-checked: a server owner could start any devbox by
    its id, past the cap, Plus and the game server's state.
  - Stops no longer fail open: a devbox is marked `stopping` (the panel refuses its token from
    then on) and its token is only retired once Wings reports it offline; the reconcile asks
    again every minute and kills it after 60 s. Devbox servers are deleted on Wings first and
    never by force. The reconcile lists every devbox node's servers (one `GET /api/servers` per
    node) and kills any devbox container without a running devbox, a row or a panel server.
  - `ph`: a 401/403 from the panel empties the access list, closes every session (SSH and
    browser) and ends every other process in the devbox; 5 minutes without an answer from the
    panel closes every session until it answers again.
  - One running cap per person (`running_per_user`) that counts the shared devboxes on servers
    they own, taken under a lock; no start on a node that deployment pause holds, over 90% memory
    or CPU, or over the storage line on disk.
  - A running devbox stops when its game server's disk is full (its writes to `/server` count
    there), and none starts then.
  - A subuser needs SFTP and every file permission (read, read content, create, update, delete)
    besides VS Code: no more than SFTP would give them.
  - A shared devbox starts over (new token, everything in it ended) when a member is removed or
    loses a permission, or the server changes hands; personal devboxes of people who are no
    longer members stop, and every devbox on a server that gets suspended stops at once.
  - A critical abuse finding on a devbox stops every devbox of its person (the owner, for a
    shared one) and refuses new ones and sign-ins until an admin dismisses or resolves it
    (`plugged_host_devbox_blocks`); a devbox under review is not deleted. `plugged-host-abuse`
    is now required: devboxes stay off without it.
  - The image follows the release: `ghcr.io/plugged-host/devbox:<VERSION>` (CI pushes that tag
    too) or a digest in `DEVBOX_IMAGE`; existing devboxes take it at their next start; the
    doctor warns while it is a tag.
  - `DEVBOX_ENABLED` is off by default.
  - Migration `020_add_devbox_stopping_and_abuse_blocks` (`stopping_at`, the blocks table).
  - README: production checklist with the node firewall (nftables) for the Wings bridge.

