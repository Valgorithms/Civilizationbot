# Gateway access proposal mode (not an activation guide)

This optional bot adapter submits the existing authenticated Verified-role
projection as one atomic approved/whitelist request. It does not grant
access, allocate a gateway revision, change bans, start a process, or send
a network command. An inbox receipt is **not** a receipt from the gateway.

For a PortableProfile with one enabled server, use its existing
`region_group` and `follow_front_door: true` binding. When a matching
Civ13 host consumer exists, `access_file_sync.gateway_proposals: true`
selects the adapter; the existing access sync mode, target policies and
verified mapping requirements still apply. The server's canonical
approved/whitelist target paths are used for policy validation only. The
adapter does not read or write those text files or fall back to them.
In publish mode the bot skips its legacy regional approved copies for the
opted-in group. Preview does not submit a proposal and leaves legacy copying
unchanged.

The destination is fixed: `<group-root>/front-door/access-inbox/proposal.json`.
Operators must separate this inbox from the authoritative snapshot's directory
and restrict both using OS permissions: only the authorized bot instance may
replace proposals, only the gateway host may commit revisions, and neither
should accept data from a region or a public endpoint. The request contains
exactly `group`, `approved`, and `whitelist`; it deliberately contains no
bot-controlled revision or command/target names. An explicitly allowed empty
list is represented by a newline, not a missing or empty file.

The bot requires both `gateway_verified_access` and
`gateway_verified_access_inbox` to be `true` in the bound group config. The
paired [Civ13 draft #7102](https://github.com/Civ13/Civ13/pull/7102) includes
an inbox consumer and access readers for Persistence and Barracks/Battle TDM
groups with a dedicated relay gateway. Keep the bot option disabled until the
matching host and region code and inbox permissions for the selected group have
been reviewed. Ban authority is handled separately. Local tests use synthetic
TDM and Persistence groups and do not exercise live authentication or admission.
