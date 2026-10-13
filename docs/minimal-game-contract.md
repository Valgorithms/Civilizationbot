# Agreed minimal status and linking boundary

This contract is source-reviewed, disabled and pending native timing/replay and
reachable player acceptance. Economy extensions remain disabled. A bot gameplay
action must also be reachable in game; API existence alone is insufficient.
Synthetic handoff vectors are in `tests/local/fixtures/minimal-game-v1.json`;
their required negative controls are a pending acceptance inventory, not passed tests.

Count-only status is exact `civ13.status.v1` with fields `schema`, `instance_id`,
`build_id`, `map_id`, `map_name`, `generated_at`, `ready`, `player_count`.
Instance is trusted launcher ASCII 1..80 characters; build is nullable ASCII
up to 80, map ID and name nullable strings up to 128. Timestamp is a canonical
decimal integer string of BYOND realtime deciseconds since 2000 UTC, converted
to Unix milliseconds by multiplying by 100 and adding 946684800000. Ready is
strict boolean and count an exact integer 0..65535. No players/truncated fields
or live endpoint are accepted in this minimal version.

Verified readiness requires trusted nonnull requested instance, build and map
to match and ready true. Null build cannot verify a requested revision. Missing
capability, invalid schema, unreachable/stale status or transition remains
unknown/loading, never a fabricated empty server. Proposed 30-second age and
5-second future tolerance are conditional on confirmed publication cadence
at most five seconds. Endpoint authentication and readiness production source
remain gates; no permission or IP whitelist relaxation is implied.

Linking retains signed `civ13.safehouse.v1` receipts with exact
`protocol`, `request_id`, `discord_id`, `action`, `request_digest`, `status`,
`reason`, `data`. Reason must not be omitted. Receipt identity and actor bind to
the authenticated service request; digest alone is not authentication.
`link.challenge` receives `{challenge_id}` and returns `{challenge_id,expires}`;
it never issues a proof. `link.complete` receives `{challenge_id,proof}`. IDs
and proofs are 64 hex characters; the game owns the 300-second challenge TTL,
inclusive expiry deadline, stored proof digest and durable one-use consumption.

The game source `ipc_game.dm` issues proof through `link_discord` only for the
owned conscious client matching ckey/home, outside transition, adjacent to its
own house and after savehome. Completion requires proof hash match and unlinked
owner/actor. Bot verification-cache or asserted Discord identity grants no game
ownership. Unknown capabilities fail closed; pending/timeouts never imply
success or authorize another mutation. Native timing/replay and in-game
reachability remain unrun acceptance gates for this frozen source contract.
