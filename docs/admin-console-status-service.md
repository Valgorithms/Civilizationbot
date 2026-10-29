# Read-only status service and unregistered HTTP boundary

This source connects trusted inventory, injected per-request authorization,
retained observation captures, normalization and projection. It does not register
an endpoint, start a listener, collect observations, authenticate a real session,
request Discord members, install OAuth grants or change runtime configuration.
The UI is still a disconnected fixture demo. No commands exist at this boundary.

## Existing app conventions

`HttpHandler::__processCallback` expects a synchronous React HTTP Response. The
prepared `AdminConsole/StatusEndpoint::handle(ServerRequestInterface)` follows
that convention, like `ServerDirectory/Endpoint`. It is not added to
HttpServiceManager or HttpHandler. Generic router IP whitelisting is not identity;
`/dwa` must not be used to bypass the endpoint's independent session/role policy.

## Three injected application-owned dependencies

1. `session(request): ?string` returns a Discord snowflake **only after** a real
   server-side session provider has authenticated it, returns null for an absent/
   expired session, or throws when unavailable. No provider is installed here;
   omission fails 503. The endpoint does not parse Authorization, Cookie, query,
   request attributes or IP as identity, and never falls back to a bot identity.
2. `authorize(discordId, targetId): bool|null` reads the existing maintained guild
   member cache through its application readiness/epoch barrier, using configured
   guild and role IDs. True grants this read capability only; false denies, null
   or an exception means unavailable. Configure policy for each target; do not
   assume every Discord administrator may read every server. Omission fails 503.
   Reuse the maintained member cache and its invalidation/readiness rules (the
   admin-sync work's renderCached boundary illustrates the epoch checks); do not
   create another member fetch/pagination/TTL poller or call admin-sync writers.
   Raw cache presence or the generic bot-ready flag alone is not authority.
   The thin binding to this existing cache policy is intentionally not installed
   by this patch and does not change PR #34/#35 ownership or files.
3. `captures(targetId): {directory:?array,heartbeat:?array}` returns existing
   original capture envelopes documented in admin-console-cached-observations.md.
   This must be an in-memory retained-value accessor, never refresh(), poll(),
   file reads, Topic, HTTP or async work. Null source values mean unknown;
   a malformed accessor result or thrown exception is unavailable, not stale data
   silently reused. No collector is wired here.

The service freezes one injected monotonic now per response. Inventory is an
explicit bounded list (maximum 32) of exactly `{id,name,provider}`; duplicate IDs,
extra fields and invalid names/providers reject at construction. Browser input
cannot select inventory, addresses, paths or target ports. Only permitted targets
are read; denied targets and names never appear in the response. Every request
reauthorizes every configured target, then rechecks permitted targets immediately
before releasing the response. Grants are not cached across requests. A role
change while assembling the snapshot drops the prepared response. Synchronous
callbacks must not yield or conceal a network refresh.

## Proposed HTTP contract (not activated)

Exact path: `/admin/v1/servers`. GET and HEAD only, no query string. No mutation
routes or target-selection input. Prepared statuses:

| Situation | Status |
| --- | --- |
| Authenticated, role-authorized inventory | 200 |
| Any nonempty query | 400 |
| No valid authenticated session | 401 |
| No authorized target / role revoked before response | 403 |
| Other path | 404 |
| Other method (`Allow: GET, HEAD`) | 405 |
| Session/authorization provider absent, not-ready cache, unavailable capture or callback failure | 503 |

Every response, including errors and HEAD, has:
`Content-Type: application/json; charset=utf-8`,
`Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache`,
`Vary: Cookie, Authorization`, and `X-Content-Type-Options: nosniff`.
No CORS access is granted. HEAD runs the same session/policy checks but returns
an empty body. Query/path/method rejection runs before session resolution.
Errors contain fixed codes, not exception details, names or credentials.

Success envelope:

```
{"schema":"civ13.admin-overview.v1","servers":[
  {"name":"Configured display name","projection":{"schema":"civ13.admin-status.v1", "...":"existing projection fields"}}
]}
```

This does not invent the fixture UI's observed_at metadata or relabel its demo
as live. All command capabilities remain false, and no raw capture fields enter
the envelope. The per-target projection still preserves independent reachability,
heartbeat and readiness evidence.

## Verification and remaining integration

`php tests/admin-console/status-service.php` is dependency-free and uses only
injected values. `php tests/admin-console/status-http.php [explicit-autoload]`
uses the application's actual PSR/React HTTP dependencies and synthetic requests,
not a fake HTTP implementation or listening server. It does not bootstrap bot.php,
load .env, connect to Discord or read real membership. The six-version/platform
PHP matrix gains service tests; a separate PHP 8.3 job installs **existing**
Composer dependencies with scripts/plugins disabled and runs actual HTTP tests.
No dependency declarations/lock files are edited.

PHP is unavailable in the preparation executor. New PHP tests and lints are
prepared, not passed; prior head CI does not verify this new patch.

Still missing before a live mobile app: a reviewed real session provider and
session lifecycle (including expiration/revocation and cookie/origin handling),
the read-only configured-role binding to the maintained member cache barrier,
trusted target inventory and cached-observation wiring, supported-browser QA,
and separately approved HTTPS/routing/runtime activation. Do not register the
endpoint merely because offline tests pass. None of these integrations or
credentials is created by this patch.
