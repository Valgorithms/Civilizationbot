# Read-only admin console: cached observation adapter

`AdminConsole/CachedObservationAdapter` normalizes injected captures. It does not
call the directory, heartbeat poller, file reader, network, bot or game. No service
registration, collector integration or endpoint is included. In particular, do not
call `Directory::refresh()` or `ServerHeartbeat::poll()` from an HTTP/UI request.

## Actual source contracts

Inspected Civilizationbot main `ff94f6dd6643054336411ebdddaeb120b1d11bae`:

- `ServerDirectory/Directory::snapshot(provider)` produces schema 1 with UTC
  `generated_at`, `expires_at` and `servers`. Each online-only row has id, host,
  engine, online=true, checked_at, players, map, mode and round{id,duration_seconds}.
  `generated_at` is the time snapshot() renders, **not** when a server was checked.
  Expiry is at most 60 seconds after the underlying observation. Null means
  unconfigured, warming up or stale; missing rows can reflect failed probes or
  omission. Neither null nor missing establishes stopped/offline.
- `ServerHeartbeat::poll()` produces state (advancing/stale/unknown), reason, age
  and data. Age measures time since last observed writer progress, not time since
  the poll. At 120 seconds without progress the source is stale. The raw data and
  reason must not enter the admin DTO: data can contain player identifiers and
  other fields not appropriate to expose.

## Injected capture envelope (new, internal-only)

The future trusted collector supplies an immutable directory capture:

```
{clock_scope, captured_at, wall_time, snapshot}
```

`captured_at` is monotonic seconds and `wall_time` is UTC Unix seconds from the
same capture; `snapshot` is the existing parsed directory result, or null. Read
the wall/monotonic pair immediately after snapshot() returns (not before it), so
a UTC second boundary cannot make generated_at newer than captured wall_time. Capture
this clock pair when collecting, not during a later render. A collector incarnation
must use a new `clock_scope` after restart. This is an application-owned adapter
contract, not an HTTP upload format or a credential/authority mechanism. Do not
reconstruct fresh capture envelopes from persisted/public status.

Heartbeat capture:

```
{server_id, clock_scope, sampled_at, observation}
```

`sampled_at` is the original poll time in the same monotonic scope; `observation`
is its retained result. Never substitute read/render time. Keep captures private
and pass only the adapter's allowlisted result onward.

`normalize(target, directoryCapture, heartbeatCapture, now)` uses a target from
trusted inventory: `{id, provider}`. The target must already be authorized by the
caller/registry boundary; accepting a syntactically valid ID here does not grant
access or registration. Providers are the directory's existing civ13.com and
valzargaming.com values. `now` is an injected monotonic time, not an ambient read.

Checked_at and expiry convert through the fixed clock pair. The adapter retains
original collection times as well as observation times, so a clock rollback
before capture cannot make an older-looking progress timestamp seem trustworthy.
No raw address, port, name, token, bearer credential, reason, session, process
identity or heartbeat data is copied. Duplicate target rows, wrong provider/scope,
future timestamps and malformed/oversized lifetimes fail to unknown.

## Display contract

Pass normalized output to the existing `AdminServerProjection`. It retains the
existing authorization gate, metrics and permanently disabled control capabilities,
with additive `evidence` fields:

- `reachability`: reachable / stale / unknown, age_seconds.
- `heartbeat`: advancing / stale / unknown, age_seconds.
- `readiness`: unknown for these two source adapters. Existing explicit boolean
  readiness inputs in other projection fixtures remain separately reported.

The projection rechecks each evidence expiry on every read. A directory row can
expire while heartbeat progress remains fresh; a reachable server can have a stale
heartbeat. Neither situation is collapsed into readiness. Unknown/stale states
still suppress current player/map metrics. An absent directory row with advancing
heartbeat remains overall unknown, not online or stopped. No offline/busy/recovery
state is invented from these sources; their future authoritative producers remain
separate. Current fixture UI does not yet present the new evidence fields.

Directory metadata supplies player count, map/mode, round ID/duration only.
Save generation, save/load phase, CPU/RAM and lifecycle readiness remain unavailable.
The unknown fallback carries no observation claim; re-normalizing an old capture
cannot renew reachability/progress evidence. All output is display-only, never an
execution lease or action authorization.

## Verification

Offline, dependency-free checks (no bot bootstrap or real captures):

```
php -l src/Civ13/AdminConsole/CachedObservationAdapter.php
php -l src/Civ13/AdminConsole/AdminServerProjection.php
php -l tests/admin-console/cached-observation.php
php tests/admin-console/projection.php
php tests/admin-console/cached-observation.php
```

The existing admin-console six-job PHP matrix includes the new lints/contracts.
Fixtures cover zero players, source expiry, independent evidence, absent rows,
wrong target/provider/scope, clock rollback, duplicate rows, malformed timestamps,
raw-data exclusion and permanently unavailable controls. Local PHP is unavailable
in the preparation environment; these new PHP checks have not yet executed. The
previous head's passing tests do not establish this patch's runtime correctness.

## Producer cross-check pointers

At the inspected source pin, Directory.php lines 132–151 require each included
checked_at <= generated_at < expires_at, with expires_at <= checked_at + 60.
Lines 168–170 emit the exact provider host, uppercase BYOND and online=true;
line 151 emits integer schema 1. metadata() permits an absent game_id as an empty
round.id, also explicitly asserted in tests/server-directory.php:180.

Existing tests/server-directory.php:147 and :164 assert a 60-second initial
lifetime and one second remaining after 59 seconds; :220 and :225 compare the
managed/unicode fixtures to actual production Directory output. The remote
suite's :69–72 checks an early result is not renewed by a slow peer, and :150
compares the VZG fixture to production output. The adapter contracts now load
all three existing fixture files and check admission, metadata and 59/60-second
boundaries. Those added checks are prepared, not locally executed.

Text validation in both projection and adapter uses the producer's exact rejected
Unicode categories: Cc/Cf/Cs and Zl/Zp. Private-use and unassigned characters are
not treated as controls. Explicit contracts accept a private-use map label through
both layers and reject newline, bidi-formatting and malformed UTF-8 independently
in each layer. The existing UI still uses textContent/createTextNode, not HTML;
its hostile-label DOM contracts and fixture-only control boundary are unchanged.
