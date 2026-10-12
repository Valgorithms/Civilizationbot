# Safehouse economics contract v1

This pins the bot-side proposal for the native handler. It is not native handler
acceptance and does not enable trades. It extends the immutable request and
authenticated exact-byte envelope in `safehouse-integration.md`; neither envelope
version changes. Existing game link/read/signing acceptance is reported by the
game owner separately. Price-table IDs, wallet units/bounds and native escrow
implementation remain game-owned dependencies, not values invented by the bot.

## Quote and confirmation actions

All quotes require the linked actor's integer `expected_account_revision` in 0..1000000.
The request actor is the authenticated Discord actor, never a payload ckey.
`trade.quote` has exactly one of the following payloads:

| sale_kind | Other payload field | Confirmation action |
| --- | --- | --- |
| server_buyback | item_ids: array of owned top-level graph root IDs | trade.confirm |
| player_listing | item_ids: array of owned top-level graph root IDs | market.list |
| player_purchase | listing_id: opaque game listing ID | market.buy |
| listing_cancel | listing_id: opaque game listing ID owned by actor | market.cancel |

No client price, currency, quantity, seller, buyer, item metadata or graph body
is accepted. IDs select existing game records. Listing prices and buyback prices
come exclusively from approved game tables. Missing/unsupported pricing rejects
the quote; no fallback price, fee, exchange rate or substitute item is minted.

A successful quote's response `data` contains:

```text
quote_id: opaque string
confirmation_token: opaque game-issued string
confirm_action: one of the confirmation actions above
expected_account_revision: actor revision captured by quote
expires_at: canonical decimal Unix-seconds STRING
price: {currency_id: civ13.game_credits.v1, amount: bounded integer game units}
item_ids: exact top-level graph roots covered
listing_id, listing_revision: present for purchase/cancellation
```

Cancellation has no economic price effect; its quote may omit `price`. Quote
issuance does not transfer/reserve items or currency, publish a listing, or change
the account's economic revision. It may durably store the token/quote record.
The token expires at most 120 seconds after issuance, independently of its IPC
envelope TTL. Native tables and wallet rules define acceptable integer bounds.

Every confirmation payload is exactly `{confirmation_token}`. The outer action,
actor and `expected_account_revision` must match the quote. The Discord route
also requires `confirm=true`; this local UI flag is not game authority and is not
part of the economic payload. Tokens bind the action, actor/account, revisions,
full graph identity including descendants/FIR/condition, immutable approved game
price and expiry. Purchase additionally binds listing/seller/counterparty identity
and listing revision; the handler observes and checks both accounts' revisions.
The token itself conveys no client authority to replace those values.

## Atomic game effects

- `trade.confirm`: remove the quoted graph from the seller and credit the
  approved server buyback amount once in one durable native transaction.
- `market.list`: reserve the entire graph into game-owned escrow, then publish
  the listing with the captured price. Listing visibility cannot precede durable
  graph reservation. Failure must not leave a public unbacked listing.
- `market.buy`: atomically debit the buyer, credit the seller, transfer that
  exact escrow graph to the buyer and close the listing in one shared durable
  receipt. Never approximate this with two independent stash-save writes.
- `market.cancel`: only the seller may return the escrow graph and close the
  unfilled listing. Competing buy/cancel can have only one winning receipt.

Recheck linked ownership, busy/in-raid states of affected accounts, exact account
and listing revisions, graph availability/eligibility, token expiry, approved
pricing, funds, integer overflow and destination capacity at confirmation.
Reject self-purchase. Reject arbitrary descendants, duplicate graph roots and
overlapping parent/child selections. Never duplicate nested IDs, relabel FIR,
repair condition or reconstruct equivalent items. A graph transfers as the game
serialized it. Account revisions advance only as native atomic transactions
require; the response reports resulting authoritative revisions and receipt IDs.

## Idempotency, expiry and recovery

The game stores one durable result for the immutable request identity, actor and
digest. Duplicate delivery with identical binding returns that original result
even after the token was consumed or expired. A conflicting actor/body/digest
using the same request ID rejects before effects. A consumed token under a new
request ID rejects, never creates another settlement. Persist business rejections
as terminal receipts as well. A token is consumed as part of the durable winning
transaction, not before an uncommitted write that would strand recoverable work.

On a crash/uncertain transaction, preserve pending and reconcile its shared
receipt before allowing conflicting effects. A fresh signed read-only
`trade.status` request has payload `{operation_request_id,
operation_request_digest}`. Verify the original receipt's actor ownership before
disclosing it. The outer status refers to completion of the status query;
`data.operation_status` and `data.operation_receipt` refer to the original
economic operation. Never interpret a successful status query as a completed
trade. `operation_status` is pending, committed, rejected or not_found. Not-found
is a query result, not authorization to repeat an economic
operation. Expired IPC requests remain immutable and recover via this fresh
status envelope; no uncertain operation file is overwritten or silently purged.

## Rejections and pending outcomes

Agreed stable business `data.code` values: `invalid_payload`, `unlinked`,
`busy`, `in_raid`, `stale_revision`, `item_not_owned`, `item_ineligible`,
`invalid_graph`, `pricing_unavailable`, `quote_expired`, `token_invalid`,
`token_used`, `action_mismatch`, `listing_unavailable`, `self_purchase`,
`insufficient_funds`, `capacity_exceeded`, `request_conflict`. These mean outer
`status=rejected` with no new economic effects. Return game-safe explanatory
text and any safe current revision, never a replacement price or confirmation.
Authentication/body/signature failures are transport failures, not accepted
economic requests. `recovery_required` means pending with no success claim until
the durable transaction resolves. The native owner must approve these names and
DTOs before compatibility is claimed.

The agreed native prototype price table is modern_safehouse_prices_v1. These are
prototype game credits and approved allowlisted prices, never Discord currency.
Quote expiry uses decimal text because native DM cannot exactly represent current
Unix epoch numbers. IPC timestamp tokens retain the existing wire format.
Duplicate token replay still requires valid fresh IPC bounds; an expired immutable
economic envelope must recover with fresh read-only status. For not_found the
operation_receipt is absent or null; other statuses carry the original actor and
request-digest-bound durable response when available.
