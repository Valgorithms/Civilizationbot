# Discord safehouse integration (provisional, disabled)

This patch provides a pure controller, command schema, durable request client and
disabled enqueue/poll authenticated spool primitives.
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

`AsyncSpool` publishes and polls without sleeping or launching a process. The
host must schedule polling with React timers and stop at its own bounded timeout.
Files are `SHA256(request_id).request` and `.reply`. The private spool directory
must exist. The game must retain immutable request files; archival is a separate
operator policy, never deletion during consumption.

The authenticated envelope is `{protocol, issued_at, expires_at, body, mac}`.
Protocol is `civ13.safehouse.ipc.v1`; body is base64 of the exact UTF-8 JSON
request/reply bytes. MAC is lowercase hex HMAC-SHA256 of these exact bytes:

```text
civ13.safehouse.ipc.v1\n<direction>\n<decimal issued_at>\n<decimal expires_at>\n<base64 body>
```

Each `\n` denotes one literal LF byte; there is no trailing LF. Direction is
`request` or `reply` and prevents reflection. Validity is at most 120 seconds;
future issuance skew is at most five seconds. Replies with expired envelopes
remain pending. The game must reject expired/unlinked/stale requests, enforce
bounded one-use challenges and validate service authentication before processing
actor claims. PHP uses built-in `hash_hmac`; game verification requires its pinned
crypto adapter and must not spawn a process per request. Digest is preserved from
the trusted bot envelope and is never authentication.

Independent Python-generated signing bytes are pinned in
`tests/safehouse/signing-fixture.json` with a synthetic public fixture key. No
real key appears in code, fixtures, arguments or environment examples. This
fixture is the proposed game compatibility input, not game acceptance evidence.

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
wallet/pricing/escrow correctness, production crash durability or live command
registration has been verified. Activation depends on game handler compatibility,
authenticated async adapter, wallet/escrow and identity reconciliation.

Native cross-process tests additionally prove local same-ID delivery serialization
and exact replay after a worker exits after mock game commit. The mock game's
durable receipt establishes one synthetic settlement; this does not prove the
production game's transaction durability. Local lock acquisition is bounded at
two seconds, requests at 8 KiB and replies at 64 KiB. A busy lock returns pending.

For an asynchronous adapter, split durable enqueue and reply handling: hold flock
only while inserting immutable serialized envelope bytes and checking known
receipts, atomically publish a signed IPC file, then poll bounded reply paths with
the React event-loop timer. Resolve timeout as pending, retaining the same request
bytes. Validate authenticated reply binding before terminal journal storage. Do
not call this synchronous client from a Discord interaction callback; the current
implementation belongs in an isolated worker until this split adapter is built.
