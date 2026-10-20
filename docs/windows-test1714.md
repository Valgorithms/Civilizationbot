# Windows TEST1714 startup (stable branch)

This profile is an explicit opt-in to the stable bootstrap. It does not import V5,
install a service, run a game server, or share the Debian bot environment file.
Windows refuses the ambient production bootstrap without a profile. Linux without
CIVILIZATIONBOT_PROFILE retains its existing behavior.

Install PHP 8.3+ CLI and Composer dependencies in this checkout with Composer's
platform checks enabled. Required extensions: json, mbstring, openssl, filter,
zlib, curl. Use a 64-bit PHP build. Optional event-loop accelerators are not
required. The portable preflight checks the PHP 8.3 floor before loading dependencies.
Do not copy an official token, environment file, verification store or runtime data.
A separate application/token must be provisioned directly by its operator.

Copy config/examples/windows-test1714.json to an operator-owned absolute path and
edit that copy. Set your own civ13_guild_id, owner_id and technician_id explicitly;
empty identity strings disable those identity matches and never inherit production
owner/technician IDs. owner_id must match the target guild owner's Discord ID for
message commands. The bot must already belong to the configured guild, with the
required gateway intents enabled for its separate application. Missing optional
verifier-status channels are ignored; a missing guild skips guild command
registration with a warning. Create the configured state directory and game directory first.
root must match this checkout; basedir must be the intended PC TEST1714 runtime
root (SQL files are relative to it). The game remains independently operated.
The sample server is disabled and destination maps empty intentionally. Before
activation, supply reviewed channel/role IDs and confirm both bot identities and
Administrator bypass: channel denies cannot isolate an Administrator. No broad
guild permission changes are implied. Keep the local bot unable to view official
channels and the official bot unable to view local channels.

From PowerShell, with explicit executable and profile paths:

```powershell
C:/php/php.exe scripts/windows/control.php check D:/Civilizationbot-local/profile.json
# Only after the separate activation gate:
C:/php/php.exe scripts/windows/control.php start D:/Civilizationbot-local/profile.json
# From another terminal, requests graceful shutdown of this profile only:
C:/php/php.exe scripts/windows/control.php stop D:/Civilizationbot-local/profile.json
```

check reads profile metadata and file presence only, never the environment contents
or Discord. start runs one shell-free foreground PHP child; the bot holds an
exclusive state lock. A duplicate launch cannot enter the bot bootstrap after
that lock. stop writes a one-use marker consumed by the owning loop, not a process
name kill. A stop marker left by an absent/crashed process requires operator
inspection before restart. A stop request is not evidence that a process exited.
Only WEBAPI_TOKEN and CIV_TOKEN explicitly present in the separate environment file
are used; inherited values are ignored. Cache files are pinned under state/json.
The check/start/stop tools reject a profile belonging to a different checkout.

Logs append to state/output.log, stdout.log and stderr.log. Protect this directory
with operator-only filesystem permissions and apply an external retention policy.
Restart commands exit gracefully in portable mode; the operator relaunches the
foreground supervisor. No self-spawning sudo/nohup path is used.

Portable construction skips public-IP discovery, so startup does not depend on
ipecho.net or grant that discovered address local trust. Linux defaults are unchanged.
The HTTP listener binds 127.0.0.1 on the explicit profile port (distinct from1714).
Portable mode removes ambient callback functions, official verifier server
construction and the official verification URL. Verification integration remains
unavailable until a separate reviewed authoritative read-only contract is supplied.
Native Host/Kill/Restart/MapSwap fail closed in portable/Windows mode before game
messages or timers. This change supplies no game lifecycle replacement.

Legacy shell maintenance is also unavailable in Windows/portable mode: chat
updatedeps/pullrepo/ts/ss14 and slash pull/update report unavailable rather than
claiming success; the staff panel omits its Git/Composer buttons. Legacy HTTP
/reset, /githubupdated, /pull, /update, /restart, /kill, /clearenv and
/cancelupdaterestart return 503 before any maintenance side effects. Use the
explicit foreground supervisor and stop marker for local bot lifecycle. The shared OS
helpers reject shell spawn, self-restart, process disown and port-based killing
before touching processes. This does not restrict the explicit owned argv-based
PHP supervisor or the separately configured local OpenDream adapter. Do code and
dependency maintenance manually while the bot is stopped.

## Offline verification and activation boundary

`php tests/windows/portable.php` uses temporary storage, synthetic environment
contents and an injected FakeProcess. It checks destination/path/port rejection,
dependency reporting, exclusive locking, stop replay, shell-free argv and actual
GameServer entry-point rejection before uninitialized game/Discord dependencies.
It never loads bot.php, connects Discord or launches a child process.

`php tests/windows/constructor.php [ABSOLUTE_VENDOR_AUTOLOAD]` additionally runs
actual Civ13 construction through afterConstruct with an injected Discord event
emitter, non-running loop, temporary state and disabled DNS connector. It verifies
slash listeners omit unavailable SS14 verification and the registered read route
returns 503, never a successful empty list. It does not invoke the credential-
loading test bootstrap or start an event loop. CI installs dependencies with
Composer plugins/scripts disabled before running this contract.

`php tests/windows/process.php` launches a real synthetic PHP child, checks shell
metacharacters survive as literal arguments, paths containing spaces, separate
stdout/stderr logs, cross-process lock exclusion, graceful stop marker consumption
and exit-code propagation. It cannot launch the bot or a game. CI runs these
checks on Windows and Linux with PHP 8.3, 8.4 and 8.5.

`php tests/windows/shell-guard.php` installs process-function tripwires and uses
synthetic messages to prove unsupported maintenance rejects before any shell,
process inspection, timer-based restart or misleading success reaction.

`php tests/windows/ready.php` emits init and fires the delayed bootstrap with a
controlled loop and synthetic Discord command repositories. It exercises real
Slash/Panels setup without connecting to Discord, and rejects network attempts.
The --unconfigured variant covers empty identities/channels and an absent guild;
both variants verify unsupported slash maintenance responds without adding timers.

These tests do not establish live startup, gateway intents, command registration,
permissions, TEST1714 file IPC, process binding, or delivery. Before an authorized
activation: review the exact candidate/CI, provision separate credentials and
private destinations directly, verify runtime root/ports and channel isolation,
then obtain the operational coordinator's explicit launch/registration/test gate.
Debian deployment, game save/load/restart, and official token reuse remain outside
this change.
