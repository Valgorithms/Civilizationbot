# Shard group worlds (shard_group)

A Civ13 shard group runs several worlds ("regions") from one config, each in its
own worktree with its own port, `serverdata.txt`, IPC files and webhooks (see
Civ13's `scripts/shard-group/README.md`). The bot treats each region as a normal
game server entry and drives it with `scripts/shard-group/shard_group.py`.

civ13.com's TDM is the first such group: shard group `tdm` with the always-on
**Barracks** (region `barracks`, port 1714, server key `tdm`) and the **battle**
world (region `battle`, port 1715, server key `tdmbattle`). `bot.php` switches
to it by itself once `/home/civ13/tdm.json` exists; until then the single
`civ13-tdm.service` server stays in use.

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
| `<key>kill`, panel Kill | `stop --region=<id>` (or `kill`, see above), announced in OOC first |
| `<key>restart`, panel Restart, `/restart_server` | `restart --region=<id>`, announced in OOC first |
| `<key>update` | `update --region=<id>` |
| `<key>hoststatus` | `status --region=<id>`: region name, port, world ready, players, last action, map |
| `<key>activity` (Ambassador, Admin) | none: reads `<group root>/player-activity-weekly.json` from the server's `region_group` |

`<key>activity` shows the group's weekly player activity totals (active players,
new players, sessions, deaths, trips, hours per region). The Civ13 host writes the
file beside its activity database; it holds no player names, research or recipe ids.

start, stop, restart and update are accepted within seconds and finish in the
script's detached worker; `hoststatus` shows progress. The bot runs as the same
user as the group (`civ13`), so no sudo is involved. Roles are the same as for
[gaia_host](gaia-host.md).

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
