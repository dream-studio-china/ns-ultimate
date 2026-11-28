# Admin counter orders

The read-only workspace lives at `/counter-management/counter-orders` in the composed admin. Existing Counter configuration, order bindings, order detail routes, and Store Console permissions are unchanged. The Counter business module owns the API; all endpoints require `ROLE_ADMIN`.

| GET route | Result |
| --- | --- |
| `/api/v1/manage/counter-orders/stores` | Store UUID, name, and code; optional `q` literal name or UUID substring search |
| `/api/v1/manage/counter-orders` | Counter card data; optional `store` UUID, `counter` literal search of name, code, or UUID, and order filters |
| `/api/v1/manage/counter-orders/{counter}/orders` | Trade orders ordered by `createdAt DESC, id DESC`, with nullable `sessionUuid` and `sessionStartedAt`; the first three on page one include receipt item previews |
| `/api/v1/manage/counter-orders/{counter}/orders/{order}/graph` | Receipt `nodes`, direct `edges` (`source` → `target`), and a `truncated` flag |

Collections return `items` and `hasNext`, with 24 rows per one-based `page`. Counter results require a **single bound order** to satisfy all supplied order filters. Filters are `order` (Trade UUID, Store Order UUID, or numeric Trade ID with optional `#`), `user` (current Trade order owner UUID), `status`, `payment`, `operational` (comma-separated state values within one field are ORed), `from` (inclusive), `before` (exclusive), `merged` and `anonymous` (`yes` or `no`). Date inputs map browser-local calendar days to UTC instants. Missing Store projections do not exclude orders unless a projection-dependent filter is applied. Amounts are order totals in minor units, not evidence of payment.

Each counter card has `unpaidTotals`, a list of `{ currency, amount }` sums in minor units for its current, unended session. Only store-scoped `draft`, `pending`, or `confirmed` Trade orders without a paid or refunded payment status contribute, independently of the history filters. The overview highlights cards with a nonempty list (including zero-value orders) and shows the amount per currency; other cards are gray but remain selectable.

The history drawer inherits the overview's order filters initially and may apply its own. It groups matched orders into expandable Counter Session folders, keeping orders newest-first within each folder and collecting unassigned historic orders separately. The selected order's folder opens automatically; other folders can be opened or closed independently. Selecting a history row changes the focused receipt and graph without opening the order details modal. Receipt clicks reuse the Trade order detail renderer without edit or refund actions; the full order page remains accessible.

Opening a counter adds `?counter=<uuid>` to the admin URL as a browser history entry. Returning within the workspace preserves the current overview filters and page. For a directly opened counter URL, the workspace's own Back button falls back to the overview.

The graph includes all orders bound to the selected order's session, including standalone orders and orders hidden by a history filter. A legacy order without a session starts on its own. Only persisted `OrderMerge` rows produce arrows. Additional linked orders are followed in both directions when both endpoints have the same trusted store lifecycle. The selected order is always retained and the UI warns if the 200-node graph cap is exceeded. Trade `cancelled` is gray but selectable; `merged` is a separate state.

Receipt badges use one tone per Trade status; refunded orders use brick red. Every arrow and the receipts upstream of it inherit the tone of their merge chain's terminal order, so a cancelled or refunded result colors the whole chain. Individual badges still show each order's own status.

Linked receipts can expand their complete item lists directly in the graph. Expanded receipts fetch all order lines from the existing admin order-items endpoint; graph data still carries only bounded previews. Long item lists scroll inside the receipt, and the graph recalculates arrow positions when a receipt expands or collapses.

On MySQL, apply Counter migration `Version20261128120000` before using the workspace. It aligns `counter_order_merge.source_order_uuid` and `target_order_uuid` with the Trade schema's `utf8mb4_unicode_ci`, avoiding error 1267 if the merge table inherited a different collation. It skips SQLite and already-aligned columns. The migration changes no table defaults and may rebuild or lock the merge table; review the production target, schema, and backup before applying it. If other UUID columns diverge from the project migrations, inspect the actual column collations before changing them.
