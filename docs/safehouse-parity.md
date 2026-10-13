# Safehouse gameplay parity audit

Source audit pins game `cf2ee03df96468ff4d2896a2dabc15794bcde6a5` and disabled
bot PR15 `be8545fc0a279573343d24bfb61876d877b7f24a`. PR18 does not contain the
PR15 Safehouse runtime; this inventory does not duplicate or activate it.

The player entry is the owned, adjacent private house's `attack_hand` in
[mode.dm:132-151](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/mode.dm#L132-L151).
It checks session body, client ckey, home state and transition before displaying
choices, then rechecks access after the modal. These files are included by
[civ13.dme:1251-1263](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/civ13.dme#L1251-L1263).
An included API alone is not player reachability; the menu-to-handler chains below
establish source reachability, while actual player-loop acceptance remains separate.

| Bot action | Reachable game equivalent at pinned source | Remaining acceptance |
|---|---|---|
| `link.challenge`, `link.complete` | Private house "Link Discord" calls [ipc_game.dm:23-39](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/ipc_game.dm#L23-L39); owned conscious client receives the game-issued proof. Completion is integration setup. | IPC must explicitly be configured/enabled; native expiry, consumption/replay and player interaction acceptance unconfirmed for this head. |
| `stash.inspect`, `stash.detail` | "Inspect stash" calls [mode.dm:628-661](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/mode.dm#L628-L661): select saved stable root ID, inspect condition/FIR/material/ammunition, next-page graph nodes. | Source reaches the same projection; no current-head human UI/runtime evidence from this audit. |
| `room.inspect` | House admission reports level/capacity [mode.dm:492](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/mode.dm#L492), while "Inspect stash" exposes saved equipment. Bot room inspection uses the same saved snapshot as stash inspection. | Runtime parity of all displayed room fields needs confirmation; no separate management action is inferred. |
| `insurance.inspect` | "Insurance mail" calls [raid_round.dm:87-125](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/raid_round.dm#L87-L125): bounded cursor pages, pending/eligible and original condition/FIR, explicit expiration "to be implemented". | Full-round generation/recovery and player UI acceptance are not proved by bot mocks. Bot has no claim action; native eligible reclaim remains game-only. |
| `room.quote`, `room.manage` | "Upgrade" calls [mode.dm:580-626](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/mode.dm#L580-L626): select exact eligible part IDs, review 5/10/15-part cost, explicit pay/upgrade; recheck revision, snapshot, access and expiry. | Shared source policy exists; latest signed native/bot integration and player loop must be accepted before exposure. |
| `trade.quote` server buyback, `trade.confirm` | "Supplies" -> "Market" [progression.dm:85-87](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/progression.dm#L85-L87) -> "Sell to vendor" [native_market.dm:100-107](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/native_market.dm#L100-L107), then [exact quote/Confirm:87-97](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/native_market.dm#L87-L97). | Finite treasury/shared economy authority, not bot pricing. Specialist trader/category/fee extensions remain unsettled and disabled. |
| `trade.quote` player listing, `market.list` | Same market menu "List gear", selects exact FIR roots and uses the shared quote/confirmation chain above. | Native escrow/conservation current-head integration, item ownership/condition and human UI acceptance required. |
| `market.browse` | Same menu "Browse listings" [native_market.dm:117-136](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/native_market.dm#L117-L136): pages, native prices, root summaries. | Source reachable, runtime/UI not established here. No seller private identity projection. |
| `trade.quote` purchase, `market.buy` | Browse -> "Buy" -> shared exact quote and Confirm [native_market.dm:138-141](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/native_market.dm#L138-L141). | Current-head settlement/recovery and player-loop evidence pending. |
| `trade.quote` cancellation, `market.cancel` | Browse -> "Cancel own listing" -> same quote/Confirm chain, native ownership check. | No bot fee/refund policy; authoritative extended cancellation policy not frozen. |
| `trade.status` | Market "Inspect receipt" [native_market.dm:108-116](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/native_market.dm#L108-L116) -> actor's saved receipt. Native pending operations require recovery, never another transfer. | Game menu reports durable receipts; unknown/not-found status never grants replay. Recovery UX/current-head interruption acceptance still required. |

All existing fifteen bot actions have a source-visible setup/read/gameplay chain;
the previous missing native stash-detail, insurance-mail and peer-market routes
are now present. This is not evidence that an active player completed those menus
or that an API is currently enabled. No bot-only claim, auction, specialist trade,
fee/repricing, admin ledger or vehicle-key operation is exposed by this surface.
Future exposure requires a frozen authoritative DTO and reachable game action.

Access is native [supply_access:progression.dm:18-21](https://github.com/Civ13/Civ13/blob/cf2ee03df96468ff4d2896a2dabc15794bcde6a5/code/modules/1713/extraction/progression.dm#L18-L21),
not Discord role/verifier cache authority. Read/mutation rejects busy/in-raid/stale
accounts on the game side. Bot negatives may prove rejection/pending rendering
and preservation of signed metadata; they cannot prove native condition pricing,
multi-account atomicity, multiweek recovery or live raid behavior.
