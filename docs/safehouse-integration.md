# Discord safehouse integration (provisional, disabled)

This patch provides a pure controller, command schema and durable request client.
It does not register commands, start a bot, read game saves or provide a live
transport. Compatibility is unaccepted until the game handler executes this
contract fixture. Existing verifier cache entries provide no trade authority.

## Version 1 request

```json
{"protocol":"civ13.safehouse.v1","request_id":"discord-interaction-id","discord_id":"123","action":"trade.confirm","expected_account_revision":4,"payload":{"confirmation_token":"opaque-game-token"},"request_digest":"sha256-of-canonical-envelope-without-digest"}
```

Objects sort keys recursively; arrays retain order. Encode UTF-8 JSON without
whitespace using PHP JSON default escaping. The digest binds the immutable
request; it is not authentication. Responses echo protocol, request_id,
discord_id, action and request_digest with status pending, committed or rejected.
Only committed establishes durable game success. A transport timeout remains
pending even when the game may already have committed. Replay the exact request.

Proposed payloads: link.challenge {challenge_id}; link.complete {challenge_id,
proof}; stash.inspect/room.inspect {page}; room.manage {confirmation_token};
trade.quote {item_ids, sale_kind}; trade.confirm {confirmation_token};
trade.status {transaction_id}; market.list {confirmation_token}; market.buy
{confirmation_token}; market.cancel {confirmation_token}. This is a proposal,
not an implemented game schema. No client price or account identity is accepted.
Quotes bind actor, item graph IDs, revision, approved game price, action and
expiry. Listing and room mutations also require game-issued confirmation.

Link challenges originate from an authenticated in-game account and are bounded,
short-lived and single-use; the trusted service adapter binds the Discord actor.
Discord alone cannot establish account ownership. Busy/in-raid/unauthorized,
expired or stale requests must be rejected by the game. Nested IDs, FIR and
condition remain game values. The bot never reconstructs inventories.

## Proposed local spool transport

Inject a transport using a private, configured IPC directory, not a save path.
Use one immutable request file per request ID, written to a private temporary
file then atomically renamed. A separate authenticated adapter signs the exact
request bytes using a service credential; the game validates that credential
independently of discord_id. No configured endpoint or credential is invented
by this patch. Replies must bind the authenticated request digest and identity.
Bound request size, queue count, expiry, polling and timeout before enabling.
The game owns one bounded queue and idempotency ledger; concurrent/replayed
requests cannot settle twice. Multi-party settlement requires one durable shared
transaction receipt and recovery, not separate account-save writes.

## Local operation and limits

Run `C:/php/php.exe tests/safehouse/run.php`. It bypasses the ordinary test
bootstrap, which reads `.env`. Tests use injected game transport and temporary
journals with no Discord/network/bootstrap effects. Constructor `enabled` is
false by default. Hosting code must use trusted Discord actor/interaction IDs
and ephemeral responses; this patch exposes no bot config or listener yet.

The journal serializes local requests with an OS file lock, persists before
transport delivery and caches terminal replies. It bounds records at 1000 and
fails closed when full; archive only under an operator-approved game receipt
retention policy. The transport must enforce a finite timeout. Blocking transport
must not run on the shared Discord event loop. No live spool, game-side checks,
wallet/pricing/escrow correctness, cross-process crash durability or live command
registration has been verified. Activation depends on game handler compatibility,
authenticated async adapter, wallet/escrow and identity reconciliation.
