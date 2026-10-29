# Cache-driven admin-file synchronization (inactive)

This application-only adapter trusts DiscordPHP's maintained member cache. Member/role events request a new projection of that cache, not a REST fetch. It does not create a polling service, HTTP client or second membership store. No bootstrap registers its listeners and no configuration is enabled by this source slice.

## Reuse existing bot behavior

The existing `GameServer::adminlistUpdate()` uses `ADMIN_PERMISSIONS` precedence, required configured roles and LF-delimited output. `updateFilesFromMemberRoles()` joins `verifier->verified` to `Verifier::getVerifiedMember()`, which resolves the member from the target guild's repository. Preserve that cache lookup and rank policy. The pure `AdminListRenderer` expresses the same join/precedence with selected-output validation; `AdminFileWriter` supplies the existing-file guarded commit boundary.

Do not enable a disabled GameServer merely to call its current admin update method: it has unrelated activation behavior. Do not remove `Verifier::afterConstruct()`'s read-only early return: its legacy member listeners also mutate whitelist/factions and roles. Future integration must register only the dedicated admin trigger and use the explicitly configured `admin_file`, independently of those mutation listeners. `PortableProfile` still accepts one explicit TEST1714 server; this proposal does not invent two-server profile support or alter game-file consumption.

## Cache and supported event contracts

Inspected locked DiscordPHP: `212bed1e0481dab3721795e0926bf133d34a674e`.

- Existing `bot.php` sets `loadAllMembers => true` and `Intents::getAllIntents()`. `init(Discord)` is emitted once after the library's initialization flow; deprecated `ready` is redundant. The adapter accepts the already initialized target-guild cache at init after the injected availability check. There is no extra initial member request.
- `reconnected(Discord)` is emitted both by RESUMED handling and by READY during reconnect. Uppercase READY/RESUMED are internal fallback handlers, not ordinary public dispatch hooks to assume. A reconnect closes the application readiness barrier and invokes the existing supported `members->freshen()` (no arguments, paginates members) and `roles->freshen()` promises once.
- `GuildMemberUpdate` updates its member and calls repository `set()` before `emitDispatchResult()` emits `(newMember, Discord, oldMemberOrNull)`. `set()` updates in-memory items synchronously. `GuildMemberRemove` yields `cachePull()`/delete before emitting removal; the cache wrapper removes the in-memory item on successful deletion. Thus normal event listeners read the updated cache.
- Role create/update/delete handlers likewise update their repositories before the dispatched callback. Role deletion may supply a Role or a raw payload with `guild_id`. Member old-role data is optional; the trigger conservatively requests projection for target-guild member updates/removals rather than suppressing unknown changes.
- GUILDS and privileged GUILD_MEMBERS intents cover these role/member events. Live intent authorization and event settings remain deployment checks, not modifications in this patch.

