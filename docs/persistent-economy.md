# Persistent Discord gameplay foundation

Development scope for this milestone is Civ13 only. Civ14/SS14 is deferred.

## Existing boundaries

Civilizationbot serves both games. Civ13's convenience lookup
`Civ13\Verifier::get('discord', $discordId)` (record field `ss13`) includes
provisional registrations as well as verified records. Do not use that lookup
alone to authorize economy access. Resolve the exact Discord ID against the
authoritative verifier state and reject missing, provisional or unavailable
verification. The bot's `getVerified()` refresh can also fall back to its file
cache for an empty successful list; that fallback must not authorize an account
removed from the server. `fetchVerifiedData()` exposes the authoritative embedded
server list without that fallback and is covered by the integration check.
Civ14 uses
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
foundation implements an isolated ledger, authoritative account resolver and
disabled service boundary. None is constructed by the bot, and no command or
HTTP route is registered. `Config` defaults to disabled, with an empty realm,
server allowlist and policy version. `config/economy.example.php` exposes these
choices and is illustrative only; the bot does not load it.

`Ledger` requires PHP PDO SQLite and a local database file on durable storage.
Use a dedicated file outside the checkout with OS permissions limited to the
bot service. It stores integer units, realm, immutable event ID, game account,
delta, resulting balance, reason, validated outcome evidence and receipt timestamp. `BEGIN IMMEDIATE`
serializes competing writers; the insert and balance calculation commit
together. Exact replay returns its original receipt, including original balance;
conflicting reuse fails. Use `balance()` to obtain the current balance.
Insufficient funds and integer overflow roll back without an entry.
`Service::read()` resolves the requesting Discord ID through `AuthoritativeAccounts`
on every request. The authoritative source returns the status/JSON shape of
`Verifier::fetchVerifiedData()`. Missing, ambiguous, malformed and unavailable
snapshots fail closed; there is no provisional/cache fallback. Future transport
code must derive the Discord ID from the interaction, never a user option.
The older low-level `AccountView` remains available for compatibility, but callers
must supply an authoritative resolver; use `Service` for the configured boundary.
Accounts belong to game identities, so unlinking does not erase the ledger.

Example offline adapter: resolve realm `civ13:test:season-1` using authoritative
Civ13 verifier field `ss13`, matching the exact requesting Discord ID. Return null
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
`Service::process()` validates a bounded version-1 envelope, calls an injected
authenticator that must bind that exact envelope to transport evidence, checks
the returned server against the configured allowlist, revalidates account
ownership and calls an injected policy. The default policy is absent. It stores
the complete validated outcome, server and policy version in the same SQLite
transaction as the balance change. The foundation has no credential handling,
HTTP ingress, production signature verification or complete gameplay event store.

Accepted envelope shape: `{version: 1, run: "stable-server-run-id",
account: "player", outcome: "approved-outcome"}`. Extra fields, including amounts
or delivery/event IDs, are rejected. The authenticated server comes from the
adapter, not an envelope field. This first processor handles one final outcome
per server/run/account. Its semantic event ID derives from those three fields;
outcome type cannot turn a conflicting final outcome into a second award.
The game must persist and reuse the same run identifier across retries/restarts,
and the authenticated adapter must derive outcomes from server-owned gameplay.
A different run ID can represent a different event; the bot cannot prove that a
malicious or faulty allowlisted server used the correct run ID. The game must
persist the outcome and outbox together before acknowledging extraction.

Keep policy versions immutable and retain the same version while draining its
outbox. Replaying under a different policy version is a conflict, even if the
amount is unchanged. Revoked identities fail closed on replay as well; reconcile
previously committed receipts through an authorized audit/recovery process.
Verification is checked at request time, not locked atomically with an external
verifier's subsequent changes. No cross-service atomicity is claimed.

## Next implementation sequence

1. Approve Civ13 earning sources/amounts, death losses, wipes,
   transfers, caps and administrative correction permissions. Keep servers and
   seasons separate until shared balances are explicitly approved.
2. Add the production authenticated server transport and durable outbox adapter.
   Keep unknown events rejected and validate the exact signature/body contract.
   Add private read commands without
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

Run `php -d xdebug.mode=off tests/economy.php` and
`php -d xdebug.mode=off tests/economy-service.php`; both bypass the bootstrap that loads `.env`.
Disable Xdebug tracing to release SQLite handles before Windows fixture cleanup.
It uses a temporary database and child processes with no Discord/HTTP/game calls.
It covers duplicate/conflicting events, failed debit rollback, unknown identity,
realm separation, reopening persistence, competing spends and integer overflow.
The service runner also covers disabled defaults, injected authentication bound
to the envelope, tampering, unknown servers/accounts/outcomes, revoked accounts,
ambiguous records, outages, policy/outcome replay conflicts, audit provenance,
concurrent duplicate deliveries and the original-schema migration. Its policy and
signing key are synthetic fixtures only. This proves local storage and adapter
boundary behavior, not production authentication or actual game outcomes.

The ledger upgrades the original schema in a transaction, preserving old records
with empty evidence; those records are not retroactively server-validated. New
schema creation and migration are safe to repeat, with an account-history index.
Before production use, add bounded asynchronous storage execution:
synchronous SQLite calls can block ReactPHP for up to the busy timeout. Use one
host and local disk; do not share the SQLite file across network hosts. Add an
online SQLite backup procedure and test restoring ledger plus outcome records.
WAL files are part of live database state; copying only an active database file
is not a backup. Use compensating entries for corrections, never edit receipts.
Deployment, command registration and production economy changes need separate
authorization.
