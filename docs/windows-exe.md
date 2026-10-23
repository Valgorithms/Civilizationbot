# Windows executable build and configuration

`composer phpacker` packages application code and production dependencies as a
PHAR, then uses PHPacker to combine it with a pinned Windows x64 PHP runtime.
The resulting `bin/build/bot/windows/windows-x64.exe` starts with **no command
line parameters**. It reads only `civilizationbot.json` beside the physical EXE,
independently of a shortcut's Start In directory or the caller's working
directory. It never discovers a parent checkout's `.env` or falls back to the
production configuration. The source Debian `php bot.php` behavior is unchanged.

## Build

Use a clean, committed isolated checkout and PHP 8.3+ with Phar and Zip support:

```text
composer install --no-plugins --no-scripts --prefer-dist
composer phpacker
```

The committed Composer lock pins `phpacker/phpacker` 0.6.4 to
`c26b7def6578e5a35d4a99dfbb5f4c552bf4f42b`. The build refuses another installed
reference. Its critical implementation files are also checked against their pinned Git blobs
before its autoloader runs. It stages tracked public application files, installs the same lock's
production dependencies with plugins/scripts disabled, and creates the PHAR.
It seals the freshly installed production file inventory and exact locked package
references before archiving; changes to staged source or dependencies are refused.
Intentional path repositories are retained; a build requiring one stops for a
reviewed reproducible checkout rather than silently changing its source.

The runtime is the official `phpacker/php-bin` 0.4.0 source commit
`0c75828a74a42068581520a10694ce9a425465e3`, archive
`bin/win/x64/php-8.4.zip` (upstream reports PHP 8.4.15). Its exact length is
9,632,865 bytes and its Git blob SHA1 is
`4ea718fd466f56199601f21cc1560f9bbefac857`. The build checks both before reading
the archive. This is a Git object identity, not a published SHA256 checksum;
upstream supplies no release-asset SHA256 manifest for this archive. The build
records measured archive/runtime/PHAR/EXE SHA256 values in adjacent `source.json`.
This pin is a reviewed development input, not a claim of current production
runtime/security acceptance.

The wrapper calls the pinned `Combine::build()` API with an isolated process-local
cache and verifies the complete EXE equals the verified runtime plus PHAR.
It does not invoke PHPacker's CLI: that CLI loads cwd `.env`, and its build
command automatically queries/downloads latest runtimes. No global tool install
or shared PHPacker cache change is needed. `phpacker:bot` remains an alias.
The PHAR and EXE are generated build output; signing/release/deployment is separate.
Run one build at a time per feature checkout. Previous owned staging trees and
PHARs are retained under ignored `build/phpacker` paths instead of recursively
deleting them; operator cleanup is separate. The final generated EXE/receipt are
replaced on a successful build. Output-directory symlinks are refused.

## External local configuration

Create an operator-reviewed `civilizationbot.json` beside the EXE using the
existing `config/examples/windows-test1714.json` portable schema. Do not copy an
official installation's profile or environment. Supply existing absolute paths
for the local environment file, state, server and admission checkout. Keep the
separate local Discord application token in the external environment file and
set only that application's intended guild/channel/role identities in the profile.
Missing configuration reports this filename and example before loading Composer,
credentials, locks, sockets or the bot. Inherited `CIVILIZATIONBOT_PROFILE` does
not redirect the packaged entry; the adjacent file is authoritative.

The existing TEST1714, loopback, local destination and enabled-control rules are
unchanged. A source checkout remains required by the existing admission policy:
`profile.root` must pass `DefaultBranchGuard` on clean named main with fresh
origin/main, and its exact commit and Composer lock must match the EXE manifest.
An EXE built from a draft feature commit therefore cannot start the bot against
an unrelated main checkout. Rebuild from the approved main commit after merge;
the EXE never updates the checkout to make itself admissible. The existing
single-instance state lock, unresolved-stop refusal and portable restart policy
still apply. No administrator/elevation or access-denial workaround is supplied.

The bundle contains tracked `bot.php`, `variable_functions.php`, PHP source,
the packaged entry, a public provenance manifest and fresh production `vendor`.
It excludes local `.env*`, `*.env`, `token.php`, `auth.json`, operator profiles,
root configuration, logs, state, saves, Git metadata, tests, build tools and dev
dependencies. No credential/profile is copied into the build. Runtime writes
remain in the external profile's state directory, never inside the EXE/PHAR.
Only the EXE and public `source.json` belong in a CI artifact.

## Offline evidence and native smoke gate

```text
php -d phar.readonly=0 tests/windows/packaging.php
```

This dependency-free fixture packages synthetic code and runs the real PHAR entry
with zero application arguments from two unrelated working directories. A fake
autoload/bot throws if reached. It checks absent adjacent config, poisoned cwd
config, inherited profile/token exclusion, rejected admission/source/lock/runtime,
artifact inventory and absence of credential/bootstrap/state effects. It does
not download or run a Windows binary. The PR workflow runs this small fixture on
Windows/Linux PHP 8.3 and 8.4. Existing portable/bootstrap contracts remain required.

The existing `Local profile offline contracts` workflow has an explicit manual
`native_smoke=true` input, usable on the feature ref before merge. It is not run
by opening a PR; ordinary offline dispatches retain their prior behavior. After source review and
resource/approval coordination, that job runs the documented Composer build and:

```text
php tests/windows/packaged-exe-smoke.php bin/build/bot/windows/windows-x64.exe bin/build/bot/windows/source.json
```

Its fixture copies the EXE into new temporary storage, supplies synthetic paths
and an invalid synthetic environment file, and invokes the EXE with no arguments.
`CIVILIZATIONBOT_CHECK_ONLY=1` exits after profile/extension inspection, before
Git admission, credentials, state locking or bot construction. It checks missing
config and two cwd values, the physical micro self-path and package source.
Each probe is capped at five seconds. This diagnostic grants no startup authority
and does not claim actual Discord delivery or game/native acceptance.

The opt-in job is one free standard Windows CI runner, at most fifteen minutes,
with a 512MB PHP build cap, an eight-minute build step and one-minute smoke step.
Expected duration is three to eight minutes including setup and locked dependencies.
The unrelated replica/supervision matrices are skipped only for this native-only
manual dispatch; normal PR checks remain unchanged.
It downloads public locked dependencies and the 9.6MB pinned runtime ZIP; it does
not use the Desktop, bot/Gaia checkout, game saves, credentials, listeners or
services. It uploads only EXE/source.json for one day. Native acceptance is pending
until an explicitly authorized run supplies an exact-head receipt and passing
smoke; source-fixture success alone does not establish it. When a completed build's
smoke fails, the same two public artifacts are retained for diagnosis, but the job
still fails and the EXE is not accepted for activation. Failed probes report only
bounded output from the harness's synthetic, nonconnecting child environment.

Official references: [dependency/PHAR considerations](https://phpacker.dev/docs/app-considerations/),
[build/configuration](https://phpacker.dev/docs/configuration/),
[automatic runtime updates](https://phpacker.dev/docs/updating-php-binaries/),
[pinned PHPacker implementation](https://github.com/phpacker/phpacker/tree/c26b7def6578e5a35d4a99dfbb5f4c552bf4f42b),
[pinned runtime tree](https://github.com/phpacker/php-bin/tree/0c75828a74a42068581520a10694ce9a425465e3),
and [micro self-path implementation](https://github.com/easysoft/phpmicro/blob/e4ecbc0a520bcd0c44be5f2e6cad9ede3766c4fe/php_micro.c).
