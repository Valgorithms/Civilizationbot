# Native Windows supervisor adapter (disabled by default)

This adds a bot-side adapter and a versioned supervisor spool contract. It does
**not** add a game supervisor consumer, upgrade an installed supervisor, install
dependencies, provision credentials, launch a game or remove a halt latch.
The installed Desktop supervisor does not implement this protocol. Keep the
adapter disabled until a separately reviewed compatible consumer exists.

The native bot entry points retain their default rejection. An explicitly enabled
`servers.<key>.native_lifecycle` object in a portable profile allows `Host` to
request `start`, `Kill` to request **save-stop**, and `Restart` to request
`restart`. The server must also be explicitly enabled and bound to loopback
TEST1714. Native MapSwap and all legacy shell maintenance remain disabled.
Linux without a portable profile retains its existing routing.

The adapter does not execute Python, DreamDaemon, arbitrary argv, shell text,
process-name kills or port-owner termination. It submits fixed private files to
an independently operated supervisor. Discord arguments never select paths,
commands, revisions, maps, saves or PIDs. Existing Discord command authorization
is unchanged; no registration or live interaction is performed by this change.

## Explicit profile binding

The existing `config/examples/windows-test1714.json` retains
`"native_lifecycle": {"enabled": false}`. The separate
`config/examples/windows-native-lifecycle.json` shows the complete object an
operator may later place under that key. Its placeholders deliberately fail
validation if enabled without verified values. Do not reuse another bot's token
or environment. No credentials belong in this object or in spool records.

Activation requires all of the following:

- A separate portable bot profile, private destinations and environment file,
  and dependencies in the intended durable bot installation.
- An existing private `<supervisor_root>/bot-control` directory, with no linked
  paths and local access restricted by the operator. This PR changes no ACLs.
- Reviewed SHA-256 pins for the installed `software/supervisor.py`,
  `software/master_guard.py`, `supervisor.json`, daemon and exact DMB/RSC pair.
- A clean dedicated `master` repository selected by portable `gitdir`, canonical
  Civ13 origin, exact master revision, map and completed saved generation.
- An explicit server `basedir` inside `<supervisor_root>/runs` that is also the
  actual game working directory. The consumer must preserve that binding for
  this operation; a different run directory is rejected. Moving normal file IPC
  to a new run requires a separate profile/restart review because GameServer's
  existing resolved paths are readonly.
- Supervisor config fields `root`, `repository`, `branch: "master"`,
  `bind: "127.0.0.1"`, `port: 1714`, the exact `map` and `daemon`, and
  `bot_control_protocol: "civilizationbot.native-supervisor.v1"`.

Changing a pinned source/config/build requires explicit profile review and bot
restart. The adapter never updates a checkout, compiles, selects an old save,
substitutes a fresh round or silently relaxes save validation.

## Supervisor consumer contract

The trusted consumer must be reviewed with the pinned source; advertising a
capability is not an independent audit of that source. The protocol is a local
operator boundary, not a sandbox against another process with write access to
the private directory. A consumer must serialize with all its existing lifecycle
operators, including scheduled/manual updates. It must **not** consume these
requests without the same ownership and save safety guarantees.

The adapter's `description()` returns the complete `binding` and its SHA-256
(UTF-8 JSON, PHP JSON_UNESCAPED_SLASHES, field order as returned). The consumer
publishes `capability.json` atomically with:

```json
{
  "protocol": "civilizationbot.native-supervisor.v1",
  "binding_sha256": "64 lowercase hex characters",
  "heartbeat_unix": 0,
  "epoch": "32 lowercase hex characters",
  "sequence": 1,
  "master_verified": true,
  "busy": false,
  "process": null
}
```

The heartbeat must be within ten seconds, not more than two seconds in the
future. `epoch` identifies one reconciled supervisor session; `sequence` must
advance after each operation. `process: null` asserts that the supervisor has
established a stopped state with no conflicting owner/listener. A running
process record instead contains `pid` (positive integer), `creation_filetime`
(decimal string), canonical `executable` and `working_directory`, and the bound
`dmb_sha256`/`rsc_sha256`. The consumer must obtain these from the actual process,
not a port label, filename, stale PID or intended command line.

