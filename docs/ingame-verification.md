# In-game verification

Players can link their BYOND key to Discord without editing their BYOND profile.

1. In Discord, the player runs `/approveme` **without** a ckey. CivilizationBot replies
   privately with a single-use code such as `ABCD-EFGH`, valid for 10 minutes.
2. In any Civ13 server, the player opens the OOC tab, clicks **Verify Discord** and types the code.
3. The game sends `ckey` + `code` to its own bot as the `discord_verify` webhook method
   (`/webhook/<server>/discord_verify?key=<WEBAPI_TOKEN>&data={"ckey","code"}`) and shows the
   JSON `{ok, message}` answer to the player.

BYOND has already signed the player in, so the game proves the ckey and the private code proves
the Discord account. Bans, investigations, existing links, the BYOND account age rule and the
alt/multi-Discord staff review still apply (`Verifier::processInGame`). The "seen on our servers"
check is skipped because the player is on a server right now. Five wrong codes lock that ckey
out of in-game verification for ten minutes. Codes are kept in memory only.

## Primary and desktop bots

- **CivilizationBot** (primary, writes the verified list) redeems the code itself.
- **VZGCivilizationBot** (desktop profile with the read-only hosted verifier) cannot register anyone.
  It forwards the request to the primary bot's `POST /verified/ingame` endpoint next to the hosted
  verified list (`verification_source.url` + `/ingame`), signed with `X-Ingame-Verify-Time` and
  `X-Ingame-Verify-Signature` (HMAC-SHA256 of `time + "\n" + body` with the shared secret), then passes the primary bot's answer back to the game. The link reaches the desktop game through
  the normal hosted verified-list refresh and access-file sync.

Both bots need the same random secret as `INGAME_VERIFY_TOKEN`: in the primary bot's `.env` and
in the desktop profile's environment file. Without it the primary bot refuses relays (403) and the
desktop bot tells players to use `/approveme` in Discord instead. The secret itself is never sent, so
the plain-HTTP `http://civ13.com:55555` source is acceptable: a captured request expires after 60 seconds
and its code is single use. Anyone holding the secret could link any ckey to a Discord user with a live
code, so keep it out of logs and chat.

`/approveme` is re-registered on every start so existing installs get the optional ckey.
