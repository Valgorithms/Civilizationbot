# Launcher control parity: opt-in phase 1

This is a **draft integration**, not a live rollout or a claim of full launcher parity.
Nothing changes until an operator configures it and deploys the bot. No credentials,
permissions, firewall rules, host services or worlds are changed by installing this code.

## Architecture and deployment boundary

The bot calls the **existing local** `scripts/shard-group/shard_group.py` through
`AsyncProcess` with eight fixed argv entries, an operator-configured absolute interpreter,
script and group configuration, and one fixed region ID. It does not expose an HTTP
control endpoint, remote shell, arbitrary arguments, file editor or log download.
The bot must already run on a machine where these trusted controller paths and host
resources are available. A cloud bot cannot reach a Windows launcher simply because
this class exists. Remote deployment requires a separately reviewed authenticated
host-agent transport; do not mount privileged paths or add public listeners to improvise it.

Legacy `gaia_host`, systemd, OpenDream and native supervisor paths are unchanged. This
new facade supports **shard_group regions only**. Existing read-only hoststatus remains.

## Explicit configuration (operator action, not performed by this PR)

Add `management` inside the existing server's `shard_group` configuration. Example:

```php
'management' => [
    'enabled' => true,
    'guild_id' => 'YOUR_GUILD_ID',
    'channel_ids' => ['PRIVATE_OPERATOR_CHANNEL_ID'],
    'operator_ids' => ['EXPLICIT_OPERATOR_USER_ID'],
    'actions' => ['status', 'stage', 'freeze', 'thaw'],
],
```

Replace placeholders with numeric snowflake strings. All lists must be nonempty;
capabilities not listed are denied. Do not enable all actions by default. Operators
also need the existing Ambassador command-router authorization. Discord Administrator
alone does not bypass the explicit user/guild/channel allowlists. Use a private channel:
confirmation prompts and server status are visible to its members. Bots, webhooks and
DMs are denied. No live role or permission grants are part of this work.

Enabling this policy disables the legacy `host`, `kill`, `restart` and `update` lifecycle
methods for that server, including slash callers, **even if the new policy is invalid**.
Use the confirmed management command instead. This intentionally prevents legacy
mutations from bypassing the new ACL or confirmation. Correct invalid configuration
locally; do not fall back silently. Other existing game administration commands are
outside this lifecycle facade and retain their existing permissions.

## Commands and results

Use the normal bot prefix plus `<server-key>manage help` (for example `pers0manage`).
The server key selects the configured region; no command accepts a region override.

- `<key>manage status`: sanitized state, player count, busy flag, lifecycle phase,
  separate staging phase and pending status. Unrecognized values are `unknown`.
- `<key>manage ACTION`: returns an impact warning and 60-second one-use nonce.
- `<key>manage confirm ACTION NONCE`: execute exactly that previewed action.
- `<key>manage cancel`: discard the pending preview, not an already submitted operation.

Confirmation binds to the actor, guild, channel, action and configured region. A new
preview supersedes that actor's old preview in that channel. Authorization is checked
again at execution; the nonce is consumed before dispatch. There is no auto-retry.

| Action | Meaning and evidence |
|---|---|
| status | Sanitized local controller report, not gameplay proof |
| start / restart | May fetch and compile a new revision before starting; accepted is not ready |
| stop | Save and stop; acceptance is not completion |
| update | Existing host update pipeline; may install/build and disrupt players |
| stage | Build staged revision; may auto-install while the world is idle/asleep |
| handover | Existing staged-build handover; older builds may save/stop/start instead |
| freeze | Existing host eligibility checks must pass; report indicates process suspension |
| thaw | Resume suspended process, or accepted cold start from save; not always synchronous |
| kill | Force stop without saving; unsaved progress can be lost |
| force-restart | Force stop then ordinary start from last completed save; receipt action is `start` |

Raw config, logs, paths and error strings are never sent by this facade. Unknown or
malformed responses cannot become success. Refusals can follow partial effects, so
refused mutations and transport/protocol errors block subsequent mutations for this
manager instance. Read-only status remains possible after the in-flight worker ends.
Inspect the local host operation receipt and actual state before restarting the bot to
clear this in-memory latch. Restart is **not** evidence that a partial operation was
reversed. The latch does not survive process restart; confirmations do not either.

Controller locks remain the authority for other launcher/host clients. This facade
serializes only its own calls. Do not configure duplicate server entries for the same
region or run multiple bot controllers with overlapping authority. Durable shared
operation IDs and host-enforced replay protection are prerequisites for multi-controller
or remote-agent support, not implemented here. Existing worker limits remain 4096 bytes
of output and 60 seconds (210 for force-restart). Large group-derived replies/timeouts
produce an unknown outcome, never a retry or a success claim.

## Remaining parity and dependency order

Inventory from Civ13 launcher `LocalHosting.cs` and host controller source, including
fresh-start draft #4412. Bot baseline already had status/start/stop/restart/update/kill.
This phase deliberately does **not** mirror everything as unrestricted commands.

1. **Bounded host operation protocol:** durable IDs, per-region concise receipts,
   replay protection, shared exclusion and safe recovery; necessary before remote
   agents or group-wide control. Extend tests to real Windows/Python host execution.
2. **Player Help, check-update:** typed receipts and exact scope. Current help-start
   may start other missing bridges even when a region is selected; do not pretend it
   is region-only. Check-update fetches shared remote refs, so label that effect.
3. **Fresh world/reset:** preserve PR #4412's recoverable archive, character records,
   stopped-region and connected-transfer-group guards. Its current shard wrapper
   emits `accepted` without the normal `ok` envelope; fix/version that contract first.
   Require exact named world/reset scope, fresh confirmation and audited restore
   evidence. No delete/reset action is accepted by this phase.
4. **Group epoch/all-region controls:** separate group authority, explicit affected
   region inventory and partial-success receipts; epoch range and transfer consistency
   must be enforced host-side. No implicit escalation from a region operator.
5. **Setup/config-apply/deploy/host-service restart:** distinct capabilities with
   bounded background job receipts. These can affect topology/configuration or the
   whole host; never map Discord text to `cmd.exe` or generic batch invocation.
6. **Recovery/unlock/force-unlock:** separate guarded workflow. Removing a live lock
   can corrupt a save. Not enabled in this phase.
7. **Settings and hub names:** typed nonsecret schema rather than arbitrary file
   editing. Launcher settings enumerate config text/JSON and only mask known keys;
   cloning that editor into Discord could disclose secrets. Security/authentication
   changes need their own approval, not general lifecycle permission.
8. **Desktop-only controls:** local BYOND sign-in/install, game client launch and
   window navigation remain desktop actions, not server-management parity promises.

## Validation

`php tests/local/launcher-management.php` checks ACL denial, region-bound argv, exact
response contracts, all action previews, actor/channel binding, expiry, cancellation,
replay rejection, malformed/refused responses, output redaction, concurrency and
uncertain-outcome blocking with injected executors. No Discord login, host action,
server restart, native game test, deployment or save/reset is performed. Linux PHP
8.4 is local validation; Windows and the PHP version matrix need CI evidence.
