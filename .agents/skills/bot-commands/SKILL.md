---
name: bot-commands
description: Maintain CivilizationBot message and slash commands using offline routing and interaction checks without live command registration.
---

Trace message commands through `src/Civ13/MessageHandler.php`, `MessageServiceManager.php` and `MessageCommand/Commands/`. Trace slash desired command schema and listeners separately in `src/Civ13/Slash.php`: a listener alone does not prove a registered command or permission scope.

Inspect the installed DiscordPHP interaction and Promise signatures before changing replies. Await asynchronous lifecycle outcomes; distinguish accepted work, process observation, readiness and durable game completion. Rejections and pending results must not produce success messages.

Use injected interaction/transport doubles and temporary state. Include forbidden destination, permission denial, unavailable adapter, concurrent operation and failed transport controls relevant to the change. Do not execute the normal bootstrap, register desired schemas or send test messages. Preserve unrelated existing commands and nullable optional channels.
