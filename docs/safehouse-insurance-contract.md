# Read-only insurance projection

`insurance.inspect` has exact payload `{ "cursor": null }` initially or an opaque
printable ASCII cursor of 1..256 bytes. Empty cursors are rejected. The existing
outer `expected_account_revision` is nullable. There is no Discord claim action.

The authoritative response has account_revision (0..1000000), mail_revision and
completed_generation (0..16777215), expiration exactly `to be implemented`,
entries (at most eight), and next_cursor (null or the same cursor syntax).
Each entry has item_id (ASCII identifier 1..80), native catalog type/name
(nullable UTF-8 strings at most 160/128 bytes), condition with health/maxhealth
(finite numbers or null), found_in_raid (boolean or null), returned_generation
and eligible_generation (0..16777215), and state (`pending` or `eligible`).
The decoded signed response remains bounded to 8192 bytes. Proofs, tokens and
arbitrary saved scalar strings are absent. Native stale cursors reject with
`stale_cursor`; the bot never constructs or interprets their HMAC bindings.

Returns are recorded at the loss round closure and remain locked through one
additional full successful round closure. Restart does not advance generation.
The bot displays native state without deriving eligibility from time or cached
rounds. Logical storage is unlimited; cursor paging and native sharding bound
individual reads. Counter limits are not an entry limit. Expiration has no
active TTL and is explicitly pending a balance decision.

Mocked projection/routing tests do not prove native sharding, full round closure,
or insurance recovery. Native compatibility is pending a pinned fixture.
