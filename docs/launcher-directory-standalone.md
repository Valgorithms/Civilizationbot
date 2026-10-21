# Isolated directory runtime

Use this optional entrypoint only when restarting the existing bot is unsafe or
unnecessary. It composes the same Directory, Endpoint and public-only BYOND
probes; it never constructs Civ13, GameServer or Discord, and has no bot control
routes. It does not replace the existing bot or its configured game lifecycle.

## Approval and dependency gate

Deploy a reviewed, immutable source revision to a **separate** directory. Do not
checkout/pull/reset the running bot, replace its vendor tree, touch untracked
custom handlers/secrets, or stop/restart its processes. The operator must first
verify the existing PHP runtime and Composer dependency package versions and
hashes, including every Composer `autoload_files.php` entry. No application-root,
untracked configuration, secret file or unexpected autoload side effect is
acceptable. A separately pinned dependency artifact is preferred; an existing
vendor tree may be reused read-only only after this review and with changes held
until the directory is stopped/revalidated. Merely finding vendor/autoload.php
is not evidence that it is safe or compatible. The preflight must not execute it.

The source namespace is explicitly prepended to the verified Composer loader,
so the standalone runtime uses its own pinned directory classes rather than an
older bot checkout's classes. Bot.php, .env and secret bootstrap files are never
included. Empty or malformed inventory exits before executing the autoloader.

## Pinned CI bundle

The `Isolated directory bundle` workflow installs only
`packaging/directory/composer.lock`, with plugins/scripts disabled and Composer
audit enabled. It builds and tests the minimal dependency environment on PHP
8.3, 8.4 and 8.5. The artifact from PHP 8.4 includes only directory sources,
locked dependencies, empty example inventory, documentation and license. It
contains no bot entrypoint, Discord dependency, credentials or live settings.
ReactPHP HTTP is pinned to patched 1.11.1; the older live bot's 1.11.0 dependency
must not be reused for this endpoint.

Before transfer/installation, obtain the artifact from the reviewed exact commit
and compare its external SHA256 with the trusted CI/download record. Run the
supplied `verify_bundle.py` against the archive **before extraction**. It checks
bounded sizes, safe paths/modes, no links/devices, complete file checksums, source
commit, lock hash, package inventory and empty default inventory. This establishes
artifact consistency, not independent authenticity: the trusted commit/artifact
channel and separately communicated outer hash are still required. Source and
per-file hashes are also stored inside the archive. The build produces no
production installation or public release.

Extract only into a new versioned directory outside the running bot checkout,
and use the bundle's own vendor/autoload.php. Do not replace bot dependencies.

Use a bundle containing the reviewed multi-address status selection fix when
an approved hostname has several public A records. The old single-address
selection can omit a healthy server if another public address is unavailable.

## Configuration and operation

`scripts/directory/inventory.example.json` contains an empty, disabled inventory.
The example's loopback port is illustrative, not a production reservation. The
only permitted top-level fields are integer `listen_port` (1024–65535) and list
`targets`; each target has exactly the five status-only fields documented in
[the directory contract](launcher-directory.md). Both approved public hostnames
are allowed, but only verified game ports should be configured. An unconfigured
provider remains unavailable, not an invented successful empty roster.

The entrypoint syntax is:

```
php scripts/directory/serve.php inventory.json /verified/vendor/autoload.php
```

The listener is hardwired to 127.0.0.1. There is no listen-host override. Select
an unused local port after inspecting current sockets. Only the two exact
civ13.com provider-query URLs are served; all game probes require fresh validated
public IPv4 A answers. Inventory never chooses an arbitrary URL or private probe
address. If an on-host public hostname cannot reach its game, inspect routing;
do not bypass the public-address policy by inventing an IP override.

Use an independently supervised non-root process, with no bot/game descendants
or shared lifecycle dependency. Stop affects only that listener/timer/process.
No process manager configuration is installed by this patch. A shared bounded
600-request/minute budget protects the loopback endpoint without trusting
forwarded IPs; the existing Apache proxy should enforce its normal public limits.
No credentials or raw exceptions/status appear in runtime logs. Controlled
startup diagnostics identify inventory, autoload, runtime or bind phases.

Before public routing, verify the isolated listener locally using Host civ13.com
and both exact provider queries, fresh timestamps, and real BYOND status. Then
review a narrowly scoped Apache route in the existing civ13.com SSL vhost,
preserving the raw query and Host, bypassing any general redirect, and disabling
caching/stale fallback. Review existing ProxyPass/Rewrite ordering first. Validate
Apache syntax before an approved graceful reload; do not restart Apache, Gaia,
Help, or the old bot. Retain a route/service rollback plan before activation.

This document is a deployment plan, not permission to change host configuration.
The CI tests validate composition and fail-closed behavior, not host deployment
or successful live game joins.
