# Verified access-file synchronization

This optional producer fills **both** approved-player and job/customization lists from the configured Discord Verified role plus an authenticated authoritative ckey mapping. Verification remains read-only. It does not register accounts, write the verifier database, change roles, enable game restrictions, reload the world or start another Discord client.

`access_file_sync` defaults to enabled intent. A complete configured target policy may omit `enabled`; explicit `"enabled": false` opts out and constructs no owner/listener/timer/writer or destination probes. Missing target bindings never become guessed paths: an existing profile with the section omitted remains **enabled but unavailable/unconfigured**, and startup reports that its prerequisites are missing. Verification mode, roles and access files are not changed to satisfy those prerequisites. Publication and live activation still require review of exact deployment configuration and current preview; merging this source does not activate or restart the live bot.

Migration:

| Existing configuration | New behavior |
| --- | --- |
| Section omitted, or only `enabled: true` | Enabled intent; no owner or writes until both explicit target bindings, role/source/cache prerequisites are supplied. |
| Complete target settings, `enabled` omitted | Enabled using the unchanged explicit paths/policies and existing mode. |
| Explicit `enabled: false` | Fully inert opt-out; retained settings are ignored, not activated. |
| Null/string/numeric flag, or explicitly partial/malformed target configuration | Rejected; never coerced or completed with guessed destinations. |

This source default applies only through a configured enabled server binding. It does not broaden admin/faction synchronization, turn on verifier/database writes, change game restrictions, or grant live activation. Existing profiles do not acquire new destination paths merely by upgrading.

The two targets are explicit independent policies, currently each `required_roles: ["Verified"]`. A role holder without an unambiguous authoritative ckey mapping cannot be inferred: the preview counts it and publication refuses the entire proposal for both files. This also delays pending revocations for other members; existing entries remain until that mapping blocker is resolved and a fresh complete proposal succeeds. The producer never silently skips the unmapped holder or applies a partial membership set. Missing/unknown/stale source or partial member cache preserves existing files. Admin and faction files are never targets.

## Mapping origin and transport

The existing configured read-only source, http://civ13.com:55555/verified, remains available for lookup and counts/hash preview. It is **not authenticated authority for either access file**, even when the response is valid, fresh, complete, or contains an authenticated flag. Publish mode with this source is blocked before entering the file publisher. Existing files and pending revocations remain held until an authenticated fresh complete proposal succeeds; there is no partial or insecure fallback.

The public origin source exposes /verified through a plain SocketServer. This repository does not establish an available HTTPS reverse proxy or a signed-response contract. Do not change the live profile to a guessed HTTPS URL. Source support now permits an operator-explicit canonical https://civ13.com[:port]/verified in verification_source.url, keeping read-only mode; it does not choose a new endpoint or enable database writes. Other hosts, user information, query/fragment, alternate path encodings, insecure alternatives, and noncanonical/invalid ports are rejected.

For that explicit HTTPS selection the verifier constructs its own ReactPHP Browser/Connector, with certificate-chain and hostname validation on and self-signed certificates disallowed. It does not inherit a shared injected Browser's connector, proxy or insecure TLS options. Redirects of every status, including HTTP downgrades and same-host HTTPS redirects, are refused. A failed HTTPS request may retry the identical endpoint once under the existing 10-second request/4-MiB response bounds; it never retries over HTTP. The two attempts have separate 10-second request bounds, not a combined 10-second deadline. Platform resolver discovery during Connector construction is synchronous (the installed Windows ReactPHP version can run WMIC); those setup calls are not preempted by the HTTP deadline. Deployment review must assess that startup/setup behavior separately. Offline controls inspect the pure factory policy and inject responses rather than constructing that platform resolver.

Only this dedicated transport can mint the local receipt used by the snapshot: it binds the exact selected source URL and canonical normalized record bytes. Remote JSON flags/objects cannot create a receipt. Publication rechecks that receipt against the current exact records, freshness, settled refresh state and configured verifier, before entering the publisher and through the existing final commit guard. Retained data after a failed/in-flight/stale refresh is unavailable for authority; replacing HTTPS data with HTTP does not inherit an earlier receipt. Preview exposes separate authenticated_mapping and publication_authorized booleans. A mapping hash is an identity check, not authentication by itself.

