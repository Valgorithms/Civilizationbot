# Shard group worlds (shard_group)

A Civ13 shard group runs several worlds ("regions") from one config, each in its
own worktree with its own port, `serverdata.txt`, IPC files and webhooks (see
Civ13's `scripts/shard-group/README.md`). The bot treats each region as a normal
game server entry and drives it with `scripts/shard-group/shard_group.py`.

For civ13.com's TDM, `bot.php` reads one validated `/home/civ13/tdm.json`
snapshot. The `tdm` entry follows the configured front door (Barracks or
gateway), and `tdmbattle` follows the configured Battle region. Each uses its
actual region directory and port. Battle keeps the public TDM Play link and
does not produce another aggregate Play button. A genuinely absent group file
keeps legacy single-world status only; an invalid configured file disables
the TDM integrations rather than falling back to the wrong service.

The official TDM aliases are **status-only for lifecycle management**.
`tdmrestart` previously acted on the front door, including the gateway.
`tdmbattlerestart` only acted on Battle. The old host, kill, restart, update,
and map-swap handlers now refuse for these entries across text, slash, and
panel surfaces. Use the maintainer runbook, not a Discord alias.

### TDM operator checks

1. Fast-forward the configured shared TDM source checkout, and reload the
   `civ13-tdm-host` service. This refreshes the coordinator, not the separate
   running world processes.
2. Use the coordinator's group `restart` **without a region argument**.
   Expect all intended non-gateway regions under `accepted`. When a gateway
   exists, the coordinator deliberately lists it under `refused` with the
   reason that it stays on its port. Treat any other refusal as a failure,
   even when the aggregate reply says `ok`.
3. Check the worlds and hub listing after they finish. An accepted operation
   is queued, not proof they are ready. Restart the gateway by name only
   when deliberately updating the front door.

The bot itself is updated by fast-forwarding its own checkout and restarting
the operator-managed `civilizationbot` unit. The bot no longer runs these
updates or restarts through its retired maintenance commands.

## Host controls

```php
'shard_group' => [
    'enabled' => true,
    'python' => '/usr/bin/python3',
    'script' => '/home/civ13/civ13-tdm-src/scripts/shard-group/shard_group.py',
    'config' => '/home/civ13/tdm.json',
    'region' => 'battle',
    'kill' => 'stop', // or 'kill': forced stop without saving
],
```

For civ13.com, `bot.php` takes the script from the group config's
`repository` (`<repository>/scripts/shard-group/shard_group.py`), so it always
matches the checkout the group runs from. All three paths must be absolute and exist; the script and config must not be
links. The bot passes only a fixed action, the config and `--region=<id>`. The
group config names every path and command. Enable either `gaia_host` or
`shard_group`, not both. A configured but unusable `shard_group` makes host
controls refuse with the reason; the bot never falls back to launching
DreamDaemon itself.

| Discord | shard_group.py |
| --- | --- |
| `<key>host`, panel Host | `start --region=<id>` |
| `<key>kill`, panel Kill | `stop --region=<id>` (or `kill`, see above) |
| `<key>restart`, panel Restart, `/restart_server` | `restart --region=<id>` |
| `<key>update` | `update --region=<id>` |
| `<key>hoststatus` | `status --region=<id>`: region name, port, world ready, players, last action, map |
| `<key>activity` (Ambassador, Admin) | none: reads `<group root>/player-activity-weekly.json` from the server's `region_group` |
| `<key>karma <ckey>` (Ambassador, Admin) | none: reads `<group root>/shared-records/karma/<ckey>.json` from the server's `region_group` |

`<key>activity` shows the group's weekly player activity totals (active players,
new players, sessions, deaths, trips, hours per region). The Civ13 host writes the
file beside its activity database; it holds no player names, research or recipe ids.

`<key>karma <ckey>` (or a verified Discord ID) shows a player's hidden karma for
staff: admin standing, playtime, kindly and poorly counts (raw and weighted), and
the heaviest givers with their own standing, hours, admin-notes flag and warning
flags. Karma is never shown to players, so the bot answers only in the `staff_bot`
channel or a DM and refuses anywhere else. The Civ13 coordinator writes one file
per ckey whenever that ckey's karma changes; the bot never opens its database.

start, stop, restart and update are accepted within seconds and finish in the
script's detached worker; `hoststatus` shows progress. The bot runs as the same
user as the group (`civ13`), so no sudo is involved. Roles are the same as for
[gaia_host](gaia-host.md). A frozen region may accept an update as build staging;
its running version has not changed. The table describes explicitly managed
non-TDM entries that have not retired their legacy aliases.

Awards belong to the world that writes them. Do not use an empty awards file
on the gateway as a substitute for the awarding world. Missing awards now
leave the ranking unchanged rather than creating a placeholder or wiping it.

## Following the front door (`follow_front_door`)

The host can move the group's front door (the region on the public port, the
group file's `gate` behind a routing relay, else its `mainland`) to another
region with Civ13's `shard_group.py set-gate` or the launcher's "Front door and
ports" dialog. A portable profile server that sets

```json
"region_group": "D:/Civ13Regions/earth.json",
"follow_front_door": true
```

takes its region paths from the group file each time the bot starts
(`PortableProfile::followFrontDoor`): `basedir` becomes `<root>/<front door>`,
`admin_file` the group's shared `<root>/front-door/SQL/admins.txt` when the
group keeps one (else, where the profile names an `admin_file`, the front
door's own `SQL/admins.txt`), and an enabled `gaia_host`'s `config` that
region's `data/shard-host/host.json`. The profile is never rewritten, so after a
move only a bot restart is needed. The values written in the profile remain the
fallback when the group file cannot be read or the front door is not set up,
so keep them valid.

## Secondary regions (`shard_of`)

A region that belongs to another entry's group sets `'shard_of' => '<parent key>'`
(the battle world sets `'shard_of' => 'tdm'`). Such a world:

- keeps its own webhooks (`/webhook/<key>`), rounds, bans, admin and verification
  files, chat relays and round embed;
- never renames the player-count channel: the parent lists the region's
  directory in `shards` (or the whole group file in `region_group`, see
  [passive-server-status.md](passive-server-status.md)) and counts its players
  there, by region, each player once;
- never receives Discord OOC. A message in the shared OOC channel goes to the
  parent world only, which shares it in game with the other regions (Civ13
  `region_ooc.dm`, which needs the group's transfer agents).

Game OOC from each world reaches Discord once: a world with its own webhook
posts its own lines, and the parent only reposts lines from regions without one.

## Game side (each region's overlay `config.local.txt`)

Use the same webhook key for both worlds and a different path for each:

```
WEBHOOK_ADDRESS http://127.0.0.1:<bot http port>/webhook/tdm        # Barracks
WEBHOOK_ADDRESS http://127.0.0.1:<bot http port>/webhook/tdmbattle  # battle
```

## Checks

`php tests/local/shard-region.php vendor/autoload.php` runs the adapter through
the real worker against a stand-in script, and the GameServer routing for Kill,
Restart, Update and an unusable config. `tests/local/ooc-auto-relay.php` and
`tests/local/heartbeat-integration.php` cover the `shard_of` OOC and
player-count rules.
