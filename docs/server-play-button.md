# Server-status Play button (opt-in)

Each BYOND server can attach a **Play** URL button to its round-status message
(including unknown/stale heartbeat and missing restored round metadata). The
aggregate status message includes labeled Play buttons, up to Discord's 25-button
limit. The existing Log button remains. Link buttons need no interaction listener,
so old messages remain usable after a bot restart.

Discord receives an HTTPS URL, not a BYOND protocol URL. A small read-only page
in the existing bot HTTP service offers an explicit **Open in BYOND** link and
a copyable connection address. It does not auto-launch, redirect, probe the game,
or assert that it is online. Desktop BYOND must be installed; mobile clients
cannot play through this page.

## Configuration and rollout

Add to the relevant server's options (replace all example values):

```php
'key' => 'pers',
'play' => [
    'url' => 'https://join.example.org/pers/play',
    'host' => 'game.example.org',
    'port' => 1714,
],
```

The URL must end in the exact server key's `/{key}/play` path, have no query,
fragment or credentials, and use HTTPS on port 443. The game endpoint is explicit,
never inferred from a potentially private control address. Only BYOND is supported.
Absent config disables both route and button; invalid config logs a warning and
also disables both. IP literals must be global IPv4; IPv6 literals are not
currently supported. Hostnames receive syntactic checks only: operators must
verify public DNS, ownership, and intended audience before configuring them.

An operator must provide a trusted publicly reachable TLS reverse proxy for this
path, preserving the configured Host header. Expose only the specific GET/HEAD
join path, **not** the entire bot HTTP service or its administration endpoints.
No TLS, DNS, firewall, proxy, live Discord message, or server configuration changes
are performed by this patch. Deploy the bot and enable the per-server config only
after checking the page is reachable. No new public site has been published.

GET/HEAD serve a fixed configured target; other methods and query-selected targets
are rejected. Names are HTML-escaped, pages are not cached, and CSP/referrer
headers limit unintended content and data sharing. This is not an access-control
bypass. Existing aggregate status address text is unchanged.

## Validation and remaining acceptance

Run with Composer dependencies installed without scripts/plugins:

```sh
php tests/local/play-link.php
php tests/local/heartbeat.php
php tests/local/heartbeat-integration.php
php tests/local/heartbeat-playercount.php
```

Tests serialize real DiscordPHP components, check per-server destinations and
HTTP response boundaries, and cover heartbeat-only and round-status rendering.
They do not start the bot, read production credentials, contact Discord, or
launch BYOND. The existing heartbeat workflow runs these on Linux/Windows and
PHP 8.3/8.4/8.5. Actual Discord desktop/browser handoff to a locally installed
BYOND client and production reverse-proxy routing still require operator
acceptance. Opening a URL button in Discord does not itself prove a game join.
