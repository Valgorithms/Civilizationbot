# Scheduled-task game hosts (gaia_host)

Live Gaia on Windows runs from a scheduled task, not from the native supervisor.
For such a server the bot calls Civ13's `scripts/gaia-host/gaia_host.py`
(see that folder's README in Civ13/Civ13), which is also what the launcher uses.

Enable it per server in the portable profile:

```json
"gaia_host": {
  "enabled": true,
  "python": "C:/Users/you/AppData/Local/Programs/Python/Python312/python.exe",
  "script": "D:/GitHub/Civ13/scripts/gaia-host/gaia_host.py",
  "config": "D:/Civilizationbot-local/gaia-host.json"
}
```

The server must be enabled, `native_lifecycle` must stay disabled, and all three
paths must exist. The bot passes only the action name; the script's own config
names the game folder, scheduled task, git and compiler.

| Discord | Action | Reply |
| --- | --- | --- |
| `<key>host`, panel Host | `start` | Accepted, or the refusal (for example "the server is already running"). |
| `<key>kill`, panel Kill | `stop`: save, shut down, verified backup | Announced in OOC first. |
| `<key>restart`, panel Restart, `/restart_server` | `restart` | Announced in OOC first. |
| `<key>update` | `update`: pull and compile while stopped | Accepted or refused. |
| `<key>hoststatus` | `status` | One line: port, world ready, players, last action and phase. |

All actions need the Ambassador role, as before. Actions are accepted within
seconds and finish in the script's detached worker; `hoststatus` shows progress
and errors. Only one action runs at a time.

Offline check: `php tests/local/gaia-host.php vendor/autoload.php` runs the
adapter through the real worker against a stand-in script.
