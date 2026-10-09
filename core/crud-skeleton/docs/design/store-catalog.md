# Store Catalog Model

> **Status: implemented.** Product and Specification are owned by `Store` (`src/Store/Entity/Product.php`, `src/Store/Entity/Specification.php`, tables `trade_product`/`trade_specification` retained). `Product.store` is nullable: `NULL` = shared/global, `Store` = store-private. The invariants below are enforced.

## 1. Purpose

The catalog supports both independent stores and a lightweight shared catalog without
introducing a `StoreProduct` mapping table prematurely:

```text
Store (optional owner) -> Product -> Specification
```

`Store` is always the place of sale. A Product's nullable Store relation determines
whether the catalog record is shared or private; it never makes the Store optional on
an order.

## 2. Ownership And Visibility

| Product owner | Meaning | Stores allowed to sell it |
|---|---|---|
| `Product.store = NULL` | Shared/global catalog product | Any active Store |
| `Product.store = Store A` | Store-private product | Store A only |

`Specification` belongs to `Product` and inherits its visibility. It does not duplicate
`store_id`.

At every catalog read, quote, and order creation, the server must resolve a Store context
and apply this rule:

```text
product.store IS NULL OR product.store = currentStore
```

A Specification owned by another Store must be rejected even if its UUID is known. List
filters alone are insufficient; the same check is mandatory in pricing/order resolution.

## 3. Checkout And Order Invariants

1. Every new order and quote has a server-resolved, active Store context. A shared product
   does not permit a Store-less order.
2. The selected Specification must be active, not deleted, and visible to the resolved
   Store.
3. The order records the Store snapshot, Product/Specification snapshots, and the scalar
   catalog Specification UUID. Historical payment, fulfillment, refund, and inventory
   flows never re-price or re-scope an order from current catalog state.
4. Inventory remains keyed by `storeUuid + specificationUuid`; a shared Specification can
   therefore have independent stock in each Store.
5. A Product with existing order history must not be moved between global and private
   ownership, or from one Store to another. Create a replacement Product instead.

Payment continues to reference the Trade order and its frozen amount only. It has no
catalog dependency.

## 4. Administration And Authorization

| Operation | Global Product | Store-private Product |
|---|---|---|
| Create/update/delete | Platform administrator | Platform administrator or authorized Store manager |
| Manage Store relation | Platform administrator only | Platform administrator only |
| Read from storefront | Resolved active Store | Owning Store only |
| Quote/order | Any resolved active Store | Owning Store only |

Store-scoped authorization must derive scope from the Product's owning Store. A global
Product is platform-owned; a Store-scoped role must not be able to mutate it.

## 5. Deliberate Limitation

For a shared Product, Specification price, status, title, and attributes are shared too.
Inventory is already Store-specific, but Store-specific price, publication, merchandising,
or text overrides are out of scope for this model.

When a real requirement needs different Store-level price or availability for one shared
catalog record, introduce a separate override/listing aggregate such as:

```text
Catalog Product -> Store Listing -> Store-specific price and availability
```

Do not add that layer until those differing Store-level semantics are required.

## 6. Implementation Notes

1. `Product.store_id` (`store` ManyToOne, nullable, `ON DELETE SET NULL`, index `idx_trade_product_store`) was added via `Version20260902000000`. Existing rows remain `NULL` (shared/global).
2. Catalog reads (`App\Store\Controller\App\ProductController` / `SpecificationController`) enforce row-scope via `DqlExpression` validated by `ExpressionDqlParser`: `entity.getStatus() == status && entity.getIsDeleted() == isDeleted && !entity.getStore()` (global) and `(!entity.getStore() || entity.getStore() == store)` (global or owned), and for specs `!entity.getProduct().getStore()` chains; pricing (`Trade\Service\Pricing\BasePriceCalculator`) resolves via `Trade\Service\Catalog\CatalogResolverInterface` implemented by `Store\Service\Catalog\StoreCatalogResolver` (`X-Store-Code` → `Store`), rejecting private specs without a matching Store context.
3. Manage API (`App\Store\Controller\Manage\ProductController`) accepts `store` (UUID or null) and resolves via `StoreRepository`.
4. Tables `trade_product`/`trade_specification` are retained; PHP namespaces moved to `App\Store\Entity`. `Trade\Entity\Product/Specification` and their repositories/services have been removed; `Trade` no longer owns catalog code and never imports `App\Store` – the port is `CatalogResolverInterface` / `CatalogItem`.
5. `Trade\Entity\OrderItem` now stores `specificationUuid` (scalar, indexed) plus `specificationTitle`/`specSnapshot`/`productSnapshot`; the legacy `getSpecification()`/`setSpecification()` aliases were removed. The `ManyToOne` FK `specification_id` was removed via two-step `Version20260903000000` (add column) / `Version20260903000001` (backfill `uuid` then drop FK/column, irreversible). `Trade` still owns orders and pricing orchestration, PHPStan Level 8 passes without `Authorization` `ignoreErrors` suppressions.
