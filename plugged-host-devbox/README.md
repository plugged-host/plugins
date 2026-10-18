# plugged.host Devbox

VS Code on the server for Plus: a private Linux box next to a game server that VS Code
Remote-SSH (or VS Code in the browser) connects to, with the game server's files at `/server`.
Each devbox is a hidden Pelican server that Wings runs (devbox egg, owned by the system user
`ph-devbox`, its own allocation as the SSH port and its own volume as the home), with the game
server's volume mounted through a Pelican Mount that core's mount guard lets through for that
devbox only. Inside, `ph serve` (Go, `ph/`) is the SSH server; it asks the panel who may sign in.

Requires `plugged-host-core`, `plugged-host-servers`, `plugged-host-economy` (Plus) and
`plugged-host-abuse`. Devboxes stay off (`DEVBOX_ENABLED=false`) until switched on.

## Production checklist

Before `DEVBOX_ENABLED=true`:

1. **Abuse protection on.** `plugged-host-abuse` enabled (devboxes stay off without it),
   `ABUSE_MODE=suspend`, and its node agent reporting on every node that will run devboxes
   (`php artisan plugged-host:doctor plugged-host-abuse`). A critical finding on a devbox stops
   every devbox of its person (the game server's owner for a shared one) and refuses new ones
   until the finding is dismissed or resolved (Admin › Servers › Abuse).
2. **Migrations** `018_create_plugged_host_devboxes_table` and
   `020_add_devbox_stopping_and_abuse_blocks`, then `php artisan plugged-host:devbox-setup`
   (egg and system user; run it again after changing the image).
3. **Image.** CI (`.github/workflows/devbox-image.yml`) publishes
   `ghcr.io/plugged-host/devbox:<VERSION>` (e.g. `2.2.0`), which is the default; the package
   must be public on GHCR (or the image pre-pulled on every node). Tags move with every push:
   pin the build you tested with `DEVBOX_IMAGE=ghcr.io/plugged-host/devbox@sha256:<digest>`
   (the workflow prints it; the doctor warns while it is a tag). Devboxes pick it up at their
   next start.
4. **Nodes**, one at a time: core's mount guard is live (it is, since 2.2), then add the node's
   data directory (Wings' `system.data` in `/etc/pelican/config.yml`, by default
   `/var/lib/pelican/volumes`; the same path as the node's Daemon Base Directory in the panel) to
   the top-level `allowed_mounts` list of that file and restart Wings (`systemctl restart wings`;
   running servers keep running), then the **node firewall** below. Step by step:
   <https://docs.plugged.host/nodes/devboxes/>. The firewall applies to every container on the
   node and is worth having without devboxes too.
5. Switch on (Admin › Plugins › plugged.host Devbox), then `php artisan plugged-host:doctor
   plugged-host-devbox` is clean (egg, system user, reconcile tick, image, mounts).

### Node firewall

A devbox is a full Linux shell on the node's container network, so the node must keep it (and
every game container) away from the node itself, the metadata service and private networks,
and away from sending mail directly. One nftables table of its own, independent of Docker's
rules (it runs before them and Docker never touches it), loaded by its own unit. Do **not** put
it in `/etc/nftables.conf` with `nftables.service`: Debian's file starts with `flush ruleset`,
which wipes Docker's rules whenever it is reloaded.

Read the values from the node's `/etc/pelican/config.yml` (`docker.network`; Wings' defaults
are shown), the node's allocation port range(s) and its Daemon Port (the port the panel reaches
Wings on: `api.port`, unless a proxy on the node sits in front of Wings) from the panel:

