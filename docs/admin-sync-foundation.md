# Admin synchronization foundation

`AdminListRenderer`, `AdminSyncSnapshotGate` and `AdminSyncCoordinator` provide an offline-testable foundation for authoritative admin-file synchronization. They are **not wired into production**: no production membership or file adapter is wired, and no Discord listener, HTTP client, configuration switch or game integration is supplied. The injected membership provider and the explicit existing-file writer are separately testable foundations, not activated services. Adding these classes does not activate synchronization or change the portable profile's single-server restriction.

## Projection and snapshot gate

Pass the existing ordered `GameServer::ADMIN_PERMISSIONS` map to the renderer. The first matching role wins. Records use literal `|||\n` framing and deterministic ckey ordering. Selected admin ckeys must be canonical lowercase alphanumeric strings; an unsafe selected identity rejects the whole projection rather than silently removing it. The existing hosted identity normalizer still permits unrelated legacy spellings.

The gate rejects unavailable, stale, pending or mismatched verification data, missing required guild roles, incomplete/disconnected membership and obsolete generations. A valid, complete snapshot with no authorized admins returns an empty string: this is authoritative revocation, not failure. Unavailable or incomplete empty data must never be interpreted as revocation.

The caller must establish complete membership and pair verification status with its records. Status fields are attestations, not proof of source authentication, integrity or authority. These classes do not resolve source-authentication or trust-policy requirements.

## Injected coordinator contract

The coordinator accepts an explicit immutable enabled flag and four callbacks; none has a default runtime implementation:

- `refresh()` returns `{status, records}` or a promise for that snapshot.
- `members(generation)` returns the complete guild snapshot or a promise for it.
- `project(snapshot, membership, generation)` synchronously returns bytes after all policy and snapshot checks. It must be pure and use the current clock on **every** call, including precommit checks. `AdminSyncSnapshotGate` supplies the validation boundary, not the membership-fetch mechanism.
- `write(bytes, generation, canCommit)` returns `{state: updated|unchanged|superseded|blocked}` or a promise. Immediately before mutation, check `canCommit()` and perform **no asynchronous wait between that check and mutation**. If false, return `superseded` without changing the target. `unchanged` means the target was actually checked, not that work was skipped. `blocked` is reserved for a known pre-mutation refusal, such as lock contention; it must not conceal a possible partial write.

`canCommit()` checks the current generation, reruns projection to check freshness, requires identical bytes and checks generation again. A writer must honor this contract; the coordinator cannot prevent a dishonest callback from writing or falsely claiming success.

One pipeline runs at a time. Requests during a burst invalidate older work and share its outcome promise. Dirty work gets another pass, up to `maxPasses` (default 3; range 1–10). Exhaustion returns `superseded` with `retry_required=true`; it does not install a timer or automatically start another burst. The owner must arrange another request where appropriate.

The pass limit bounds concurrency and retries, **not wall time**. Each asynchronous adapter must enforce its own deadline. A forever-pending adapter stays pending; the coordinator does not start overlapping work or claim cancellation.

## Outcomes and failure handling

Results include `state`, `phase`, `generation`, `passes`, `writes`, `last_written_generation`, `retry_required` and `integrity_uncertain`.

- Disabled performs zero adapter calls and returns `disabled`, not success.
- Refresh/member failures and rejected projections return `blocked`. Synchronous exceptions and rejected promises are handled; a resolved unavailable refresh status is not success.
- Successful empty projection is passed to the writer as empty bytes.
- Delayed refresh/member results from an older generation cannot reach later stages. Events during a writer wait for it to settle before another pass.
- Writer exceptions, rejections or malformed outcomes return `failed` with `integrity_uncertain=true`. They are not silently retried, even if a newer request arrived.
- A confirmed earlier write remains counted if a later pass blocks. The final state describes the latest pass, not a rollback of earlier effects. No filesystem atomicity or partial-write recovery guarantee is made.

Before integration, define trusted source acquisition, complete membership, reconnect invalidation, approved guild/role configuration, deadline handling, writer serialization/recovery, observability and game-consumption behavior. Retain separate activation controls; do not enable a game server merely to enable file synchronization.

## Offline tests

Use PHP 8.3 or newer at the repository root. The projection test needs no Composer packages. The coordinator test uses the existing Composer autoload for React promises and injects all providers/writers; its writes are in-memory only.

```sh
php -l src/Civ13/AdminListRenderer.php
php -l src/Civ13/AdminSyncSnapshotGate.php
php -l src/Civ13/AdminSyncCoordinator.php
php -l tests/local/admin-sync-projection.php
php -l tests/local/admin-sync-coordinator.php
php tests/local/admin-sync-projection.php
php tests/local/admin-sync-coordinator.php vendor/autoload.php
```

Fixtures cover valid empty versus unavailable/incomplete snapshots, rank precedence, unsafe selected identities, shared promises, generation invalidation, freshness at delayed commit, bounded churn, failed writers, earlier writes followed by a blocked pass, and reentrant subscribers. No bot bootstrap, `.env`, live identities, game process or real admin file is used.

The existing offline workflow runs projection on Ubuntu/Windows PHP 8.3/8.4 and coordinator tests in the dependency-installed Ubuntu PHP 8.3/8.4/8.5 supervision job. Workflow coverage is not evidence that a production provider or writer has been implemented or verified.

The dedicated existing-file filesystem adapter and its platform limits are described in [Admin file writer](admin-file-writer.md). The legacy `Server/Ipc` writer is unchanged.
