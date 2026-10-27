# Windows admin-list files

The existing `GameServer::adminlistUpdate` writer uses ordinary files. It does
not require Discord desktop RPC or a named pipe. Admin records use LF on every
OS because the Civ13 DM loader splits on `|||\n`.

`servers.<key>.admin_file` optionally selects an explicit absolute local file
path. Omit it to retain `<basedir>/SQL/admins.txt`, including existing Debian
behavior. Both admin replacement and `listadmins()` use the selected file.
Listing reads without creating a missing file. Relative paths, drive-relative
Windows paths, stream URLs, null values and NUL bytes are refused. The writer
does not create missing parent directories or manage links/permissions.

Keep permanent Windows configuration on D:. For example, an operator can select
`D:/Civilizationbot-local/config/civ13/servers/gaia/admins.txt` **only after the
game reader and all game admin writers are configured for that same file**.
This bot option does not redirect a game that still reads `SQL/admins.txt`.
Separate per-server D: files can receive identical desired membership through
the existing updater; two games must not delete/rebuild an uncoordinated shared
canonical snapshot. No entire SQL directory junction is appropriate.

For the supplied Gaia supervisor snapshot, the game directory is
`D:/GitHub/Civ13`, and its existing expected reader path is therefore
`D:/GitHub/Civ13/SQL/admins.txt`. This is a configuration expectation, not proof
that the file exists or the server is running. Do not substitute the old
preparation-only `master-gaia` directory or the temporary C: TDM deployment.

This patch does not enable a server, install an automatic-sync listener, change
verification authority or grant roles. The current read-only verifier omits the
automatic mutation listeners; keep it read-only. A separately reviewed file-only
trigger must have fresh member/role and verification evidence before writing.
An unavailable roster is not an authoritative empty list. Confirm role IDs and
the actual baseline, then review exact additions/removals before enabling sync.
Do not infer Ambassador from a legacy High Staff label or grant Host by default.

Offline checks (explicit dependencies; no bootstrap or environment load):

```powershell
php tests/windows/portable.php
php tests/local/adminlist-files.php D:/GitHub/Civilizationbot/vendor/autoload.php
```

The file test uses fake members, temporary paths and a model of the inspected
DM loader's framing/cleanup. It does not execute DM or connect to Discord.
