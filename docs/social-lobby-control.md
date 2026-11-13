# DiscordPHP lobby control primitive (inactive)

Tracking: [Civ13/Civ13#5122](https://github.com/Civ13/Civ13/issues/5122).
**DiscordPHP is required**, not an optional backend replacement. The launcher
uses the native Discord Social SDK for client UI and audio. DiscordPHP-NHA is
an unrelated NHA agent integration and is not a voice dependency.

`Civ13\Social\LobbyRoster` is an unwired backend primitive. Nothing constructs
it from production bootstrap; there is no endpoint, command, config activation,
OAuth flow, new permission, channel linking, message relay, or native voice call.
It uses the real DiscordPHP `LobbyRepository::removeMember()` and `addMember()`
promise contracts. A caller must pass the existing bot's `$discord->lobbies`.

For one freshly observed lobby roster and an already-authorized target roster,
the primitive deduplicates string IDs, removes departed users before adding any
new user, and stops on first rejection. It does not roll back successful removals.
The in-flight guard is per service instance, not distributed locking. Use one
owner, refresh remote state before retry, and never treat a cached player-session
roster as authorization. Roster changes are not atomic and the accepted-operation
receipt does not prove a native client connected or disconnected.
The target is a frozen snapshot: if authority changes during pending requests,
it cannot supersede that snapshot. Production must add generation/expiry checks
before grants and prioritized revocation; an initial fresh snapshot is insufficient.

Before wiring this into production, implement and test authenticated world/session
binding, authoritative character state/consent and revocation, expiry, fresh remote
roster retrieval, per-room ownership and cross-process serialization. No user or
launcher-provided list can be treated as authorization. Initial conservative rooms
are capped at 25 participants; this does not establish production scaling.

## Chat migration boundary

All chat through the SDK is the long-term goal, not delivered by this primitive.
Player lobby messages require the player's OAuth2 session; bot credentials cannot
impersonate them. Preserve server-authoritative recipients, language transforms,
dead/admin/faction restrictions and moderation. Do not send private/local content
to a global lobby and merely hide it in the client. DiscordPHP moderation metadata
is a client-facing decision, not proof of server-side pre-delivery blocking.
Message metadata is not retained on the linked guild channel's copy. Linking a
guild channel is a separate, explicitly authorized decision.

## Validation

`php tests/social/roster.php` uses the actual installed DiscordPHP class and React
promises with an injected subclass that has no client/HTTP transport. It tests
ID validation, deduplication, ordering, asynchronous completion, failure stopping,
in-flight exclusion and retry guard release. No `.env` or bot bootstrap is loaded.
Live Discord, native audio, authorization integration and production behavior are
not validated. Run this with the project's supported PHP 8.3/8.4/8.5 matrix.

Source contracts reviewed at DiscordPHP `991eab3e9f5a5349eda814c9f9424ed8f5c5ebc6`:
- `src/Discord/Repository/LobbyRepository.php`
- `src/Discord/Repository/SessionLobbyRepository.php`
- `guide/parts/lobby.rst`
- `scripts/social-sdk-compatibility.md`
