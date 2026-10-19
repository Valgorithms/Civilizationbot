# Live launcher server directory

The opt-in read-only endpoint is `GET /.well-known/civ13/servers.json`. It uses the
bot's existing ReactPHP HTTP listener. It does not create another listener,
scan ports, start servers, modify server configuration, or expose bot controls.
It is disabled unless `launcher_directory` is explicitly configured.

## Deployment requirements

Deployment and public reverse-proxy changes are separate operator actions. This
repository has no game/bot production-deployment workflow; its GitHub Actions
workflows test contracts and publish documentation only.

1. Install the updated bot code and existing Composer dependencies with PHP 8.3+
   using the installation's usual process. No additional dependency is needed.
2. Add a mapping to the bot's `$options`. Each value is a list of keys from that
   installation's **enabled** Civ13 `GameServer` registry, for example:

   ```php
   'launcher_directory' => [
       'civ13.com' => ['tdm', 'nomads'],
       // Add valzargaming.com only with its verified, enabled production keys.
   ],
   ```

   `tdm` and `nomads` are enabled in the checked-in `bot.php`; the checked-in
   VZG `pers` entry is disabled. That is not evidence of the current production
   roster. An operator must inspect the actual installation before enabling the
   mapping. `['valzargaming.com' => []]` intentionally reports an empty roster;
   do not use that to claim VZG discovery is deployed or tested. A disabled,
   duplicate, unknown or invalid key disables the directory and logs a warning.
   Up to 32 total targets are allowed. A key belongs to only one provider.
3. Verify that each configured server's existing `ip` and `port` are the desired
   BYOND Topic target. The public join address is the mapped provider hostname
   plus that same configured port, so the provider DNS and any existing port
   forwarding must already route those ports correctly. A server is probed at
   its configured address, which can be loopback/private for an on-host bot;
   its internal address is never published. No port is specified in the launcher
   or repeated in the directory mapping.
4. The game must answer BYOND Topic `?status=1&format=json` on its configured
   gameplay/Topic port with `players`, `map_name`, `map_gamemode`, and
   `round_duration_in_ticks`. Zero players and a zero-duration lobby are valid.
   A JSON `game_id` is recommended; if absent, the public round ID is empty.
   A successful TCP handshake alone is not online evidence.
5. On each mapped provider, route the **exact** HTTPS path below to the existing
   bot listener, preserving `Host: civ13.com` or `Host: valzargaming.com`:

   - `https://civ13.com/.well-known/civ13/servers.json`
   - `https://valzargaming.com/.well-known/civ13/servers.json`

   Use the site's existing TLS certificate and reverse proxy. Serve GET/HEAD
   without redirects, login pages, bot challenges, CDN caching, or stale-on-error
   fallback. A normal website redirect to another host must not catch this path.
   Do not expose the bot listener or proxy other bot API paths as part of this
   change. The endpoint ignores forwarded-host headers and selects providers
   only from its actual request URI/Host. An Nginx-style location could be:

   ```nginx
   # Inside the existing civ13.com HTTPS virtual host. Adapt only after review.
   location = /.well-known/civ13/servers.json {
       proxy_pass http://127.0.0.1:55555;
       proxy_set_header Host civ13.com;
       proxy_cache off;
       proxy_cache_use_stale off;
   }
   ```

   `55555` is the bot's checked-in default HTTP port, not a game-port discovery
   list. Substitute the verified listener for the installation. Use
   `Host valzargaming.com` in that provider's virtual host. Do not change
   listeners, firewalls, TLS/security settings, or existing proxy trust rules
   without the operator's separate approval. Apply any per-client rate limiting
   at the trusted public proxy. The existing bot handler also rate-limits this
   endpoint to 600 requests/minute per observed remote address; behind a proxy,
   clients may share that budget. Do not trust new forwarded-IP headers merely
   to bypass that limit.
6. Restart the bot through its existing approved deployment process. Verify each
   HTTPS endpoint returns status 200 and `application/json`, contains only the
   corresponding provider, and has fresh timestamps. Then verify a known live
   server, a zero-player server, and an offline configured server. Compare counts,
   map/mode and round duration with the game or `/players`; do not use old bot
   file-cache data as proof of liveness. Neither code merge nor offline CI proves
   this live deployment acceptance.

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

The port above is an illustrative response, read from the server registry at
runtime. All timestamps use UTC `yyyy-MM-ddTHH:mm:ssZ`. The envelope is valid
for at most 60 seconds and never past the oldest included observation's expiry.
HTTP reads cannot extend observations' lifetimes. An empty successful sweep
returns `servers: []`; warmup/stale data returns 503 with Retry-After. Unknown
provider/path, query parameters, and non-GET/HEAD methods are rejected. Every
response is `Cache-Control: no-store`; no raw status payload is returned.

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
