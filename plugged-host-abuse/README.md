# plugged.host Abuse Protection

Finds miners, proxies, botnets and similar on hosted servers, suspends them (or reports
them), and deletes what it suspended after a grace period. Requires `plugged-host-core`
(suspension). Admin pages: **Servers › Abuse** (findings, node agents).

## How it checks

- **Full scan**, every 3 hours (`plugged-host:scan-abuse --suspend`): every server's whole
  file tree, likely file contents, startup values and console.
- **CPU watch**, every minute (`plugged-host:abuse-watch`): a server at `ABUSE_CPU_PERCENT`
  of its CPU limit for `ABUSE_CPU_MINUTES` gets its console read and the full check.
- **Node agent**, every minute: `agent/abuse-agent.py` (Python 3.6+, stdlib only) reports the
  processes and connections on each Wings node to `/plugged-host/abuse/agent/report`, signed
  with that node's own Wings token. All rules live on the panel (`Services/Signatures`,
  `Services/ProcessInspector`); the agent only collects.
- **Purge**, hourly (`plugged-host:abuse-purge`): deletes servers the checks suspended
  `ABUSE_DELETE_AFTER_HOURS` after the suspension, unless an admin unsuspended one or
  dismissed a finding on it.

Console text is never critical on its own (chat can be echoed into it).

## Setup

1. Enable the plugin and run `php artisan migrate` (tables `plugged_host_abuse_findings`,
   `plugged_host_abuse_agents`).
2. Settings (Admin › Plugins › plugged.host Abuse Protection), all stored in `.env`:
   `ABUSE_MODE` (`report` by default, then `suspend` once the findings look right, or `off`),
   `ABUSE_ALERT_WEBHOOK` (a Discord webhook for staff, optional), `ABUSE_CPU_PERCENT`,
   `ABUSE_CPU_MINUTES`, `ABUSE_DELETE_AFTER_HOURS`.
3. Install the agent on every Wings node, as root:

   ```bash
   curl -fsSL https://<panel>/plugged-host/abuse/agent/install.sh | sudo bash
   ```

   It needs the node's `/etc/pelican/config.yml` (for the token), installs `python3-minimal`
   if missing, and runs as the hardened `plugged-host-abuse-agent.service` (CPUQuota 25%,
   MemoryMax 256M). No secret to copy: the node signs with its own Wings token.
4. `php artisan plugged-host:doctor plugged-host-abuse` lists nodes without a reporting agent.

The agent reads the Wings token only when it starts. After a node's Wings token is reset (and
Wings' `config.yml` updated), its reports are refused (403, `bad signature`) until the agent is
restarted: `systemctl restart plugged-host-abuse-agent`, or re-run the installer.

Staff alerts are posted to the webhook as "`APP_NAME` abuse".
