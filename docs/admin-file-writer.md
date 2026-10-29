# Existing admin file writer

`Civ13\Server\AdminFileWriter` is a dedicated synchronous adapter for an explicitly selected, existing, operator-controlled local file. It is not registered with the bot or activated by configuration. The shared `Server/Ipc::replace()` method and its existing callers are unchanged.

## Explicit boundary

Construct the adapter with an absolute canonical local path and a byte budget of 1–16,777,216 bytes. It has no default path. The constructor validates strings only. Windows requires a drive-qualified path and rejects UNC/device forms and alternate-stream syntax. Relative components, redundant separators and directory-only roots are refused.

Invoke it with candidate bytes, a nonnegative generation and the coordinator's pure synchronous `canCommit` callback. The byte budget bounds both candidate and existing content. Oversized or unavailable existing files are blocked, not automatically truncated to make them fit.

The adapter never creates a missing file or parent directory, replaces an inode, changes permissions or ownership, creates links, retries a lock/write, or restores an old snapshot. Provisioning a destination is a separate operator action. The intended canonical file is bot-owned; a game deleting/recreating its input remains an unresolved integration constraint.

## Lock and commit sequence

1. Check the guard before filesystem access.
2. Inspect the existing regular file and canonical resolved path, then open `r+b`: missing paths are not created and opening does not truncate.
3. Attempt `LOCK_EX | LOCK_NB` once. Contention and other open/lock failures return `blocked` without mutation.
4. Under lock, compare pre-open, opened-handle and current-path device/inode evidence; reject missing/zero identity evidence and unsupported 32-bit PHP. Recheck the guard under lock.
5. Read bounded existing bytes, recheck path identity, rewind and call the guard again immediately before mutation or an `unchanged` acknowledgement. A false/throwing guard returns `superseded`; no asynchronous wait occurs between this final guard and writing.
6. For different bytes, complete bounded chunk writes, truncate to the exact length and flush. Empty bytes intentionally truncate. Verify available pathname identity again before returning `updated`. Always release the lock and close the handle.

`unchanged` means exact existing bytes were checked under lock and the final guard passed. It is not a successful no-op caused by missing configuration.

## Failure semantics

`blocked` is a known no-mutation refusal and is now accepted by the coordinator. A writer-level reason identifies the refused phase without exposing file contents. The coordinator reports the blocked write phase and preserves any previously confirmed writes in that reconciliation burst.

Once a write/truncate/flush may have changed the file, errors throw rather than returning `blocked`. The coordinator reports `failed` with integrity uncertainty. A zero/false/thrown write, failed truncate, failed flush or detected pathname change never reports `updated`. No rollback or hidden retry is performed. Retrying after a failure requires an explicit subsequent reconciliation decision.

The protected write/truncate/flush primitives form a narrow deterministic temporary-test seam. Normal production behavior calls PHP filesystem functions directly; no generic filesystem abstraction or alternate path resolver is introduced.

## Platform and concurrency limits

In-place writing keeps the existing file identity and does not change ACLs or ownership, but it is not transactional or crash-atomic. Noncooperating readers/writers may observe or cause partial contents. A process retaining an earlier open handle must still refer to the same file after a successful update.

Nonblocking lock acquisition does not make open, read, write or flush nonblocking, nor guarantee a wall-clock I/O deadline. Windows sharing restrictions can cause a refusal; do not change security settings or weaken identity checks to bypass it.

Modern PHP supplies Windows file identity fields, but an unavailable/zero identity or mismatched evidence fails closed. Windows mode bits do not prove ACL equality. The temporary tests check file identity and mode where observable; they do not claim a comprehensive native ACL audit.

Canonical-path, `lstat` and handle checks reject observed symlinks and path mismatches. They are not an atomic no-follow/open-by-handle boundary and do not prove the absence of every Windows reparse-point, parent-directory or path-swap race. Use an operator-controlled directory. Reliable deployment-specific identity/reparse/sharing behavior remains a Windows validation gate; no security-setting changes or symlink workarounds are included.

The guard must remain pure. It must not mutate the file/path or claim authority without fresh validated source/member evidence. Source authentication, Discord continuity and game-consumption semantics remain separate activation gates.

## Offline tests

At the repository root, using PHP 8.3 or newer:

```sh
php -l src/Civ13/Server/AdminFileWriter.php
php -l tests/local/admin-file-writer.php
php tests/local/admin-file-writer.php
php tests/local/admin-sync-coordinator.php vendor/autoload.php
```

The dependency-free filesystem test uses only its owned temporary directory. It covers guard refusal, contention, checked unchanged, LF framing, empty/shorter output, identity, bounded size, missing paths and deterministic partial failures. A temporary symlink rejection fixture runs only if the platform permits creating it; otherwise it prints an explicit skip and leaves writer safeguards unchanged. No privilege or permission setting is changed to make that fixture run.

The existing Ubuntu/Windows PHP 8.3/8.4 replica matrix runs the file test with a two-minute step timeout. The existing dependency-installed supervision matrix runs the coordinator's `blocked` outcome regression. Neither test activates a live path or game.
