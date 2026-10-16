# Windows TEST1714 startup (stable branch)

This profile is an explicit opt-in to the stable bootstrap. It does not import V5,
install a service, run a game server, or share the Debian bot environment file.
Windows refuses the ambient production bootstrap without a profile. Linux without
CIVILIZATIONBOT_PROFILE retains its existing behavior.

Install PHP 8.3+ CLI and Composer dependencies in this checkout with Composer's
platform checks enabled. Required extensions: json, mbstring, openssl, filter,
zlib, curl. Optional event-loop accelerators are not required. The existing
composer.json PHP floor is lower than the typed class constants used by the code;
the portable preflight reports the actual 8.3 floor before loading dependencies.
Do not copy an official token, environment file, verification store or runtime data.
A separate application/token must be provisioned directly by its operator.

Copy config/examples/windows-test1714.json to an operator-owned absolute path and
edit that copy. Create the configured state directory and game directory first.
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
Logs append to state/output.log, stdout.log and stderr.log. Protect this directory
with operator-only filesystem permissions and apply an external retention policy.
Restart commands exit gracefully in portable mode; the operator relaunches the
foreground supervisor. No self-spawning sudo/nohup path is used.

The HTTP listener binds 127.0.0.1 on the explicit profile port (distinct from1714).
Portable mode removes ambient callback functions, official verifier server
construction and the official verification URL. Verification integration remains
unavailable until a separate reviewed authoritative read-only contract is supplied.
Native Host/Kill/Restart/MapSwap fail closed in portable/Windows mode before game
messages or timers. This change supplies no game lifecycle replacement.

## Offline verification and activation boundary

`php tests/windows/portable.php` uses temporary storage, synthetic environment
contents and an injected FakeProcess. It checks destination/path/port rejection,
dependency reporting, exclusive locking, stop replay, shell-free argv and actual
GameServer entry-point rejection before uninitialized game/Discord dependencies.
It never loads bot.php, connects Discord or launches a child process.

These tests do not establish live startup, gateway intents, command registration,
permissions, TEST1714 file IPC, process binding, or delivery. Before an authorized
activation: review the exact candidate/CI, provision separate credentials and
private destinations directly, verify runtime root/ports and channel isolation,
then obtain the operational coordinator's explicit launch/registration/test gate.
Debian deployment, game save/load/restart, and official token reuse remain outside
this change.
