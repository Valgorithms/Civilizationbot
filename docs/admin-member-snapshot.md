# Injected admin membership snapshot provider

`AdminMemberSnapshotProvider` collects bounded, completed pagination into a private snapshot. It is not an atomic Discord snapshot and has no default HTTP client, Discord client, lifecycle observer or runtime wiring. Constructing this foundation does not activate synchronization.

## Adapter contracts

Supply all callbacks and limits explicitly:

- `fetchRoles(guildId)` returns a list of normalized role-ID strings, or its promise.
- `fetchPage(guildId, after, limit)` returns a list of normalized `{id: string, roles: list<string>}` records, or its promise. The provider requests limit 1000. The initial cursor is the string `"0"`; subsequent cursors are the greatest validated member ID received. Only an actual empty list terminates pagination, including after a short nonempty page.
- `observe(guildId)` returns `{guild_id, connected, epoch, generation}`. Connection must be explicitly true; epoch and generation are nonnegative integers. The observer must reliably identify disconnects and changes, not infer continuity from cached member count or an earlier ready event.
- `clock()` returns finite monotonic seconds. Backward movement or deadline expiry fails the snapshot.
- `deadline(delaySeconds, expire)` registers a deadline and returns a callable that cancels that timer. Timer registration failure rejects before fetching. Cancellation here never means transport cancellation.

The normalized transport contract deliberately does not guess installed Discord `Member` classes or convert live payloads. A later adapter must validate raw HTTP responses before constructing these normalized lists; malformed JSON, errors and absent data are not empty lists.

`maxPages`, `maxMembers` and `timeoutSeconds` are required. Supported safety bounds are 1–1000 pages, 0–1,000,000 members and a positive finite timeout of at most 300 seconds. The terminal empty page counts against the page budget. Each role list is capped at 1000 entries, and each member page at 1000 records. Choose tighter operational limits for the intended guild; these maxima are not defaults or guild-size assumptions.

## Completion and failure semantics

The provider obtains roles before pagination and again after the terminal empty page. Both validated role sets must match. It rejects malformed/duplicate IDs, missing or duplicate role IDs, references to unknown guild roles, duplicate members, stalled/backward cursors and exceeded budgets. IDs are compared as decimal strings, never floats or machine integers.

Lifecycle epoch, generation, connection and elapsed time are checked before requests and after responses. Any failure rejects without returning partial members. Successful results contain the existing gate fields `guild_id`, `connected`, `complete`, `generation`, `roles`, `members`, plus `epoch`, `pages`, `started_at` and `completed_at`. Times are monotonic seconds, not UTC timestamps. Members are deterministically sorted by numeric string ID.

A deadline rejects the snapshot and ignores late data. If an injected request remains outstanding, the provider stays busy until it settles; another call rejects rather than overlapping that request. Late settlement only releases the busy gate and never issues more pages or publishes partial authority. A transport that never settles can therefore keep this provider busy indefinitely after the caller has received timeout failure. This is intentional: the provider does not pretend to cancel it.

Completed pagination is an observation over a time window. Matching role sets and lifecycle generations are conservative consistency checks, not proof of an atomic Discord state or protection against undelivered events. The coordinator still needs fresh precommit validation and a trustworthy invalidation source.

## Runtime gates remain unresolved

The locked DiscordPHP HTTP client exposes `Discord::getHttpClient()`, `Http::get()` and `Endpoint::GUILD_MEMBERS`/`GUILD_ROLES`; a later adapter should prefer those existing abstractions over a new authenticated HTTP stack. However, the locked client retries 502/525 internally and its queued Deferred has no transport cancellation hook. A provider timeout must not be described as cancelling those requests or retries.

The locked Discord client also does not expose its protected connection state through the inspected public getter interface. `init`, `reconnected` and `closed` alone do not report every gateway disconnect. A reliable continuity observer must be established separately; this injected contract does not supply one. No dependency patch, subclass or bootstrap modification is included.

Source authentication, status/records pairing, approved guild/role configuration, real transport deadlines, error observability and actual file/game consumption remain independent integration gates. Pure callback attestations do not resolve them.

## Offline verification

At the repository root with existing dependencies and PHP 8.3 or newer:

```sh
php -l src/Civ13/AdminMemberSnapshotProvider.php
php -l tests/local/admin-member-snapshot.php
php tests/local/admin-member-snapshot.php vendor/autoload.php
```

Synthetic fixtures cover terminal-empty completion, empty guilds, short pages, large string IDs, malformed data, role changes, duplicate/stalled cursors, size/page limits, lifecycle changes, deadlines, late-response draining, reentrant deadline expiry and resolved-subscriber reentrancy. Fetches are injected and no bot bootstrap, `.env`, token, live identity, network or admin-file write is used.

The existing dependency-installed Ubuntu PHP 8.3/8.4/8.5 supervision job runs this test. No default transport or production continuity guarantee is exercised by that offline coverage.
