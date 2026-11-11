# Server-status Play button (opt-in)

Each BYOND server can attach a **Play** HTTPS URL button to its round-status
message, including unknown/stale heartbeat and missing restored round metadata.
Aggregate status includes labeled buttons, up to Discord's 25-button limit.
The Log button remains. URL buttons need no restart-sensitive interaction listener.

Configure an operator-verified public join page in that server's options:

```php
'play' => ['url' => 'https://civ13.com/tdm/'],
```

For Persistence, use `https://civ13.github.io/persistence/` (Civ13/civ13.github.io
#59, live). civ13.com serves only the TDM shortcut, not the GitHub Pages site.
The expected BYOND target is
`byond://play.valzargaming.com:1714`, the Gaia gateway, not Rail Yard on 1715.
Do not map a linked region's Play button to its internal/direct endpoint.
The launcher gate-down fix ([Civ13 #3869](https://github.com/Civ13/Civ13/pull/3869))
explains why a reachable region is not a substitute for an unavailable gate.
The production `bot.php` sets TDM and Persistence; `tdmbattle` drops the inherited
Play link so the aggregate status shows one TDM button. A portable profile adds
`play` to its server entry the same way.
If a region has no supported public entry page, omit its Play configuration.

Absent config disables the button; invalid config logs a warning and disables it.
Only BYOND is supported. URLs require HTTPS on port 443, no credentials, query,
fragment, whitespace or private IP literal. Hostname checks are syntactic:
operators must verify public DNS, destination ownership and the page's final
join target. No game/control address is used to construct a link.

This patch adds no HTTP routes, proxy, website, doorman queries, automatic game
launch or lifecycle control. It cannot wake stopped/frozen worlds or provide the
launcher's verified wake-and-join workflow. Existing status text is unchanged;
a Play button is a shortcut, not evidence the server is online. A browser may
require a further click/permission to open BYOND, which must be installed on a
supported desktop. Mobile cannot play BYOND through the link.

## Validation and rollout

```sh
php tests/local/play-link.php
php tests/local/heartbeat.php
php tests/local/heartbeat-integration.php
php tests/local/heartbeat-playercount.php
```

Tests serialize real DiscordPHP components without credentials or network.
The heartbeat CI covers Linux/Windows and PHP 8.3/8.4/8.5. Actual Discord browser
handoff to BYOND remains an operator acceptance check. Configure only after the
public page is verified, then roll out the bot under separate authorization.
