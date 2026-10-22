# Disabled safehouse acceptance and host gates

The safehouse integration remains disabled by default. Local mocks, native fixture artifacts and exact-head CI
are separate from command registration, live Discord delivery and host activation.
Ordinary tests/bootstrap.php loads .env; safehouse runners deliberately bypass it.

## Evidence and remaining scope

| Scope | Evidence | Remaining gate |
| --- | --- | --- |
| Linking | Actor derived from Discord member; strict challenge/proof payload; signed bound replies; native link artifact accepted | Native owns in-game generation/expiry/one-use proof and one-to-one identity. No live linking claim |
| Identity/condition/FIR | Bot passes native JSON without reconstructing inventory; earlier native economic fixture preserves graph; bot stash.detail routing implements the agreed nested DTO | The earlier inspection fixture exposed root ID/secure only. Current native implementation status is not established by that artifact; obtain an exact-head integrated nested DTO receipt |
| Access/busy/in-raid | Bot never supplies account identity or ownership; native rejects access/revision failures; mocked rejection remains ephemeral | No connected active-raid or multiweek recovery acceptance |
| Economics | Native 19-phase roundtrip manifest pins game1644e49dc46a82e430e16c0cfbe47cb911b8cfd4 and bot470fdfb6c3a407cc272f0c63a494de84e3a638d8: list/purchase/cancel/buyback/replay/interruption status | Pricing is approved native prototype allowlist/table, not production market acceptance |
| Confirmation/pending | Exact token-only payload and explicit confirm=true; strict selectors/revision bounds; original status distinguished from query status; bound recovery JSON displayed | Human Discord usability/registration scope has not been exercised |
| Replay/crash/concurrency | Exact bytes retained; queue/active bounds; process lock/replay/crash mocks; native markers and signed recovery artifacts | Host exclusion of concurrent native writers and actual power-loss ordering |
| Room management/market discovery | Additive room.quote/market.browse/stash.detail DTO agreed and bot routing implemented disabled | Exact-head native/bot integrated fixture acceptance pending; verify room upgrades use one account snapshot and receipt. This ledger does not assert that current native code is absent |
| Audit/retention | Immutable request/reply and native transaction receipts retained; no automatic uncertain deletion | Host audit export/access/retention/ack policy and bounded archival before capacity fills |

The native economic manifest was inspected as supplied evidence, not rerun by
this bot audit. Graph preservation during settlement does not establish detailed
inspection UI, multiweek persistence or active-raid/corpse-insurance gameplay.

## Source closeout and evidence handoff

The source package includes the disabled configuration example, strict command
schema, injected async route, immutable worker/spool transport, recovery handling
and offline regression runners. Current main's portable startup and launcher
directory options are preserved alongside the safehouse defaults. The actual
options-resolver regression checks disabled defaults and independent explicitly
configured directory/safehouse settings without constructing a bot.

`tests/safehouse/run.php` and `economics-driver-test.php` cover the dependency-free
spool and synthetic economic driver. `tests/safehouse/discord.php` exercises the
installed-library interaction, options and registration boundaries with injected
effects. `tests/safehouse/schema.php` exports desired command metadata only.
The existing safehouse workflow runs these checks in public CI; preserve the
source SHA and job results for each new head. Passing fixtures and a skipped
documentation job do not supply runtime or publication acceptance.

To close the remaining native integration evidence gate, supply a synthetic
artifact that names the exact native and bot commits, the DTO/crypto contract,
request/reply digests, and observed room quote/manage, market browse and stash
detail results. Include revision/actor rejection and replay/recovery controls.
Review that artifact through the existing offline compatibility runners before
claiming compatibility; an older paired receipt cannot silently cover newer
contracts. Connected raid/long-term recovery and host activation gates below
remain separate. This bot-only review does not authorize generating a new game
fixture, accessing game saves, enabling integration or running a live operation.

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
