---
name: bot-architecture
description: Trace CivilizationBot services and authority boundaries when changing bootstrap, storage, verification or local profiles.
---

Start with `src/Civ13/Civ13.php` and the affected service constructor. `bot.php` is the ambient official bootstrap; inspecting it is safe, executing it is not an offline test. `local-bot.php` is a separate explicit profile bootstrap, not a substitute for the full router.

For local isolation read `src/Civ13/Local/Profile.php`, `ControlEndpoint.php`, `Replica.php` and `OpenDreamControl.php`. The replica is authenticated read-only verification data; it cannot grant game authority or mutate official roles. Controls need their own enabled gate and loopback authentication. Missing adapters fail closed rather than falling back to legacy server paths.

Resolve installed signatures through the selected checkout's `vendor/autoload.php` and Composer dependencies. Existing primary path repositories may be intentional; never rewrite them to make an isolated test pass. Use `tests/local/run.php` for dependency-free contracts and `tests/local/library.php <autoload>` for actual dependency behavior. Report which dependency tree was used.
