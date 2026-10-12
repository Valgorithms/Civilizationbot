# Isolated local CivilizationBot profile

This profile supports a separate local application in Civ13 guild `468979034571931648`. It currently provides gateway presence and an authenticated loopback verification read endpoint. It does not register commands, relay messages, change roles, control games, or provide gameplay actions. The legacy `bot.php` remains the official entry point. Do not use that entry point for this local profile.

## Installation and offline checks

Use a separate checkout on the PC or inside WSL Debian. Install PHP 8.3 or newer, sodium, Composer and the repository dependencies with `composer install`. Preserve intentional Composer path repositories in existing installations. Under Debian, install `php-cli php-sodium php-curl php-mbstring php-xml php-zip` using the distribution's packages. On Windows, use an explicit PHP executable with sodium enabled. Game installation is documented by the separate game setup work.

Create a private PC root, with separate `state`, `private`, `nomads` and `tdm` directories. Copy `config/local.example.json` outside the checkout and replace every placeholder. Windows paths may use forward slashes. Every configured path must already exist under the PC root; symlink paths and official Linux roots are rejected. Bind addresses and both game pointers must remain `127.0.0.1`, with distinct ports. These pointers do not launch or control a game.

The human supplies a new local application/token, the official application ID, local private channel IDs, an approved official Ed25519 public key and a separate random read bearer key of at least 32 bytes. Store the token, base64 public key and read key in separate files. Restrict Debian directories to mode 700 and secret files to 600. On Windows restrict ACLs to the owning user and administrators. File ACLs remain an operator prerequisite; profile validation does not prove ownership or secure permissions.

Keep `enabled:false`. Run `php local-bot.php --check /absolute/profile.json`, `php tests/local/run.php` and `php tests/local/library.php /absolute/vendor/autoload.php`. These checks use synthetic keys, temporary storage and injected adapters. They do not load `.env` or connect to Discord. `--check` validates configuration, not current replica freshness. Activation requires separate approval and completed channel isolation review; only then set enabled and run `--run`. Token identity decoding is a fail-closed format check; the gateway identity is also checked when connected.

## Channel isolation review before activation

Record both application IDs, bot member IDs, all assigned role IDs, exact local channel IDs, official channel IDs and category/thread inheritance. Neither bot may have Administrator, including through any role or guild ownership: Administrator bypasses channel denies. Do not claim isolation if either has it.

Review exact effective permissions for each bot and each channel. Deny View Channel to the official bot member on every local category/channel, and to the local bot member on official categories/channels. Deny everyone access to private local channels and permit only approved human roles and the local bot. Check channel overrides and inherited/thread permissions individually. Avoid broad guild role changes; submit the concrete scoped plan for human review. The allowlist in this profile is additional destination policy, not proof of real Discord permissions. No live permission changes are performed by these scripts.

## Read-only verification contract

Protocol `civ13.verification-replica.v1` seals exact UTF-8 JSON body bytes with Ed25519 over `protocol + LF + body` (no trailing LF). Wire fields are exactly canonical-base64 `body` and `signature`. The signed body contains protocol, pinned authority, monotonic export revision, fixed issued_at/expires_at integer Unix seconds, and a one-to-one list of `{ss13,discord}` records. Maximum body is 4 MiB; wire is 8 MiB; at most 50,000 records. Integers are bounded to 9007199254740991. TTL is at most 86400 seconds and future skew at most five seconds.

`ExportEndpoint` is a disabled, unregistered official adapter. Its approved source must supply a consistent committed read projection with a durable monotonic export revision and fixed time metadata. Renewal requires a new revision even when records are unchanged. The legacy verifier list alone does not provide this source contract. Official installation, source publication storage, key provisioning and an authenticated export transport remain blockers. No endpoint is guessed or installed. The private signing key stays only with the official authority; the PC receives its public key.

Obtain a sealed export through an explicitly approved authenticated read-only transfer, then run `php scripts/local/sync-replica.php /absolute/profile.json /absolute/export.json`. This CLI imports a file; it does not fetch from SSH or HTTP. It rejects signature failures, stale snapshots, rollback and same-revision changed bytes. Identical replay does not republish. A corrupt previous snapshot requires operator recovery and cannot silently reset the rollback barrier.

The local endpoint permits only authenticated `GET /v1/verification-replica`, marks results non-authoritative and returns 503 for unavailable/stale data. It rejects mutation methods and exposes no control routes. Do not use replica data to grant roles or authorize gameplay mutations. Both installations must retain official verification as the sole authority.

## Supervision and remaining limits

Legacy restart remains the default. Explicit `restart_mode:supervised` stops the current bot gracefully and leaves replacement to its supervisor; it never self-spawns PHP. Repeated requests stop once, and selecting another entry point is rejected. Games need independently supervised fixed units/scopes and narrowly reviewed helper permissions. A bot unit using KillMode=control-group can kill game descendants launched by legacy PHP. Nohup/disown do not detach cgroups. KillMode=process leaves child ownership/orphan risks and requires a separate reviewed compatibility decision.

Replica publication uses a private lock, staged file flush/fsync and same-directory rename. Tests cover process interruption before publication and lock exclusion. They do not prove power-loss directory fsync ordering, hostile-owner filesystem races, production exporter consistency, multiweek freshness or actual guild permissions. No official cutover or live activation is included.
