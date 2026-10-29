# Inert operation admission boundary

This is an application API and serial fake test, **not a production storage provider**.
No caller or route instantiates it at runtime. There is no transport dependency and no
controller call, listener, database migration, production journal or live command.

`OperationAdmission` checks the independently authenticated actor against the typed
intent, configured target inventory and injected restart policy. Policy is checked
again after storage. Denial/unavailability after a reservation hides the response but
must not erase the reservation. Every replay rechecks authorization.

`OperationAdmissionStore::reserve` is the future atomic admission-index seam:
key -> (payload fingerprint, lifecycle operation ID), with outcomes reserved,
existing, conflict or uncertain. Existing means BOTH stored fields match. Keys
must never be overwritten/released on conflict. A production implementation must
use durable unique-key/transaction semantics. There is intentionally no default
provider; missing storage fails closed. Exceptions and unknown provider outcomes
are treated as uncertain, never as proof that no record exists.

The service recomputes the canonical keys from typed intent identity. Its downstream
operation identity is `adm_` plus the actor/request SHA256 key (68 permitted ASCII
characters). It remains stable even if the admission response is lost. The payload
fingerprint binds target/action; reusing a request ID for another target conflicts.
The hashes/ID are not secrets, signed capabilities or authorization tokens.

## Align with the existing lifecycle journal

At source f104515, `Local/OpenDreamControl::request` accepts the explicit operation
ID for a versioned restart; `recover` issues a read-only operation-status query.
Existing receipt validation binds operation ID, server/action/map and digest.
Preserve this consumer and the controller's authoritative lifecycle journal rather
than creating an admin copy of game execution state. The admission index only binds
the authenticated logical request to that existing operation identity.

**Concrete future integration:** a reviewed durable implementation of the reserve
interface, followed by a separate dispatcher resolving the trusted adapter/target
generation and rechecking action policy/readiness. Pass the retained operation ID
to that versioned adapter. Reserve/write ordering alone cannot provide exactly-once
game execution. A crash/timeout after dispatch requires read-only reconciliation.

All responses here have execution_enabled=false and retry_dispatch=false. An
existing reservation says existing_requires_reconciliation, never “run again”.
Existing consumer pending/not_found recovery results do not authorize mutation
replay. A committed downstream receipt is not invented by this admission API.
Save-only/arbitrary verbs remain unsupported. No existing UI capability is enabled.

## Verification limits

`php tests/admin-console/operation-admission.php` tests stable serial replay,
conflicts, revocation before/after storage, lost acknowledgements, unknown outcomes,
unavailable policy/provider and downstream identity syntax using in-memory fakes.
It does NOT prove process races, database atomicity, disk durability or live lifecycle
completion. Production-provider crash/race tests, audit retention, target-generation
binding and actual dispatch acceptance remain separate review gates.
