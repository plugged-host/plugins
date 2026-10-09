# plugged.host noprofit.space

Connects the panel to [noprofit.space](https://accounts.noprofit.space), the account system
plugged.host runs on. **Optional**: without this plugin every other plugged.host plugin works on
its own (Pelican's own sign-in and Discord OAuth, panel-local credits and plans, neo with its
own AI providers and usage limits, rux paying giveaways locally).

With it enabled, noprofit.space takes over, through the contracts the other plugins define
(see `Providers/NoprofitBindingsServiceProvider`):

- **Sign-in and accounts**: every panel's login and `/register` go to noprofit.space
  (`/sso/callback` comes back); passkeys and upstream OAuth are closed; sign-in details are
  locked on the panel; `/migrate` is the one-time "Continue with plugged.host" conversion.
  `AccountPortal` points account and Discord-link pages at noprofit.space.
- **Account sync**: usage and the Discord snapshot go to noprofit.space every minute
  (`noprofit:accounts-sync`); noprofit.space calls `/api/noprofit/*` for summaries, deletion
  and Discord links.
- Converted accounts' SFTP passwords are checked against noprofit.space.

## Setup

Both sides share three secrets; set the same values in noprofit.space's `.env` and here
(Admin › Plugins › plugged.host noprofit.space, stored in the panel `.env`):

| Panel `.env` | Purpose |
|---|---|
| `NOPROFIT_ACCOUNTS_URL` | e.g. `https://accounts.noprofit.space` |
| `NOPROFIT_SSO_SECRET` | sign-in tokens |
| `NOPROFIT_MIGRATION_SECRET` | account export/commit/summary/delete, `/migrate` |
| `NOPROFIT_SYNC_SECRET` | credits and Discord links, both directions |
| `NOPROFIT_RESOLVE_IP`, `NOPROFIT_CA_BUNDLE` | optional: reach noprofit.space on this box directly |

noprofit.space must list `{APP_URL}/sso/callback` as a callback. Then run
`php artisan migrate` and `php artisan plugged-host:doctor plugged-host-noprofit`.
