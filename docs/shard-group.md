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
    'script' => '/home/civ13/civ13-git/scripts/shard-group/shard_group.py',
    'config' => '/home/civ13/tdm.json',
    'region' => 'battle',
    'kill' => 'stop', // or 'kill': forced stop without saving
],
```

All three paths must be absolute and exist; the script and config must not be
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

start, stop, restart and update are accepted within seconds and finish in the
script's detached worker; `hoststatus` shows progress. The bot runs as the same
user as the group (`civ13`), so no sudo is involved. Roles are the same as for
[gaia_host](gaia-host.md).

## Secondary regions (`shard_of`)

A region that belongs to another entry's group sets `'shard_of' => '<parent key>'`
(the battle world sets `'shard_of' => 'tdm'`). Such a world:

- keeps its own webhooks (`/webhook/<key>`), rounds, bans, admin and verification
  files, chat relays and round embed;
- never renames the player-count channel: the parent lists the region's
  directory in `shards` and counts its players there (a player seen in both
  worlds counts once);
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
