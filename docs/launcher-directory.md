# Live launcher server directory

One opt-in read-only HTTPS authority serves both provider menus through the bot's
existing ReactPHP HTTP listener:

- `https://civ13.com/.well-known/civ13/servers.json?provider=civ13.com`
- `https://civ13.com/.well-known/civ13/servers.json?provider=valzargaming.com`

Only those exact raw query strings are accepted. Responses remain separate
schema-1 snapshots, containing only the selected provider's servers. No new HTTPS
service is needed on valzargaming.com. Both inventories trust civ13.com and both
menus become unavailable if that authority is unavailable. The directory never
scans ports, starts games, modifies game configuration, or exposes bot controls.
Both inventory options default to `[]`; the route is disabled when both are empty.

If restarting the live bot is unsafe, the optional [isolated directory runtime](launcher-directory-standalone.md) reuses this contract without booting the bot or games. Its dependency and host-review gates must be satisfied first.

## Deployment requirements

Deployment, production configuration, and public reverse-proxy changes require
separate operator approval. This repository has no game/bot production-deployment
workflow; its GitHub Actions workflows test contracts and publish documentation.

1. Install the updated bot code and existing Composer dependencies with PHP 8.3+
   through the installation's usual process. No new dependency is needed.
2. Configure only verified inventory in the bot's `$options`. Existing locally
   managed games use keys from the installation's **enabled** GameServer registry:

   ```php
   'launcher_directory' => [
       'civ13.com' => ['tdm', 'nomads'],
   ],
   'launcher_directory_status_targets' => [],
   ```

   `tdm` and `nomads` are enabled in checked-in bot.php; that does not establish
   the actual production roster. The checked-in VZG `pers` game is disabled.
   Do not enable it just to publish discovery. GameServer construction requires a
   local basedir and activates other bot behavior, so it is inappropriate for a
   remote status-only target.

   The separate status-only list permits exactly five fields per row. This is a
   **synthetic example**, not an approved production target or gameplay port:

   ```php
   'launcher_directory_status_targets' => [
       ['id' => 'vzg-fixture', 'name' => 'VZG fixture',
        'host' => 'valzargaming.com', 'port' => 24017, 'engine' => 'BYOND'],
   ],
   ```

   Operators must verify the real port before adding a row. `host` must be exactly
   civ13.com or valzargaming.com, `port` an integer 1–65535, and `engine` BYOND.
   No IP override, URL, basedir, enabled flag, credentials, unknown fields or
   request-supplied targets are allowed. These rows never construct, start or
   enable a GameServer. A status-only row makes its provider configured even if
   every probe fails. An explicit mapping such as `['valzargaming.com' => []]`
   can intentionally configure an empty provider; it does not prove live discovery.

   Invalid configuration disables the directory and logs a warning. IDs must be
   globally unique across both sources; host/port pairs must also be unique.
   The same port on different providers is valid. The limit is **32 total targets
   across both inventories**, not 32 per provider.
3. Managed targets are probed at their existing configured `ip` and `port`, which
   may intentionally be loopback/private for an on-host bot. Internal addresses
   are never published. Public joins use the mapped provider hostname and that
   same configured port. Existing provider DNS and port routing must match.

   Status-only targets instead resolve the approved hostname afresh on each
   probe, using asynchronous system-configured DNS with no application cache or
   public IP pin. Consistent with the launcher, they require 1–32 IPv4 A answers;
   the entire answer set must be ordinary public addresses. Private, loopback,
   shared, link-local, reserved, documentation, benchmark, multicast and other
   special-purpose answers are rejected. IPv6-only public targets are not
   supported by this launcher contract. One complete validated A-answer snapshot
   is deduplicated and tried in deterministic order. Literal status attempts are
   sequential: each receives an equal share of the remaining three-second total
   DNS/connect/read deadline. A silent or incomplete first address cannot consume
   every later address's budget. Only a complete valid status result is selected;
   TCP success alone is insufficient. There remains at most one active socket
   per target and 32 across the bounded sweep. No second hostname lookup occurs.
   Short time slices or an exhausted deadline can still leave a slow service
   unavailable; this is bounded best-effort selection, not guaranteed failover.
   A DNS change can take effect on the next
   sweep, subject to the system resolver's normal behavior. Missing system DNS
   fails closed; no fallback DNS provider is invented.
4. Each game must answer BYOND Topic `?status=1&format=json` on its configured
   gameplay/Topic port with `players`, `map_name`, `map_gamemode`, and
   `round_duration_in_ticks`. Zero players and a zero-duration lobby are valid.
   `game_id` is recommended; when absent the public round ID is empty. A successful
   TCP handshake alone is not evidence that the game is online.
5. Route only `/.well-known/civ13/servers.json` on the existing **civ13.com** HTTPS
   virtual host to the existing bot listener. Preserve `Host: civ13.com` and the
   raw query string. The query is only an allowlisted snapshot selector; it never
   triggers probes or supplies addresses. Repeated/extra parameters, percent
   encodings, URLs, ports, unknown providers and an absent query return 400.

   Use the site's existing TLS certificate and reverse proxy. Serve GET/HEAD
   without redirects, login pages, bot challenges, caching or stale-on-error
   fallback. Do not expose the bot listener or proxy unrelated bot API paths.
   The endpoint ignores forwarded-host headers. An example, for operator review:

   ```nginx
   # Inside the existing civ13.com HTTPS virtual host.
   location = /.well-known/civ13/servers.json {
       proxy_pass http://127.0.0.1:55555;
       proxy_set_header Host civ13.com;
       proxy_cache off;
       proxy_cache_use_stale off;
   }
   ```

   `55555` is the checked-in default bot HTTP port, not a game-port discovery
   list. Substitute the verified existing listener. No VZG HTTPS route is needed.
   Do not change listeners, firewalls, TLS/security settings or proxy trust rules
   without separate approval. Apply per-client rate limiting at the trusted
   public proxy. The bot also limits the endpoint to 600 requests/minute per
   observed remote address; clients behind a proxy may share that budget. Do not
   newly trust forwarded-IP headers merely to bypass it.
