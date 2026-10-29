# Portable admin-file composition — default disabled

`AdminFileSync` composes the existing read-only verifier, maintained Discord member cache, cache-event trigger, coordinator, pure renderer/gate and guarded existing-file writer. It introduces no client, transport, polling service, credential discovery, second GameServer or game activation. `local-bot.php` and its isolated replica boundary are unchanged.

The owner selected continued use of the existing configured HTTP verification feed for this task. This is a source-policy choice, not cryptographic authentication or live activation approval. ReactPHP supports HTTPS; the current endpoint is configured as HTTP. No signing, TLS changes, trust flags or extra validator framework are introduced.

## Opt-in configuration boundary

An omitted top-level `admin_file_sync` block maps to `enabled=false`, overriding any same-named value smuggled through the generic `options` array. The disabled block accepts only `enabled: false`. The proposed enabled schema requires exactly:

- `enabled`: true;
- `max_bytes`: integer 1–16,777,216;
- `debounce_seconds`: finite number greater than zero, at most 5;
- `refresh_timeout_seconds`: finite number greater than zero, at most 300.

Enabling also requires the existing explicit hosted read-only verification configuration, target guild, nonempty role map, and exactly one server with an explicit absolute `admin_file`. The runtime destination is bound to **that existing server setting**, not a second path in this block. A shared pure configuration validator checks every `GameServer::ADMIN_PERMISSIONS` role mapping and distinct ID. Portable `bot.php` invokes it immediately after Composer autoload, before loading environment credentials or constructing Discord; attachment reuses the same validator. Existing TEST1714/single-server restrictions are unchanged. No server `enabled` flag is changed or required merely for file synchronization. The required `options.role_ids` keys are `Owner`, `Chief Technical Officer`, `Host`, `Head Admin`, `Ambassador` and `Admin`, each mapped to a distinct actual Discord role ID. `options.civ13_guild_id` must identify the intended guild. Keep `verification_source` as `{mode: "read-only", url: "http://civ13.com:55555/verified"}`; `runtimeOptions()` derives `verification_read_only=true` from it. Current IDs, limits and destination must be reviewed rather than copied from synthetic fixtures.

This schema preparation does not approve an actual path or role map, approve live activation, or establish transport authentication. No live profile file is changed. The intended canonical admin file must already exist and be bot-owned under the approved D: configuration; the writer does not create files or directories.

## Existing behavior reused

`Verifier::afterConstruct()` retains its read-only early return, so whitelist/faction/role/thread mutation listeners are not enabled. `GameServer::adminlistUpdate()` and its callers remain unchanged. The new composition reuses their verified-record-to-`getVerifiedMember()` cache lookup and `ADMIN_PERMISSIONS` precedence through `AdminListRenderer`, without activating a GameServer or invoking the legacy method's `touch()`.

Attachment occurs in Civ13's existing init callback, after the earlier read-only Verifier initialization callback assigns the verifier. Runtime attachment failure logs this feature as inactive and leaves its service unset without aborting unrelated bot initialization; fatal configuration mistakes are rejected by the earlier preflight. The existing `ready` flag supplies the explicit late-ready bootstrap signal: listeners added after init do not wait for another initial event. Normal role/member events use the updated maintained cache. Reconnect alone composes the existing member/role repository `freshen()` calls behind the cache trigger's readiness/deadline barrier. The aggregate waits for both calls to settle before reporting failure, so one early rejection cannot release the trigger's drain gate while the other request still runs. No membership TTL or per-event member request is added.

Verification status and records are copied together, checking status consistency and record count. Before projection and under the writer's precommit guard, the exact record array and success timestamp must still match; same-count identity replacement is not accepted. Verifier object, target guild and role configuration changes fence work. Configuration changes require stop and explicit recreation, not silent rebinding. Existing HostedVerificationSource normalization and ambiguity checks remain in use, along with selected-output validation, verification freshness and cache/epoch guards. These checks do not authenticate HTTP transport.

The service stores its exact listener closures. Stop fences commits first, then removes only those listeners and cancels owned trigger timers. Core bot shutdown continues if listener cleanup fails; no cleanup exception is allowed to escape the added destructor path. Existing client, other listeners, game instances and shared IPC behavior are not replaced.

Optional synchronization notification failures are isolated after successful verification-cache replacement. They emit a fixed feature warning without exception details; even failure of that warning logger cannot reject the otherwise successful core refresh or suppress its ordinary status log. Actual source failures retain their existing unavailable status and do not invoke this notification or retry.

The already-existing verifier refresh completion also notifies the optional composition after a successful non-coalesced cache replacement, including unchanged identities: the new success timestamp can invalidate an older pending write. Failed and coalesced refresh completions do not schedule new work. This application-owned hook reuses the existing debounce and immediately invalidates a pending stale write; its next snapshot acquisition consumes the already-replaced verification cache without calling `refreshHosted()` again. When the coordinator is already acquiring that verifier refresh, it consumes the completion itself and the notification does not dirty another pass. No polling interval, HTTP request, DiscordPHP change or second identity/member store is introduced. Disabled/unattached, retired-verifier and stopped owners remain inert. Required-role deletion policy is unchanged.

## Existing source policy

The composition uses `Verifier::refreshHosted()` and `hostedStatus()` with the explicitly configured read-only endpoint `http://civ13.com:55555/verified`. It does not fetch public verifier data during offline tests, change endpoint configuration, mutate verification records remotely, or remove the verifier's read-only guard. The owner's decision to retain this feed does not authorize starting the bot, enabling file writes or changing local configuration. Future source replacement remains an explicit configuration/review task rather than silent discovery.

## Shared game consumption is separate

Writing the single canonical file does not prove Nomads and TDM consume it. Inspected Civ13 `code/modules/admin/admin_ranks.dm` hardcodes `SQL/admins.txt` and deletes/recreates it during `load_admins()`. One physical shared read-only input would need separately reviewed consumer configuration/code and lifecycle behavior. However, one authoritative list can also mean identical validated bytes written to separate explicit existing Gaia/TDM admin files, without changing the loader. That future multi-target design must report partial write outcomes; actual TDM destination and reload behavior remain to be confirmed. Neither alternative is needed to complete this single-target file composition. This slice makes no multi-target, game-loader, hardlink, junction, TDM or runtime changes.

## Offline checks

```sh
php tests/local/admin-file-sync-profile.php
php tests/local/admin-file-sync.php vendor/autoload.php
php tests/local/admin-verification-refresh.php vendor/autoload.php
```

The first test uses synthetic arrays only and runs in the existing Ubuntu/Windows PHP 8.3/8.4 replica matrix. The composition test uses an inert application/cache/emitter, injected clock and memory-only writer in the dependency-installed supervision PHP 8.3/8.4/8.5 matrix. No test loads bot.php, credentials, live Discord, actual admin files or game servers.
