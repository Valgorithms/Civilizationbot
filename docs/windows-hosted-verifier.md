# Portable hosted verification reads

An explicit portable profile may select the hosted Civilizationbot's existing
public projection with:

```json
"verification_source": {
  "mode": "read-only",
  "url": "http://civ13.com:55555/verified"
}
```

This exact HTTP GET contract comes from the bot's `SocketServer` HTTP listener
and `/verified` route. It is a legacy unsigned public read projection, not a
signed replica or authenticated TLS transport. No authority token is needed or
sent. Do not configure `CIV_TOKEN`, copy production credentials, run a local
VerifierServer, or interpret the Desktop instance as a verification authority.
The separate app's own Discord token remains private and unrelated.

The consumer uses the bot's asynchronous ReactPHP HTTP browser without a proxy,
disables redirects, bounds each request to 10 seconds and response bytes to
4 MiB, and validates at most 50,000 one-to-one
`ss13`/`discord` records from the hosted collection (JSON list or keyed object),
normalizes legacy account display spelling to the bot's existing lookup form,
rejects normalization collisions, and retains only those fields in memory. It reads at
startup and every 30 minutes, replacing the prior view even when the valid list
is empty. Non-200, timeout, malformed, duplicate or oversized responses mark
the source unavailable and retain the last valid list in memory as stale.
Lookups then reject as unavailable; the local `/verified` read endpoint reports
503 with staleness/last-success metadata. No stale disk cache or
provisional registration can become an alternative source. The projection has
no remote revision/expiry; the refresh interval is not proof of instantaneous
remote freshness.

Staff may invoke `/refreshverification` in the configured guild. It uses the
same staff-role/Moderate Members/Administrator/Ban Members check as the staff
panel, rechecks permission after an ephemeral acknowledgement, and reports
fresh/unavailable, last successful refresh UTC and retained record count.
There is one GET plus at most one retry on a transport rejection (20 seconds
total HTTP deadline); HTTP/schema failures are not retried. Concurrent refreshes and
queued bursts within ten seconds are coalesced. Failed refresh never advances
the last-success timestamp; no valid initial list remains unavailable. A view
older than 30 minutes cannot authorize even if the periodic callback is late.

Read-only mode does not register the legacy verifier's member/thread mutation
listeners, approve guild join requests or run verification-driven role/ban
timers. It does not synchronize Discord roles. Registration, token challenges,
provisioning and deletion are rejected before local or remote writes. Direct
users to the primary hosted bot to complete or change verification. Other bot
commands retain their existing authorization checks; this mode grants none.

`php tests/windows/hosted-verifier.php` uses synthetic records and an injected
asynchronous browser around the real setup/parser/write barriers. It proves
unrelated timers progress while refresh is pending. It opens no socket,
loads no environment/token and makes no Discord or hosted service change.
Live deployment additionally needs an exact endpoint read and normal runtime
ownership/activation checks. Never log response bodies or verified identities
as readiness evidence; retain only status, schema and record count.