The legacy GameServer whitelistUpdate path also refuses read-only mappings without this authority before touching its whitelist destination. Existing writable legacy verification, admin and faction policies are unchanged; this is not a claim that all legacy mapping consumers have been migrated. Automatic legacy whitelist listeners remain disabled in read-only mode.

Deployment remains unconfigured and held. The operator must establish and review the authoritative origin behind the exact HTTPS URL, with a valid public trust chain and matching hostname, and review the resulting current preview before any live access publication. No endpoint availability, real TLS handshake, real member mapping or consumer activation is proved by mocked tests. TLS authenticates the selected server, not the integrity of a compromised server or its database. No certificate bypass, new key, credential, firewall change or network/security setting is included.

Signed mappings are not an implemented fallback. If HTTPS cannot authenticate the authoritative origin, separately reviewed inactive support would need an operator-approved public verification key, canonical signed bytes binding schema/source/snapshot/expiry, strict signature and replay checks, and an explicit key-rotation/revocation policy. Keys must not be generated, installed or implicitly trusted as part of this change.

## Profile and preview

A portable profile may contain the following **example only**, using operator-selected paths, guild and Verified role already configured elsewhere. Do not copy another installation's credentials or enable remote verifier writes.

```json
"access_file_sync": {
  "enabled": true,
  "mode": "preview",
  "max_bytes": 4194304,
  "debounce_seconds": 0.25,
  "refresh_timeout_seconds": 30,
  "targets": {
    "approved": {
      "path": "D:/example/Civ13/SQL/approved.txt",
      "required_roles": ["Verified"],
      "allow_creation": false,
      "allow_empty": false,
      "metadata_policy": "inherit-parent"
    },
    "whitelist": {
      "path": "D:/example/Civ13/SQL/whitelist.txt",
      "required_roles": ["Verified"],
      "allow_creation": false,
      "allow_empty": false,
      "metadata_policy": "inherit-parent"
    }
  }
}
```

Paths must match the one enabled profile server's explicit `basedir/SQL/approved.txt` and `basedir/SQL/whitelist.txt`. This version refuses simultaneous `admin_file_sync` activation: two reconnect/cache owners must not refresh the same guild independently. Existing admin sync is unchanged. A future combined composition needs explicit shared-owner review.

`mode: preview` evaluates fresh proposals without file probes or writes. `Civ13::accessFileSyncPreview()` returns only state, per-kind record/byte counts and proposed SHA256, and an unmapped-role-holder count. Stale/unready/configuration-changed previews return unavailable. The bounded synchronization log records the same sanitized proposal plus per-target publication receipts, never a member list, ckey, Discord ID, private path or response body. A proposal hash is not evidence of game consumption.

`mode: publish` is an explicit later activation. `allow_creation` must be approved for missing targets; `allow_empty` must be approved only with the compatible consumer. An authoritative empty is exactly one LF byte, not zero bytes. `metadata_policy: inherit-parent` acknowledges that staged inode replacement uses metadata inherited by a new sibling file; it does not preserve an old target's ACL/owner/inode. Review the operator-owned local directory and its existing metadata before activation. The producer never changes ACLs, ownership, privilege or security settings to make a replacement succeed.

## Readiness and events

`AccessFileSync` reuses `AdminCacheSyncTrigger` and `AdminSyncCoordinator`. Normal Discord init/loadAllMembers completion starts the first projection; target-guild member/role changes and successful existing hosted-verification refresh invalidate pending work. Reconnect closes the readiness gate while one composed existing member/role refresh drains. No new poller, member store, client, permissions or per-event member fetch is introduced. The cached guild `member_count` must match the complete captured member list; unavailable counts, required role, source success/freshness, epoch or generation refuse projection.

