# Disabled safehouse acceptance and host gates

The bot remains disabled. Local mocks, native fixture artifacts and exact-head CI
are separate from command registration, live Discord delivery and host activation.
Ordinary tests/bootstrap.php loads .env; safehouse runners deliberately bypass it.

## Evidence and remaining scope

| Scope | Evidence | Remaining gate |
| --- | --- | --- |
| Linking | Actor derived from Discord member; strict challenge/proof payload; signed bound replies; native link artifact accepted | Native owns in-game generation/expiry/one-use proof and one-to-one identity. No live linking claim |
| Identity/condition/FIR | Bot passes native JSON without reconstructing inventory; native economic fixture preserves graph | Native inspect response currently exposes root ID/secure only. Nested condition/FIR inspection needs an authoritative DTO |
| Access/busy/in-raid | Bot never supplies account identity or ownership; native rejects access/revision failures; mocked rejection remains ephemeral | No connected active-raid or multiweek recovery acceptance |
| Economics | Native 19-phase roundtrip manifest pins game1644e49dc46a82e430e16c0cfbe47cb911b8cfd4 and bot470fdfb6c3a407cc272f0c63a494de84e3a638d8: list/purchase/cancel/buyback/replay/interruption status | Pricing is approved native prototype allowlist/table, not production market acceptance |
| Confirmation/pending | Exact token-only payload and explicit confirm=true; strict selectors/revision bounds; original status distinguished from query status; bound recovery JSON displayed | Human Discord usability/registration scope has not been exercised |
| Replay/crash/concurrency | Exact bytes retained; queue/active bounds; process lock/replay/crash mocks; native markers and signed recovery artifacts | Host exclusion of concurrent native writers and actual power-loss ordering |
| Room management/market discovery | Additive room.quote/market.browse/stash.detail DTO agreed and bot routing implemented disabled | Native implementation and exact-head integrated fixture acceptance pending; room upgrades use one account snapshot and receipt |
| Audit/retention | Immutable request/reply and native transaction receipts retained; no automatic uncertain deletion | Host audit export/access/retention/ack policy and bounded archival before capacity fills |

The native economic manifest was inspected as supplied evidence, not rerun by
this bot audit. Graph preservation during settlement does not establish detailed
inspection UI, multiweek persistence or active-raid/corpse-insurance gameplay.

## Explicit host activation requirements

Keep safehouse_enabled=false until the concrete host package is reviewed. Supply
an injected AsyncGateway backed by AsyncSpool plus AsyncDriver/ReactTimer on the
existing event loop, the intended civ13_guild_id, and independently authenticated
service key provisioned outside tracked files. The synchronous Client is worker
only. Protect the private spool and all ancestors against untrusted replacement,
symlinks and access; do not point it at a save directory.

Run exactly one native writer per spool with host-enforced exclusion across
processes/restarts. Marker-before-reply and validated-receipt-before-clear are
required, but do not prove disk flush or power-loss ordering. Never overwrite a
completed reply, clear a forged/uncertain marker, re-sign an economic operation,
or mint another quote/token to resolve uncertainty. Expired operations use fresh
read-only actor/digest-bound status. Agree storage flush/recovery guarantees and
receipt-backed archival/ack policy before enabling; queue saturation fails closed.

Pin native crypto/handler and PHP dependencies, align clocks to the 120-second
TTL/five-second skew bounds, establish production wallet/treasury/pricing policy,
and retain configured audit receipts. Review the exported command schema against
the intended application's global command snapshot; registration saves only the
named safehouse command and does not bulk overwrite unrelated commands. The
handler additionally restricts use to the configured guild. Perform a separately
authorized bounded registration/restart/live ephemeral interaction check before
claiming live acceptance. No activation, live currency or deployment occurred.