Source references: [Discord event emission/lifecycle](https://github.com/discord-php/DiscordPHP/blob/212bed1e0481dab3721795e0926bf133d34a674e/src/Discord/Discord.php), [member update](https://github.com/discord-php/DiscordPHP/blob/212bed1e0481dab3721795e0926bf133d34a674e/src/Discord/WebSockets/Events/GuildMemberUpdate.php), [member removal](https://github.com/discord-php/DiscordPHP/blob/212bed1e0481dab3721795e0926bf133d34a674e/src/Discord/WebSockets/Events/GuildMemberRemove.php), [repository cache operations](https://github.com/discord-php/DiscordPHP/blob/212bed1e0481dab3721795e0926bf133d34a674e/src/Discord/Repository/AbstractRepositoryTrait.php), [member refresh](https://github.com/discord-php/DiscordPHP/blob/212bed1e0481dab3721795e0926bf133d34a674e/src/Discord/Repository/Guild/MemberRepository.php).

## Narrow application contracts

`AdminCacheSyncTrigger` owns readiness and an invalidation epoch, not member records. Its stable listener map is returned without registration. Bind before initialization, or explicitly call `bootstrapReady()` only when the application already knows initialization completed. Remove only these exact closures when detaching. `stop()` cancels only its owned timer(s) and fences future projections; configuration changes invalidate the epoch immediately, including while debounce is pending. A failed configuration callback remains fenced until a successful explicit configuration update; reconnect alone cannot approve a failed configuration.

Use a dedicated existing `AdminSyncCoordinator`; all requests for that coordinator must pass through this adapter. Idle events share one bounded debounce window; events during reconciliation immediately advance its dirty generation. The coordinator's member callback reads the current guild cache, tags the value projection with `cache_epoch` and its supplied `generation`, and performs no member fetch. Use the existing verified-record/member lookup instead of trusting an event payload as the entire member list.

`AdminSyncSnapshotGate::renderCached()` accepts trusted current `{ready, epoch}` from the trigger at every projection and precommit. It requires matching captured cache epoch and coordinator generation, valid required guild roles, and a fresh successful verification snapshot. It imposes **no member-cache age/TTL**, and does not fabricate a Gateway-connected attestation. A ready cache with no eligible admins produces the valid empty authoritative file; uninitialized, reconnect-refreshing or refresh-failed state cannot clear it.

The injected reconnect refresh should compose the existing promises, for example `React\Promise\all([$guild->members->freshen(), $guild->roles->freshen()])`, without starting a new authenticated client. During refresh, member/role events are coalesced; successful completion projects the then-current cache. A newer reconnect/configuration transition invalidates an outstanding refresh attempt; its late completion cannot reopen readiness. An actual reconnect received while refreshing records one pending refresh: after the old request drains, exactly one coalesced refresh starts for the newer connection. No parallel refreshes occur. Failure or timeout alone never creates follow-up work, and a timed-out request that never settles keeps the barrier closed. A result deadline/failure leaves the adapter not-ready and preserves the file. It does not cancel the library's underlying HTTP requests, silently retry them, or treat an unresolved refresh as complete. The pinned member refresh has no own deadline, can remain pending after a nested cache failure, and retains its old cache if its initial page is empty; this is an integration limitation, not a reason to replace the normal maintained cache or change libraries here. Reconnection refresh is not an atomic transaction with concurrent events.

## Activation remains separate

No token/environment reads, Discord registration, member refresh, writer invocation, bot startup or game operation occur merely by constructing this source foundation. Required runtime decisions remain: explicit Windows/D: configuration, admin-only enablement, approved role map, exclusive owner, successful initialization, trusted verification source, guarded writer and shared game consumption. A one-time reviewed verification snapshot does not authorize future unauthenticated plain-HTTP verifier changes.

Superseded unpublished preparations are **not part of this cache path**: the new `AdminRestMemberClient`, REST transport tests, per-event completed-pagination provider integration, application membership-age TTL, and the prior REST-based event-trigger packet. Existing unused draft foundations need not be wired to implement cache-driven synchronization.

## Offline test

```sh
php -l src/Civ13/AdminCacheSyncTrigger.php
php -l src/Civ13/AdminSyncSnapshotGate.php
php tests/local/admin-cache-sync.php vendor/autoload.php
```

Existing dependency-installed supervision runs synthetic cache/event tests on PHP 8.3/8.4/8.5. Tests use injected cache state, timers, refresh promises and a memory-only writer—not a live bot or admin file.

## Dirty-work continuation

A coordinator burst still has a finite pass budget. If an already-received event dirties its final pass, the terminal outcome explicitly sets `continuation_required=true`. The cache trigger preserves this one coalesced intent, including synchronous request reentrancy, and services it through the normal debounce only after pending work drains and readiness permits. This is continuation of received work, not periodic polling or an error retry. New requests consume older continuation intent. Stop, reconnect and invalid configuration still fence writes.

Generic writer `superseded`/`blocked`, failed or integrity-uncertain outcomes do not request continuation. `retry_required` alone is not an automatic-retry instruction. A successful continuation does not schedule another unless a new event dirties that burst too; sustained real event traffic is paced by the debounce. The injected one-shot scheduler must defer callbacks until after it returns; a synchronous callback is rejected instead of creating a recursive busy loop.
