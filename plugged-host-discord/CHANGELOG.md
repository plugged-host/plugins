# Changelog

## 1.2.1

- Version bump to 1.2.1 (plugged.host 2.1).

## 1.3.0

- **Giveaway Rewards** (Admin → Users → Giveaway Rewards): paste a giveaway's winners by Discord
  user id (one per line, comma/space separated, or `<@…>` mentions), pick credits or tokens and an
  amount per winner, and every winner with a linked panel account is paid through
  `plugged-host-economy`'s ledger (type `giveaway`, description `Giveaway reward: <prize>`).
- Winners whose Discord id is not linked to a panel account yet are recorded as *Not linked*;
  **Retry unpaid** pays them once they link. Each winner is paid under a row lock, so a retry never
  pays anyone twice.
- Gated on the `update user` permission (same as the Economy tab on the admin user page). Hidden
  until migration `008_create_discord_giveaways_tables` has run.

## 1.2.0

- Version bump to 1.2.0 for the v2 release; open-source prep (README, this changelog).
- Account linking, membership verification, the panel Discord Linked role for resource limits,
  credit rewards for tracked invites and boosts, and the self-supervising gateway daemon are
  unchanged from 1.0.x — no behavioural change in this release.
- Credit rewards continue to post through `plugged-host-economy`'s ledger services.
