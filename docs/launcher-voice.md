# Launcher voice lobbies

Bot side of Civ13 launcher voice (Civ13 issue #5122, Phase 3). The game side
is Civ13 `code/modules/voice/voice_chat.dm`, and the design is in Civ13
`docs/launcher-voice-plan.md`. The code is `src/Civ13/VoiceLobbies.php`.

- `POST /webhook/<server>/voice_roster` (whitelisted; `X-Webhook-Key`): the
  game's `{v: 1, members: [{ckey, key, team}]}`. The answer is
  `{ids: {ckey: Discord user ID}}` for every ckey that has an account so far.
- Each ckey gets a Discord provisional account through
  `partner-sdk/token/bot`, with external user ID `byond:<ckey>`. Players need
  no Discord login. Tokens live only in memory.
- One lobby per world (`civ13_kind: region`) and one per team
  (`civ13_kind: team`, `civ13_team`), created with the bot token. Members
  follow the roster. A player who drops out of it is removed after 30
  seconds at most; their launcher key stops working at once. If a world
  stops reporting, its presence expires 45 seconds after its last roster.
  Token lookup checks the deadline immediately; a five-second bot timer
  reconciles region and team membership, including retries after failed
  updates, without needing another roster. A delayed add is reconciled
  against current presence when it completes.
- This only changes the existing v1 roster's lifetime. It does not verify
  that Discord stops live audio on removal, revoke a previously issued
  Discord access token, or reject authenticated v1 roster replays. Do not
  use it as a private proximity-voice boundary.
- `POST /voice/token` (public, 30 per minute per address):
  `{id: sha256("civ13-voice-token:" + key)}`. The answer is 202 until the
  token exists, then `{v: 1, sealed}`. `sealed` is base64(nonce | ciphertext |
  tag) under AES-256-GCM, with key `HKDF-SHA256(key, info "civ13-voice-seal")`
  and the token ID as AAD. It opens to `{application_id, server, user_id,
  access_token, expires_in}`.

Needs the Social SDK enabled on the bot's own application in the Discord
Developer Portal (the launcher uses the `application_id` it is given).
Offline contracts: `php tests/local/voice-lobbies.php vendor/autoload.php`.
