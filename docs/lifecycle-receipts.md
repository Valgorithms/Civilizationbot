# Opt-in local lifecycle receipts

Controls remain disabled by default. Set `controls.lifecycle_schema` to
`civ13.lifecycle.v1` only with a reviewed receipt-capable controller. Null retains
the existing controller protocol. There is no native fallback.

Mutations bind a caller operation ID (ASCII letters, digits, underscore or hyphen,
1..80 characters), action, nullable map ID, exact profile SHA256 and origin `cli`.
The binding digest is SHA256 of compact ASCII JSON with sorted keys:
`action`, `map_id`, `origin`, `profile_digest`. It is identity, not authentication.
Local routes independently require the configured bearer key and loopback peer.

The controller publishes `civ13.lifecycle.v1` receipts with exact fields
`schema`, `operation_id`, `binding_digest`, `request`, `outcome`, `result`.
Pending has null result; committed additionally has `launch_identity`. Committed
means the owned process action completed. It never establishes game readiness.
Launch identity is null or a bound `civ13.launch.v1` projection whose `ready` is
false. Unknown build identity cannot establish revision readiness.

Versioned HTTP mutations require a stable caller ID before execution. A deadline or lost reply
does not authorize another mutation. Query `/v1/local/servers/nomads/operation-status`
(or `tdm`) with exact `{operation_id,action}` and `map` for mapswap. The adapter
invokes only `operation-status --operation-id ID`. Query response has exact
`ok`, `schema`, `operation_status`, `receipt`; status is pending, committed or
not_found. Not_found requires null receipt and never authorizes replay.

The bot rejects altered profile, body, ID, ports, receipt schema, terminal
projection or readiness claims. Its mutation deadline is bounded (start 25s,
other actions 210s), recovery 10s and stdout 4096 bytes. It does not retry.
Pending and transport uncertainty return failure without a success acknowledgement.

Offline verification: `php tests/local/lifecycle.php vendor/autoload.php` uses
temporary profiles and injected transports, including lost replies, pending,
not_found, wrong destination/authentication and altered receipt controls. These
tests do not establish live process ownership or player reachability. Parent
directory fsync and cross-file power-loss atomicity remain host limitations.

Independent cross-language check (explicit trusted paths):

```text
<Python> tests/local/lifecycle-wire.py --controller <absolute control.py> --php <absolute php> --autoload <absolute vendor/autoload.php>
```

This imports the actual Python OperationStore, generates committed and interrupted
pending receipts in temporary storage, confirms unchanged-body replay has one
synthetic effect and changed-body replay rejects, then runs the actual PHP parser
with those vectors and altered digest/profile/origin/identity/readiness controls.
The only child is the bounded PHP verifier; no server executable or port is used.
