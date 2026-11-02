# Game-ready observations

The default-disabled game_ready receiver uses the existing authenticated game-to-bot bridge. It records a startup-completion claim; it does not set process/Topic readiness, control lifecycle, send Discord messages or forward an external webhook.

## Payload

Send GET /webhook/<server>?key=<existing service key>&method=game_ready&data=<URL-encoded JSON>. The direct /webhook/<server>/game_ready route has the same checks. Query parsing decodes data once; a second URL-encoding layer is rejected.

| Field | Required value |
| --- | --- |
| schema | game_ready.v1 |
| event | game_ready |
| round | Captured game_id, [A-Za-z0-9_-]{1,128} |
| server | Exact configured route key, lowercase [a-z0-9_]{1,32} |
| boot | Existing boot_session identity, 32 lowercase hexadecimal characters |
| generation_kind | fresh or restored |
| generation | fresh:<boot> for fresh selection; captured validated round-v2:<32hex> for restore |

The flat object must contain exactly these seven literal unique keys. Normal field order and whitespace are immaterial. Unknown fields, private content, escaped/duplicate keys, wrong server and malformed types are rejected. The raw decoded query-data limit is 1,024 bytes, JSON depth four.

Both the existing dispatcher and the direct handler require the outer nonempty service key. Loopback/network whitelist never substitutes for authentication. Exact path, current enabled GameServer instance and explicit game_ready_events: {enabled: true} are required. Omission/default false creates no ledger, timer, client, file or dispatch. The portable example keeps the flag explicitly false. Chat legacy_relay is a separate policy.

## Acknowledgement and retention

202 acknowledges an observation queued in this process; 200 acknowledges its semantic duplicate. A conflicting startup identity for the same server/boot returns 409. Disabled/unbound/capacity/clock failure returns 503, malformed data 400 and non-GET 405. Receipts state delivery: unconfigured.

The ledger retains at most 256 startup records for 24 hours; duplicates do not extend retention. Restart/expiry can permit re-observation. It provides no durable or exactly-once external delivery guarantee. A future publisher must state its delivery semantics; persisted identities or destination idempotency are needed if deduplication must survive restarts. External destination selection and activation remain separate.

Compute canonical text by joining schema/event/server/boot/round/generation_kind/generation values with LF, without a trailing LF. Receipt event_id is its SHA256. X-Civ13-Game-Ready is its MD5 solely as a bounded native acknowledgement correlation token; it is not authentication or a signature. A sender can accept only HTTP 200/202 plus this exact header, without reading CONTENT. Logs contain only server/event hash/state. Logging failure does not undo an observation.

## Producer contract

This signal means initialized startup, including the lobby, not PLAYING or client reachability. The assigned game startup/persistence owner must provide a coherent positive completion snapshot: required initialization finished, startup selection/import finished, player restoration complete and all operation/recovery holds cleared. Fresh selection must be explicit. A restored generation and round identity must be captured from successful validated import, never inferred by rereading save_complete.txt later. Generic round_start and the initial setup notice are too early. This is a historical startup event, not proof of current reachability or readiness after a later operation.

Reserve one immutable startup identity before asynchronous dispatch. Permit one outstanding request, at most three settled attempts, a one-second retry delay and a 1,200-world-time admission window. An unresolved request retains ownership and permits no overlap. Treat 400/403/404/405/409 as permanent refusal. Retry only within the same identity and configured bridge; never fall back to a new destination or credentials. The initial delivery proposal is restricted to the existing authenticated 127.0.0.1 bridge on an explicit unprivileged port and matching server route.

Game completion-hook wiring, native compilation/runtime and transport acceptance are a separate producer change. The consumer does not assert that those hooks exist. No polling or independent lifecycle owner is introduced.

BYOND's [Export reference](https://www.byond.com/docs/ref/info.html#/world/proc/Export) documents a sleeping HTTP call and STATUS/CONTENT results without a timeout/cancellation argument. Spawn avoids blocking the caller; it does not impose a hard transfer or response-allocation deadline. CONTENT should not be read for this acknowledgement. Engine redirects remain unverified, so the initial loopback restriction is not OS egress containment. The [file2text reference](https://www.byond.com/docs/ref/info.html#/proc/file2text) has no bounded-read parameters. Attempt/admission limits do not claim a hard wall-time or engine-memory bound.

## Offline checks

Run php tests/local/game-ready.php vendor/autoload.php. Existing local-profile Ubuntu and effects-disabled Windows jobs select it. It uses inert requests, fake server bindings and memory only. No bot.php, .env, listener, client, real send, native game or restart is used. Producer and live activation acceptance remain pending.

### Synthetic HTTP-wire integration

`php -d memory_limit=128M tests/local/game-ready-http.php vendor/autoload.php` connects the real ReactPHP Browser and HttpServer parsers through injected in-memory duplex streams, then invokes the registered consumer route. The fixture opens no OS listener, resolves no DNS, launches no child process and loads no bot bootstrap; all HTTP traffic uses injected memory streams and synthetic credentials. The matching Windows CI step disables network/process functions. The new checks run in the existing Linux and Windows PHP 8.3/8.4/8.5 jobs; no job, trigger or permission is added.

The finite representative producer uses the agreed query encoding and independent canonical ACK vector. It covers both fresh/restored payloads, lost response after reservation, malformed ACK, response-buffer overflow, silent response timeout, duplicate/conflict retries, stale current-server ownership, disabled/auth/path/verb refusals, receiver-object replacement, capacity, expiry and clock rollback. Default-capacity coverage directly prefills 256 synthetic ledger records, then tests full/duplicate/24-hour expiry through HTTP. A smaller two-record fake-clock seam covers edge cases too.

Bounds are 40 sequential exchanges, one outstanding request, 128 KiB cumulative wire bytes, 4 KiB per write/request, 64 queued deliveries, a 512-byte client response buffer, 100 ms per Browser exchange and a five-second cooperative private-loop watchdog. CI steps have two-minute outer limits; PHP memory is 128 MiB. Stream/future-delivery ownership is closed between every request and finally. The test timeout belongs to this PHP fixture, not BYOND Export. The producer model has at most three settled attempts and a simulated 120-second admission clock; it does not test the DM retry delay, cancellation or actual startup barrier. Receiver replacement models loss of memory state, not an OS bot restart. It establishes no live transport or end-to-end readiness proof.
