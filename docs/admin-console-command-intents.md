# Inert command preparation

`CommandIntentPreparer` is not an endpoint or execution adapter. It accepts a
trusted list of target IDs and an injected action-specific role-cache policy.
The actor is supplied only by a future independently authenticated session
boundary. No request header/IP/DTO grants identity. A policy may not reuse the
read-status grant as permission to restart.

The only supported intent is `restart`, corresponding to the existing fixed
Local/ControlEndpoint action name. This does not connect to that endpoint or
enable native/loopback controls. Save-only and arbitrary verbs are unsupported;
the existing save_shutdown lifecycle operation must not be misrepresented as
save-only. Future verbs require an explicit typed verb/argument allowlist.

Input has exactly `target_id`, `action`, `request_id`. Request IDs are canonical
lowercase UUIDv4 values generated once by a future caller per logical intent.
Unknown/unauthorized targets yield the same denial. Missing/unready policy fails
closed. Every preparation, including repeated request IDs, rechecks policy.

The readonly value validates shape, not authorization. Its output explicitly says
`prepared_not_executable` and `execution_enabled=false`; anyone constructing or
copying it gains no authority. It contains no URL, bearer, role list or raw actor.
The actor-scoped request hash is stable across changed targets; the separate
payload hash binds schema, actor, target and fixed action. These are deterministic
comparison keys, NOT secrets or signed grants.

**No deduplication is implemented yet.** A future durable ledger must atomically
reserve the request key, reject changed fingerprints as conflicts, and preserve
one operation identity/results across retries and crashes. Hashes alone cannot
prevent replay or concurrent execution. No queued/accepted/completed receipt is
returned. HTTP200 here means only that an in-memory value was prepared.

Before any dispatch: independent session/action-policy recheck; trusted current
target/process generation; observation freshness/readiness; explicit confirmation
and CSRF protections at the real session boundary; atomic operation admission;
and durable audit/history are still required. Never dispatch from this DTO alone.
Save/restart completion must rely on authoritative operation-bound save/process
receipts, not transport200, queueing, timeout or an empty player count. Unknown
outcomes require reconciliation rather than automatic resubmission.

Offline verification: `php tests/admin-console/command-intent.php` (no dependencies,
bot bootstrap, Discord, collector, filesystem/process effect or network). Existing
PHP8.3/8.4/8.5 Ubuntu/Windows matrix runs lint and these contracts. No runtime
registration, live configuration, new dependencies or enabled UI controls.
