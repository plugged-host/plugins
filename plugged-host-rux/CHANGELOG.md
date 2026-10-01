# Changelog

## 1.0.0

- First version: rux, the plugged.host Discord bot for moderation and credit giveaways. It takes
  plain-language orders (slang welcome) by DM, by @mention or by "hey rux".
  - Admins (Discord accounts linked to noprofit.space admins) can order everything. Members of
    rux moderator roles can order moderation only.
  - Giveaways require a linked noprofit.space account, can keep out the winners of the last N
    giveaways, and are drawn and paid automatically.
  - Standing rules ("if anyone asks for new locations, warn them").
  - Watched channels (#chat, #bot-commands) where every message is checked in batches.
  - Warning steps: 3 warnings = 24h timeout, 5 = 24h ban, which rux lifts itself.
  - Runs on the production panel only.
