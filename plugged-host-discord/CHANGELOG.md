# Changelog

## Unreleased (2.2)

- **Works without noprofit.space**: `DiscordAccounts` (who a Discord user is) and
  `DiscordCreditPayouts` (giveaway and admin credit payouts). Standalone the panel answers from
  its own discord_links and pays through plugged-host-economy, recording giveaways in
  discord_giveaways (new nullable `source_key`, migration 009) and paying a pending winner when
  they link; plugged-host-noprofit binds noprofit.space's. The giveaway export to noprofit.space
  moved to that plugin.
- **Only the production panel acts on the Discord server** (`DiscordEnvironment`): the gateway
  daemon, slash-command registration and the 2x Booster role changes refuse to run elsewhere
  unless `DISCORD_ALLOW_NON_PRODUCTION=true`. Dev shares production's bot token, so its hourly
  sync could change real members' roles from dev's data. neo and rux already had this rule.

## Unreleased (2.1)

- **Discord rewards tab on the profile page** (first tab, `?tab=discord-rewards`): what invites
  and boosts earned, what is waiting and when it pays, what left or did not count, what is
  owed back, both boost levels and the reward history, with a "Check for new rewards" button.
  Replaces noprofit.space's rewards page; linking Discord stays on noprofit.space.
- **Giveaways moved to noprofit.space** (Admin › Giveaways). The panel's Giveaway Rewards page
  and `GiveawayRewardService` are gone; `plugged-host:discord-giveaways-export` hands the
  history over (paid winners as history, waiting winners are paid there once they link).
- **Security fix** (also shipped to prod as a patch): the interactions endpoint refuses unsigned
  requests, and `/setboost` requires the Administrator permission on both paths.
- The panel role no longer decides any limit (role limits are gone, see plugged-host-servers).

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