The adapter briefly holds a nonblocking exclusive `adapter.lock`, persists `pending.json`,
then publishes `request.json` using an exclusive hard link to a flushed temporary
file. A filesystem without the required hard-link/fsync behavior fails closed.
The packet contains only protocol, random operation ID, fixed action,
epoch/sequence, binding hash, complete binding, and the observed `before` process.
An existing request/pending record or consumed epoch/sequence refuses submission.
The publication lock is released before waiting; completion reacquires it only
for its short verification/acknowledgement transition. A separate process can
inspect or reconcile state while an operation is pending, but must not replay it.

On accepting an operation the consumer must:

1. Revalidate all pins, protocol, epoch/sequence, expected master/map/save and
   actual ownership under its own lifecycle lock. Fetch canonical origin/master,
   require clean branch master equal to that verified ref, and fail closed when
   offline, stale, dirty or diverged. Never reset, force checkout or stash.
2. For start, refuse an existing/conflicting daemon and verify the selected
   completed generation and import intent before startup. For save-stop/restart,
   match PID plus creation time, executable, working directory and binary hashes;
   refuse busy/error/client/hold conditions. Use only the authenticated existing
   save/shutdown API. A lost reply is unknown, not permission to retry or kill.
3. Validate the completed save and acknowledged exit before restart. Start only
   the bound binary with loopback binding, then verify actual ownership and
   application readiness/imported generation. TCP-only success is insufficient.
4. Persist a single immutable `result-<operation>.json` atomically, consume the
   request exactly once, and advance its capability sequence. Recovery must
   reconcile durable pending/result/state rather than replay a request.

Completion results must echo protocol, operation, action, epoch, sequence,
binding hash and exact `before`; set `outcome: "complete"`,
`master_verified: true`, `ready` (false for save-stop, true otherwise), and
`after` (null only for save-stop). A running `after` uses the process schema
above. Restart must establish a different creation identity. Save-stop/restart
also require `exit_acknowledged: true`, `save_complete: true`, a valid
`saved_generation` and `save_receipt_sha256`. Start/restart additionally return
the matching `restored_generation`.

The bot accepts these as a pinned supervisor's report, not independent native
acceptance. Real process/socket, actual save receipt, logs, imported world and
readiness evidence remain required before deployment is considered verified.

The bot polls without blocking its event loop. Queueing is never reported as
success. A ten-minute bot timeout does **not** kill the supervisor or daemon.
Timeout, malformed/unbound response, failed/incomplete save, changed pins, or bot
restart leaves the pending record intact and blocks another request. Successful
completion retains the result plus a consumed epoch/sequence marker before
clearing only the exact pending record. No automatic retry or recovery clearing
is provided; an operator must reconcile evidence before changing those records.
Longer game operations can finish independently after the bot timeout.
If save-stop/restart advances the generation, a durable marker blocks subsequent
operations using the original binding. The operator must review the newly saved
generation, update the explicit profile and supervisor capability binding, and
restart the bot before a later start can proceed. Old results and reconciliation
markers remain evidence; the adapter never selects the original save again.

## Current Desktop launch blockers

The discovered installation still hardcodes Europe while the preserved current
save is Gaia; its configured initial save is older Europe. The Gaia generation
lacks a restart intent, and HALTED.json remains present. The old supervisor also
lacks this spool consumer and explicit loopback bind contract. None is repaired
or bypassed by enabling a flag or installing Composer dependencies. The actual
master binary has not been rebuilt/refreshed. Keep launch disabled while the
supervisor contract, exact build, matching save/intent and operator profile are
resolved and validated under the existing native allocator.

## Offline checks

`php tests/windows/native-lifecycle.php [EXPLICIT_VENDOR_AUTOLOAD]` uses inert
temporary files, an injected clock, fake loop, supervisor receipts and message
objects. It covers fixed routing, default rejection, pins, bad capability,
serialization, stale capability replay, process identity, save/exit/import
controls, malformed/unknown outcomes, interrupted publication and bot restart.
Two real inert PHP children additionally compete for one temporary spool; only
one may publish. No supervisor, game, bot
bootstrap, credential load or network runs. Existing portable constructor,
shell-guard, ready and real inert PHP child contracts remain separate checks.
