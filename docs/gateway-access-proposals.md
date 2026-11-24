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
The bot's legacy regional approved-copy path skips the opted-in group.

The destination is fixed: `<group-root>/front-door/access-inbox/proposal.json`.
Operators must separate this inbox from the authoritative snapshot's directory
and restrict both using OS permissions: only the authorized bot instance may
replace proposals, only the gateway host may commit revisions, and neither
should accept data from a region or a public endpoint. The request contains
exactly `group`, `approved`, and `whitelist`; it deliberately contains no
bot-controlled revision or command/target names. An explicitly allowed empty
list is represented by a newline, not a missing or empty file.

Keep this disabled until a matching gateway host consumer and region readers
are available for the selected group. Civ13 #7102 currently supplies the
gateway authority and consumer for persistent relay groups, not this inbox
adapter or TDM gameplay enforcement. The local tests cover synthetic group
binding for both TDM and Persistence, not live authentication or admission.
