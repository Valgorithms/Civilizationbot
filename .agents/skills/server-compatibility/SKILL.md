---
name: server-compatibility
description: Verify CivilizationBot native BYOND and OpenDream server controls, Topic and file relay contracts using isolated adapters.
---

Read `src/Civ13/GameServer.php`, `Server/Ipc.php`, `Server/FileRelay.php`, `Local/OpenDreamControl.php` and `Local/AsyncProcess.php`. OpenDream uses the trusted Python controller profile; no DreamDaemon, git update or native kill fallback is permitted. Process status and Topic readiness are separate observations.

Runtime SQL is `<runtime_dir>/SQL`; server metadata is `<runtime_dir>/serverdata.txt`. Gameplay and Topic ports are distinct. Map IDs come from the configured allowlist, never arbitrary user paths or shell text. Coordinate Python/DM contract changes with the Civ13 owner before editing shared contracts.

Run `tests/local/server-compatibility.php <autoload>`, `tests/local/opendream.php <autoload>` and `tests/local/controls.php <autoload>` as applicable. These use temporary files and injected effects or bounded synthetic PHP children, not game servers. Check record framing, denied writes, empty revocation, partial relay lines, failed delivery and lifecycle concurrency. Queueing an IPC record does not prove its application by the game. Actual server probes require separately authorized ports and ownership.
