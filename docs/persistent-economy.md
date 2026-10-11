# Persistent Discord gameplay foundation

Development scope for this milestone is Civ13 only. Civ14/SS14 is deferred.

## Existing boundaries

Civilizationbot serves both games. Civ13 identity is available through
`Civ13\Verifier::get('discord', $discordId)` (record field `ss13`); Civ14 uses
`Civ14\Verifier::get('discord', $discordId)` (field `ss14`) through its verifier
server endpoint. Use an explicit Discord lookup, not `getVerifiedItem(string)`,
which can also interpret strings as game names. Revalidate on every request.
Civ14 verification currently derives identity from OAuth session/IP correlation;
review that identity assurance before allowing valuable purchases or transfers.

`Civ13\Slash` and `src/Civ13/MessageCommand/Commands` provide command integration.
Existing commands include verification, player lists/logs, rankings and medals.
`Civ14\ServerApiTrait` exposes status/info and authenticated watchdog update/shutdown.
Those endpoints are not evidence of a stash, extraction, or purchase API.
No game contract is added by this patch. Never contact the retired persistence
service at valzargaming.com:1714.

## First milestone and this patch

Start with private `/balance` and `/history` views for the requesting verified
account, plus authenticated ingestion of outcomes from one test server. This
patch implements only the isolated ledger and account-view boundary. Neither
is constructed by the bot, and no command or HTTP route is registered. The
feature is disabled by absence of runtime wiring, with no player effects.

`Ledger` requires PHP PDO SQLite and a local database file on durable storage.
Use a dedicated file outside the checkout with OS permissions limited to the
bot service. It stores integer units, realm, immutable event ID, game account,
delta, resulting balance, reason and receipt timestamp. `BEGIN IMMEDIATE`
serializes competing writers; the insert and balance calculation commit
together. Exact replay returns its original receipt, including original balance;
conflicting reuse fails. Use `balance()` to obtain the current balance.
Insufficient funds and integer overflow roll back without an entry.
AccountView accepts an injected current-verifier resolver and offers reads only.
Its caller must derive the Discord ID from the interaction, never a user option.
Accounts belong to game identities, so unlinking does not erase the ledger.

Example offline adapter: resolve realm `civ13:test:season-1` using Civ13 verifier
field `ss13`. Return null
for unknown realms or missing links. Realm names are policy, not implicit shared
balances. No earning amounts or season policy are selected by this foundation.

## Required server contract before enabling earnings

Authenticate the sending server independently of Discord users, bind its key
to an allowlisted realm, and derive account/outcome from server-owned state.
Use a durable server outbox with stable server/round/player/outcome identifiers;
retry until the bot acknowledges a committed receipt. The ledger's event ID
must represent the semantic outcome, not a fresh delivery UUID. Otherwise two
different IDs could award the same extraction twice. A versioned, immutable
policy maps approved outcomes to integer deltas; clients never supply amounts.
Persist the full validated outcome and policy version alongside the receipt
in a future ingestion transaction. This ledger alone is not an authenticated
endpoint or a complete gameplay event store.

Example proposed envelope: `{version: 1, server: test, round: 123,
account: player, outcome: extracted, outcome_id: 123:player:final}`. Authentication,
server allowlisting, schema limits and policy evaluation precede `apply()`.
Reject death/extraction conflicts for the same final outcome. The game must
persist the outcome and outbox together before acknowledging extraction.

## Next implementation sequence

1. Approve Civ13 earning sources/amounts, death losses, wipes,
   transfers, caps and administrative correction permissions. Keep servers and
   seasons separate until shared balances are explicitly approved.
2. Add schema versioning, authenticated server ingress and outcome/policy audit
   records with fixture-backed adapter tests. Add private read commands without
   production registration, and verify serialized schemas/interaction routes.
3. Add server extraction/death outbox only where authoritative game code supports
   it. A game PR needs screenshots and reproduction steps, plus video where
   applicable; this bot-only patch has no game UI changes.
4. Expose stash/loadout reads through a game-owned API before proposing writes.
   For purchases, use a durable reservation/delivery workflow: debit reservation,
   idempotent game delivery acknowledgment, then settlement or compensation.
   A ledger debit alone cannot atomically deliver items on another server.
5. Add a website account page through authenticated server-side endpoints,
   using the same ownership checks. Keep bot/server credentials out of browsers.

Durable currency and stash must remain separate from current-round inventory.
Do not implement transfers or item mutation until their ownership, replay,
rollback and loss rules are approved. No real-money behavior is assumed.

## Verification and operations

Run `php -d xdebug.mode=off tests/economy.php`; it bypasses the existing bootstrap that loads `.env`.
Disable Xdebug tracing to release SQLite handles before Windows fixture cleanup.
It uses a temporary database and child processes with no Discord/HTTP/game calls.
It covers duplicate/conflicting events, failed debit rollback, unknown identity,
realm separation, reopening persistence, competing spends and integer overflow.
This proves local storage behavior, not authenticated ingress or game outcomes.

Before production use, add migrations and bounded asynchronous storage execution:
synchronous SQLite calls can block ReactPHP for up to the busy timeout. Use one
host and local disk; do not share the SQLite file across network hosts. Add an
online SQLite backup procedure and test restoring ledger plus outcome records.
WAL files are part of live database state; copying only an active database file
is not a backup. Use compensating entries for corrections, never edit receipts.
Deployment, command registration and production economy changes need separate
authorization.