6. Restart through the approved deployment process. Verify both exact URLs return
   status 200, application/json and fresh provider-specific records. A configured
   provider with no verified online games returns 200 with `servers: []`; an
   unconfigured provider returns 404. Warmup/stale sweeps return 503. Test a known
   live, zero-player and offline configured game, and compare counts/map/mode/
   duration with the actual game or `/players`. Old cached files, merged code,
   and offline CI do not establish live acceptance.

## JSON contract (schema 1)

```json
{
  "schema": 1,
  "generated_at": "2026-10-19T17:00:00Z",
  "expires_at": "2026-10-19T17:01:00Z",
  "servers": [
    {
      "id": "nomads",
      "name": "Nomads",
      "host": "civ13.com",
      "port": 1715,
      "engine": "BYOND",
      "online": true,
      "checked_at": "2026-10-19T17:00:00Z",
      "players": 0,
      "map": "Gaia",
      "mode": "Nomads",
      "round": {"id": "example-round", "duration_seconds": 1234},
      "epoch": "1200"
    }
  ]
}
```

The port above is illustrative, obtained from explicit operator inventory at
runtime. All timestamps use UTC `yyyy-MM-ddTHH:mm:ssZ`. The envelope is valid
for at most 60 seconds and never past the oldest included observation's expiry.
HTTP reads cannot extend observations' lifetimes. An empty successful sweep
returns `servers: []`; warmup/stale data returns 503 with Retry-After. Unknown
authority/path, noncanonical provider queries, and non-GET/HEAD methods are rejected. Every
response is `Cache-Control: no-store`; no raw status payload is returned.

Text limits are UTF-8 **bytes**: name 120, map 128, mode 80, round ID 80,
and optional epoch 32. Well-formed supplementary-plane characters such as
`🌍` are supported and occupy four bytes. Malformed UTF-8/surrogates, Unicode
control/format characters, and line/paragraph separators are rejected. IDs use
1–64 ASCII letters, digits, underscores or hyphens. Players must be an integer
from 0 to 10,000. The shared fixtures under `tests/fixtures/` cover managed, supplementary Unicode,
and status-only VZG output against the launcher's production parser.

At bot readiness and every 15 seconds, one non-overlapping sweep asynchronously
probes the configured targets (at most 32 concurrent connections). Each probe
has a three-second total DNS/connect/read deadline and 64 KiB framing bound.
Only a complete valid BYOND string response containing a JSON object can produce
an online entry. Partial, malformed, timeout and offline results are omitted;
a completed sweep replaces the previous snapshot, including failed entries.
One provider/server failure does not remove another healthy server. Request
traffic never triggers probes or chooses addresses, ports, queries or URLs.

`players` is the live numeric count, not a cached player list. Map, mode, round
duration, and optional `epoch` are allowlisted public metadata. Player names,
IPs, CIDs, admin lists, account links, tokens, and other raw status fields are
never copied. The game's existing `round_duration_in_second` has a units error,
so this adapter uses `round_duration_in_ticks / 10` (floored). It does not invent
round state or take state from the bot's disk cache. If a game version's ticks
exclude restored elapsed time, that game status producer must be corrected
separately before claiming persistence-aware round duration.

## Offline verification

Run `php tests/server-directory.php` after Composer installation. An optional
first argument selects an existing dependency autoloader. The suite loads no
`.env`, boots no bot, and opens no network connections. Injected streams exercise
the production BYOND parser and timeout cleanup; fixture registries exercise
online/offline/zero-player/partial/malformed data, privacy projection, provider
isolation, HTTP methods, freshness, time rollback, and coalescing. The existing
dependency workflow runs it on PHP 8.3, 8.4, and 8.5 alongside source lint and
the other bot regression suites.

`php tests/server-directory-startup.php` additionally constructs real
`GameServer` objects against an injected in-memory bot boundary and invokes the
real directory registration method. It verifies synchronous population of the
enabled registry, exclusion of disabled/invalid-directory servers, string-port
normalization, and pre-init route registration without probes. A source-order
guard covers `Civ13::afterConstruct`: the game-server constructor loop runs
before `HttpServiceManager` construction. This test bypasses bot startup, the
HTTP handler's external-IP lookup, and live listening.

`php tests/server-directory-remote.php` verifies the strict status-only schema,
unknown-field rejection, cross-source identity/host-port uniqueness, global
32-target bounds, slow-peer freshness, overlapping-sweep coalescing, partial
failures, dynamic DNS and validation before literal TCP connection. Both DNS and
TCP are injected, so the suite performs no external network I/O. The startup
suite also checks remote-only registration with empty GameServer registries and
no added lifecycle callbacks. All three suites run on PHP 8.3, 8.4 and 8.5 in CI.

`php tests/server-directory-addresses.php` exercises the production probe factory
with injected DNS/TCP: both answer orders, silent/malformed/incomplete first
answers, full-set safety validation before TCP, duplicate suppression, one DNS
snapshot, literal/port binding, shared deadlines, cancellation, late callbacks
and the 32-active-socket ceiling across concurrent targets. It opens no sockets.