Precommit rechecks the exact source records/success timestamp, verifier instance, guild/role/server/configuration identity, cache epoch/generation and current time. Preview does not bypass these guards. A timed-out unresolved refresh retains ownership and cannot enable another parallel refresh or publication. Stop removes only this owner's listeners and cancels its timers.

## File and consumer contract

Nonempty output is sorted unique ASCII `ckey=DiscordID` rows, each ending LF. Ckeys match `[a-z0-9]{1,256}`. IDs remain decimal strings matching `[1-9][0-9]{16,19}`. Canonical mapping/identity ambiguity rejects the proposal; IDs are not numerically converted. The producer accepts no CRLF or spaces in its own canonical wire. Shared synthetic cases are in `tests/local/access-list-vectors.json`; a game reader may separately support reviewed legacy whitespace migration.

Publication uses an owned random sibling stage, bounded complete writes, flush/fsync, byte verification and close, followed by a final authority guard and one same-directory `rename` per file. The stable cooperative lock is separate from replaced files. A separate bounded state file receives its pending record through a synced, verified sibling stage before target staging. A retained nonempty record blocks a replacement owner until explicit reconciliation. Clearing also syncs/verifies an empty sibling stage before atomically replacing that state file; it never truncates or flushes the live pending record. Thus a failed clearing-stage sync retains the hold. No fallible flush/read follows a successful state rename. Clearing is allowed only after confirmed completion or known no-publication failure with owned staging cleanup confirmed. These are ordinary process/namespace guarantees, not proof of power-loss persistence or directory durability. Never delete an active lock to bypass this barrier. It never unlinks/truncates a live destination first or falls back to copy-over. Before-commit failures retain the old destination; after-commit uncertainty or partial two-target publication latches the owner for explicit reconciliation. Per-target receipts distinguish confirmed update, preserved/absent and unconfirmed effects. Cleanup failure retains ownership uncertainty and blocks more work. No automatic I/O retry occurs.

This is **per-file atomic namespace replacement on supported local filesystems**, not a two-file transaction or a power-loss guarantee. [PHP's rename contract](https://www.php.net/manual/en/function.rename.php) requires a writable existing Windows destination. [PHP's Windows implementation](https://github.com/php/php-src/blob/PHP-8.5/win32/ioutil.c) delegates to `MoveFileExW`; same-directory staging avoids a cross-volume copy. Observed links/path/identity changes are refused. Checks do not prove absence of every reparse/path race; use an operator-owned directory and a sole writer. Filesystem calls are not preemptible by these application budgets.

On the tested Windows PHP 8.5.12 filesystem, an already-open reader caused a safe replacement refusal with both old files retained. After the reader closed, a new explicit notification published LF-empty successfully. No sharing/security setting was changed to force it. Closed-file publication/replacement and injected write/flush/rename/cleanup failures were tested in owned temporary directories. These observations do not establish all Windows ACL, filesystem or game-reader behavior.

The Civ13 consumer must stage and validate a complete file, then replace its in-memory list, including a valid LF-empty list. Missing/unreadable/zero-byte/malformed data must retain prior known-good state; initial unavailable is distinct from authoritative empty. Existing approved-list admission and conditional job-whitelist policy remain game-owned. A stale append-style Reload Lists cannot establish revocation. Consumer source work and actual activation/reload are separate acceptance gates; this producer never performs a game reload.

## Offline verification

```text
php tests/local/access-list-sync.php
php tests/local/access-file-sync.php vendor/autoload.php
php tests/local/verification-authority.php vendor/autoload.php
```

The first is dependency-free and tests actual owned temporary-file publication plus pure projection/framing. The second uses fake caches/timers/verification, an injected memory writer and an owned synthetic end-to-end publisher. It never loads bot.php, .env, a real profile, Discord or a game. Existing portable CI selects the file tests on Ubuntu/Windows PHP 8.3/8.4/8.5; the existing supervision matrix runs lifecycle tests, including its effects-disabled variant. The existing portable hosted-verifier entrypoint also selects the deterministic transport/provenance controls. No workflow trigger, permission, job or native test is added.
