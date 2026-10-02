# Changelog

## 1.1.0

- AI: rux fetches neo's providers from noprofit.space by itself and fetches them again every
  minute, so it always runs the chain set in neo's settings. The copy (`RUX_AI_P1..3_*`,
  `plugged-host:rux-copy-ai`) is gone; `plugged-host:rux-ai` shows what rux has. The last chain
  fetched is kept for when noprofit.space is unreachable.
- AI: Google AI Studio (Gemini API: Gemini and Gemma models) as a provider format, like neo.

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
  - AI: neo's three providers 1:1 (Groq Qwen, Groq gpt-oss, justwoker) with neo's failover,
    called directly with rux's own copy of the settings (`plugged-host:rux-copy-ai`). No neo
    limits apply.
  - Runs on the production panel only.
