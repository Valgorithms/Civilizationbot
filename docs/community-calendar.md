# One-way Discord community calendar (inactive)

The existing production client can project scheduled events into a sanitized,
short-lived snapshot for the Civ13 website. No second Gateway, Discord REST
polling daemon, attendee query, new permission or registration is introduced.
The official source bootstrap is `bot.php` (the deployed file may be named
`productionbot.php`); it already requests all intents. The isolated
`local-bot.php` client requests only GUILDS and is not used by this integration.
`PublicCalendar::attach` rejects a client missing either GUILDS or scheduled-event
intents. Disabled configuration returns before reading app state or opening files.

## Visibility and data boundary

The target is the existing configured `civ13_guild_id`, not any guild selected by
an HTTP caller. Visibility is computed for @everyone plus only explicitly
selected, established onboarding mappings (`Verified`, optionally `SS14 Verified`).
Faction, notification, staff and bot roles are not baseline roles. Managed roles
and a profile carrying administrator/manage-guild/manage-channel/manage-role powers
are rejected. No actual member, creator or bot permission calculation is used.
Review the selected mappings against ordinary onboarding before activation.

External events qualify for this guild-member scope. Stage/voice events require
the matching native channel type and baseline VIEW_CHANNEL: base role union,
@everyone deny/allow, combined onboarding-role denies, combined allows. Member
overwrites are not applied to an invented member identity. Category overwrites
are not separately inherited; Discord supplies the child's actual overwrites.
Missing/obfuscated metadata and age-gated channels fail closed with degraded
coverage. Unknown event visibility never reveals titles, locations, links or IDs.
Channel age metadata must be present as the literal boolean `false`; missing,
null, nonboolean or restricted values cannot authorize export. Loss of that
known state also withdraws details of an event that was previously public.

The public row selects title, description, public external location or a generic
Discord voice/stage label, start/end times, status, stable event identity and the
original Discord event link. Mentions are replaced by `[mention]`. Creator data,
attendee lists/counts, channel names/IDs, cover images and role/member records
never enter the public snapshot. Raw data is neither written nor logged.

## Updates, freshness and durable revisions

Init, reconnect and relevant raw Gateway event/channel/role/recurrence-exception
changes invalidate the public projection **before** asynchronous library handlers
run. Changes are debounced for 0.2 seconds and reconciled by the existing guild's
roles/channels/scheduled-event repositories using `freshen`; user counts are off.
Cache Parts are obtained through the locked repository's async cache wrapper.
Every refresh branch drains before ownership is released. A 30-second timeout
or superseding generation prevents late results from publishing. Refresh failure
stays stale until another relevant change or reconnect; it does not start a retry
poller. Do not report persistent stale status as a complete sync.

Successful Gateway heartbeat ACKs renew a trusted projection's 120-second lease
without a Discord HTTP request. Reconnect/invalid-session/guild loss suspends or
invalidates it. A lost connection stops ACK renewal; the request-time read gate
returns stale with **no event details** when the lease expires or the clock moves
backward. A resumed page independently enforces expiry. This is a bounded freshness
contract, not an instantaneous recall promise for previously delivered data.

The private ledger atomically commits revisions and sanitized output before it
becomes visible. A lifetime file lock rejects another writer using that ledger.
Shutdown/destruction explicitly removes listeners and releases the lock before
relaunch. File contents are synced before rename; POSIX additionally attempts a
directory sync afterward. The Linux matrix probes that filesystem capability.
Windows and filesystems without directory fsync have a weaker power-loss boundary;
fresh-process tests establish restart recovery, not simulated power-loss recovery.
Never reset/delete a production ledger to fix startup: preserve it and recover.
UID is `discord-{guild}-{event}@civ13.github.io`; SEQUENCE increases on public
content/status changes, withdrawal and reappearance and survives restart. The
ledger stores only identity, hash, sequence, public flag and timing per event;
old public titles/descriptions are absent after withdrawal. Generic cancellation
records remove previously listed events without retaining now-restricted details.
Their timestamp is the withdrawal observation time, not the former event start;
only the previously public UID/revision is retained for reconciliation. The
consumer must label it as a removal time. Native calendar-client reconciliation
of these cancellation imports is not claimed as tested.
They are exported for 30 days; minimal revision history remains durable. Bounds
(10,000 historical IDs, 1,000 exported rows, 8 MiB private state/2 MiB public body)
fail closed and require review instead of silently truncating or resetting history.
Startup invalidates the previous process's lease before listeners/routes exist.

Missing end time is retained as `null`, not fabricated. Recurrence and its
exceptions are **not expanded**: the visible series record is retained with
`recurrence=discord_only` (or unknown), state `degraded` and an explicit note.
The website/download must say it contains the displayed instance only and direct
people to Discord for every occurrence/exception. This is not “all synced.”

## Delivery and activation boundary

`config/calendar.example.php` is inactive. Source wiring only adds the optional
`calendar_sync` option to the existing Civ13 composition. Main must separately
review the deployment file/options, established onboarding roles, private state
directory, existing web-API authority and HTTPS delivery before activation.
Use a durable private state directory outside the web root, restricted to the
existing process. Do not serve the ledger file or copy it into the website.

`enabled=true` attaches the source; the separate `public_http=true` flag registers
only GET/HEAD `/calendar/v1/snapshot` on the existing HTTP handler, under an exact
configured authority. It has no query/upstream selector, uses the existing rate
limiter, no-store/nosniff headers and CORS for `https://civ13.github.io` only. Stale
reads return 503 with no details. No control route/credential is exposed by this
adapter. This PR enables neither flag and opens no listener itself.

An approved existing HTTPS reverse proxy/public-services owner must deliver that
same path before enabling the website consumer. Preserve no-store and expiry;
never publish raw or stale “last-known-good” events as a static artifact. No new
account, credential, proxy change or Worker route is configured here. The website
consumer has its own default-off configuration and source review. Downloads are
static copies, not Discord event subscriptions; already imported copies cannot be
recalled. Thirty-day withdrawals help later imports reconcile the stable UID.

## Verification

`composer install --no-dev --no-scripts --no-plugins` reproduces the existing lock;
`php tests/calendar/run.php` runs synthetic fixtures and inert event/HTTP adapters,
never `bot.php`, `.env`, Discord or a game process. The fixture
`tests/calendar/snapshot.fixture.json` is produced by the real projection/ledger
and is also committed to the website for consumer compatibility checks. Updating
that contract requires both source reviews; it is not production data.

Locked DiscordPHP source: `212bed1e0481dab3721795e0926bf133d34a674e`.
Official references: [scheduled events and permission requirements](https://docs.discord.com/developers/resources/guild-scheduled-event),
[permission overwrite ordering and category syncing](https://docs.discord.com/developers/topics/permissions).
No live credentials, Gateway session, role changes, event publication, restart,
HTTP endpoint activation or production website sync has been tested or performed.