```bash
sudo install -d /etc/nftables.d
sudo tee /etc/nftables.d/ph-node-guard.nft >/dev/null <<'EOF'
#!/usr/sbin/nft -f
# Node guard: what containers on the Wings bridge may reach.

define WINGS_BRIDGE = "pelican0"             # docker.network: Wings' bridge name
define WINGS_V4 = 172.18.0.0/16              # docker.network.interfaces.v4.subnet
define WINGS_V6 = fdba:17c8:6c94::/64        # docker.network.interfaces.v6.subnet
define GAME_PORTS = { <FIRST_PORT>-<LAST_PORT> }   # the node's allocation range(s)
define WINGS_API_PORT = 8080                 # the node's Daemon Port in the panel

table inet ph_node_guard
delete table inet ph_node_guard

table inet ph_node_guard {
    # Containers to elsewhere: the internet yes; private ranges, link-local (the cloud
    # metadata service at 169.254.169.254) and SMTP no. Containers on the bridge keep
    # reaching each other, and replies to connections from outside always pass.
    chain forward {
        type filter hook forward priority -10; policy accept;
        iifname != $WINGS_BRIDGE return
        ct state established,related return
        ip daddr $WINGS_V4 return
        ip6 daddr $WINGS_V6 return
        ip daddr { 10.0.0.0/8, 100.64.0.0/10, 169.254.0.0/16, 172.16.0.0/12, 192.168.0.0/16 } counter drop
        ip6 daddr { fc00::/7, fe80::/10 } counter drop
        tcp dport 25 counter drop
    }

    # Containers to this host (the bridge's gateway or any of its addresses): only the game
    # ports (servers on a 127.0.0.1 allocation are reached at the gateway), Wings' API and
    # ICMP. Nothing else: not SSH, Wings' SFTP, a database, Redis or metrics.
    # Wings' API: a devbox's ph logs, ph cmd and ph console open Wings' websocket at the node's
    # address, which is this host. It is open to the internet anyway, and needs a token.
    chain input {
        type filter hook input priority -10; policy accept;
        iifname != $WINGS_BRIDGE return
        ct state established,related return
        meta l4proto { icmp, ipv6-icmp } return
        tcp dport $GAME_PORTS return
        udp dport $GAME_PORTS return
        tcp dport $WINGS_API_PORT return
        counter drop
    }
}
EOF

sudo tee /etc/systemd/system/ph-node-guard.service >/dev/null <<'EOF'
[Unit]
Description=Node guard (nftables rules for the Wings bridge)
After=network-pre.target
Before=docker.service wings.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/nftables.d/ph-node-guard.nft
ExecReload=/usr/sbin/nft -f /etc/nftables.d/ph-node-guard.nft
ExecStop=/usr/sbin/nft delete table inet ph_node_guard

[Install]
WantedBy=multi-user.target
EOF

sudo nft -c -f /etc/nftables.d/ph-node-guard.nft   # syntax check, changes nothing
sudo systemctl daemon-reload
sudo systemctl enable --now ph-node-guard.service
sudo nft list table inet ph_node_guard
```

Servers whose egg has "force outgoing IP" on run on Wings' per-address Docker networks (`ip-…`),
not on `pelican0`, and are not covered; the devbox egg never has it.

**A node that is also a database host** (Admin › Database Hosts: a host whose address is this node)
must let its own servers reach that port, or every server database on the node stops working:
add `tcp dport 3306 return` (the host's port) to `input` before the drop.
Check Admin › Database Hosts for every node before loading the table there.

If containers must reach something on the host or in a private network (a node whose
`docker.network.dns` points at a resolver on the host: add `udp dport 53 return` and
`tcp dport 53 return` to `input`; a database in the provider's private network: a `return` for
that address before the `drop` in `forward`), add it explicitly. Undo with
`sudo systemctl disable --now ph-node-guard.service`.

Check it from a devbox terminal (or any game server's console tools) on that node:
`curl -sm5 https://example.com >/dev/null && echo ok` (works),
`curl -sm5 http://169.254.169.254/` (times out), `timeout 5 bash -c '</dev/tcp/172.18.0.1/<SSH_PORT>'`
(fails), the same for Wings' SFTP port (fails), `ph logs -n 5` prints the game server's last
console lines (Wings' API passes), and a game server on the node is still reachable from outside.
`nft list table inet ph_node_guard` shows the counters going up.

### Lifting an abuse block by hand

A block outlives its finding when the finding's server is deleted (abuse-purge, foreign key
cascade). To lift it anyway, in tinker:
`PluggedHost\Devbox\Models\DevboxBlock::query()->where('user_id', <id>)->update(['lifted_at' => now()]);`
