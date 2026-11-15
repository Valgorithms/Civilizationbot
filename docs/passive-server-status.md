# Passive server status

The Civ13 writer in `code/world.dm` emits `get_packaged_server_status_data()` to
`serverdata.txt` every 100 ticks (normally ten seconds). Its `realtime=` field
advances even when nobody is playing. The literal `Online` in that file is a
historical statement, not evidence that the game is responding now.

For enabled servers whose configured IP matches the bot's local external IP,
the bot samples that existing file every 15 seconds, without TCP or Topic
requests and without modifying its timestamp. An observation starts **Unknown**.
An increasing `realtime` between observations no more than 120 seconds apart
establishes **Heartbeat advancing**. After 120 seconds without observed progress,
the state becomes **Stale heartbeat**. Missing, unreadable, incomplete, oversized
or malformed data becomes **Unknown** immediately. A backwards heartbeat or
observer clock, a polling gap over 120 seconds, or a bot restart requires a new
baseline followed by another advance. Neither file mtime nor player changes
establish progress. Repeated rendering cannot refresh a frozen heartbeat.

This is evidence that the local writer ran recently. **Connectivity stays
Unverified**: no listener, Topic response, remote connection or successful join
is inferred. A fresh heartbeat may coexist with a networking failure. A stale
heartbeat may reflect a long save or pause rather than a crash. No recovery,
restart or game command is triggered.

Unknown/stale observations clear the current player cache and omit current game
metadata from status and current-round embeds. The player-count channel
(`<prefix>-<count>`, renamed every ten minutes) shows `offline` once the
heartbeat is stale or `serverdata.txt` has been missing for 120 seconds (the
game deletes and rewrites it, so a brief absence is ignored), rather than zero or
`unknown`. While a fresh baseline is still being observed, after an
unreadable or malformed read, and for remote/unobserved servers, it keeps its
current name.

A server that heads a Civ13 region group (`scripts/shard-group` in Civ13) lists
the other regions' worktree directories in `shards`, for example
`"shards": ["D:/Civ13Regions/nomads/surface", "D:/Civ13Regions/nomads/caves"]`.
Each region's `serverdata.txt` gets its own heartbeat, sampled on the same
15-second timer, and the channel shows the distinct ckeys across every advancing
world, so a traveller seen in two regions counts once. It reads `offline` only
when every world is stale or missing.

Instead of listing directories, a server can point `region_group` at the Civ13
shard group file, for example `"region_group": "D:/Civ13Regions/earth.json"`.
Every region in it (`<root>/<id>`) other than the server's own world is
watched under its group name, and the list follows the file: a region added to
or removed from the group is picked up on the next sample, without a bot
restart. An unreadable file keeps the last good list.

The status embed and the current-round embed in the player-count channel show
the same group-wide count in an `Online Players (N)` field, one line per region
with players (for example `**Gaia** (2): alice, bob`). Each ckey is listed once:
a traveller still listed by the region it left goes under the region it moved
to, and one listed by no region (between leaving one world and joining the
next) stays counted under *Between regions* for up to 30 seconds, so a trip
never drops the count. A logout shows there for that window too. When every
world is down nobody is listed. When the names do not fit in one embed field,
the field shows per-region counts. A server without shards keeps its plain
player list. The current-round embed retains its existing one-minute update
cadence. Remote/unobserved servers explicitly show unknown health and label
cached players unverified. This does not change the separate legacy serverinfo
web-service or verification-API indicators.

No server-side schema or configuration migration is needed: the current writer
already supplies `realtime`. The optional Approved-only field is normalized only
in these status readers. Other IPC and access-control consumers are unchanged.
The observation is intentionally in memory, excluded from GameServer exports,
and never resumes a prior process's health assertion after a bot restart.

Offline verification (never use `tests/bootstrap.php`, which reads `.env`):

```console
php tests/local/heartbeat.php
php tests/local/heartbeat-integration.php /explicit/dependency/vendor/autoload.php
```

The integration test loads production methods with fake Discord repositories,
a fake clock and temporary files. It refuses status TCP probes and `touch()`.
CI installs this checkout's dependencies without plugins/scripts and runs both
suites on PHP 8.3–8.5 on Windows and Linux. These checks do not verify the deployed
bot revision or current hosted Nomads health.
