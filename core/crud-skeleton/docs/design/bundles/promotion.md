# Promotion Bundle Design

> The Promotion module (`src/Promotion/`) provides a custom DSL-driven promotion engine.
> Promotions are defined as human-readable DSL text stored in `PromotionTemplate`,
> with per-store config values in `Promotion` instances. The engine hooks into Trade's
> price calculation pipeline as a tagged calculator, fully decoupled from Trade internals.

---

## 1. Overview

Promotion is an independent module that plugs into Trade's `PriceCalculatorInterface` chain:

```mermaid
flowchart TD
    A["OrderService::calculatePrices()"] --> B["BasePriceCalculator (-100)<br/>Trade built-in"]
    A --> C["QuantityCalculator (50)<br/>Trade built-in"]
    A --> D["PromotionCalculator (60)<br/>Promotion module (tagged trade.price_calculator)"]
    A --> E["TotalAggregator (100)<br/>Trade built-in"]
```

Key characteristics:

- **Custom DSL**: Promotion conditions and actions are written in a structured,
  human-readable mini-language — not ExpressionLanguage and not raw PHP. The DSL is
  parsed at save time, validated for syntax/semantics, cached as AST, and evaluated
  at runtime — zero runtime parse overhead.
- **Multi-store**: One `PromotionTemplate` (DSL text) can serve N stores. Each store
  creates its own `Promotion` instance with store-specific `config` values (thresholds,
  amounts, gift specs). The engine is store-agnostic.
- **Template-driven**: DSL lives in `PromotionTemplate`. `Promotion` instances carry
  only `storeCode` + `config` + time range + enabled flag.
- **Chaining**: Promotions can trigger further promotions (e.g., buy A get B, B also
  discounts). The calculator loops up to 20 iterations.
- **Phase separation**: `PHASE_INNER` (applied during item-level loop) and `PHASE_OUTER`
  (applied after inner promotions) for staged execution.
- **Decoupled**: Trade's `OrderService` has zero knowledge of promotion logic. The
  module connects via the standard `trade.price_calculator` tag.
- **Pre-defined types**: DSL `type` field maps to an execution strategy. Common types
  have dedicated strategy classes; new types are added by writing a strategy + parser
  keywords — no ExpressionLanguage hacks.

### 1.1 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `PromotionTemplate` | `promotion_template` | DSL text + type + field definitions (store-agnostic) |
| `Promotion` | `promotion` | Per-store instance: config values, time range, enabled flag |

### 1.2 DSL vs ExpressionLanguage

| Aspect | ExpressionLanguage (farm-neighbor) | DSL (this design) |
|--------|-----------------------------------|-------------------|
| Readability | Cryptic one-liners | Structured, readable text |
| Safety | Runtime exceptions, sandbox holes | Syntax is the sandbox — nothing not in grammar is executable |
| Validation | Save-as-is, crash at runtime | Lex + parse + semantic check at save time |
| Editor UX | Raw textarea | Syntax-aware editor possible (auto-complete, error underlines) |
| Storage | 3 fields (validator, executor, priorityCalculator) | 1 field (`dsl`) |
| Debugging | String eval, no trace | Parser error with line:col; execution log per step |

---

## 2. Multi-Store Architecture

```mermaid
flowchart TD
    subgraph Template["PromotionTemplate (store-agnostic)"]
        Dsl["type: full_reduction<br/>phase: inner<br/><br/>when:<br/>cart.subtotal >= config.threshold<br/>do:<br/>discount order config.amount<br/><br/>priority: config.amount<br/>fields: threshold,amount"]
        TemplateOwner["Owned by: developer / tech-ops"]
    end
    subgraph Instances["Promotion instances (one per store)"]
        StoreA["Store A<br/>threshold:200<br/>amount:20<br/>enabled:true<br/>start:7/1<br/>end:7/31"]
        StoreB["Store B<br/>threshold:300<br/>amount:50<br/>enabled:true<br/>start:7/15<br/>end:8/15"]
        StoreC["Store C<br/>threshold:150<br/>amount:10<br/>enabled:false<br/>start:null<br/>end:null"]
        InstancesOwner["Owned by: operations / merchant"]
    end
    Template -->|"1:N"| Instances
    Note["PromotionCalculator receives a storeCode from the request/context,<br/>evaluates only matching Promotion instances for the current store."]
```

---

## 3. File Structure

```text
src/Promotion/
├── Controller/
│   ├── App/
│   │   └── PromotionController.php           # Public read: list active promotions
│   └── Manage/
│       ├── PromotionController.php           # Admin CRUD
│       └── PromotionTemplateController.php  # Admin CRUD + DSL validate on save
├── Entity/
│   ├── Promotion.php
│   └── PromotionTemplate.php
├── Repository/
│   ├── PromotionRepository.php
│   └── PromotionTemplateRepository.php
├── Service/
│   ├── PromotionService.php                  # CRUD + engine: match, sort, apply
│   ├── PromotionServiceInterface.php
│   ├── PromotionTemplateService.php          # CRUD (DSL parse/validate on update)
│   ├── PromotionTemplateServiceInterface.php
│   ├── PromotionCalculator.php               # tagged trade.price_calculator, priority=60
│   └── Dsl/
│       ├── Lexer.php                         # Tokenizer
│       ├── Parser.php                        # Tokens → AST
│       ├── AstNode.php                       # AST node classes (Condition, Action, ...)
│       ├── Evaluator.php                     # AST → bool (conditions) / void (actions)
│       └── DslSyntaxException.php            # Parse error with line:col
├── Strategy/
│   ├── PromotionStrategyInterface.php        # apply(AST, context): void
│   ├── FullReductionStrategy.php             # 满减
│   ├── DiscountStrategy.php                  # 折扣
│   ├── GiftStrategy.php                      # 满赠
│   ├── NthItemDiscountStrategy.php           # N件折扣
│   ├── TieredStrategy.php                    # 阶梯优惠
│   ├── FreeShippingStrategy.php              # 免运费
│   ├── MemberDiscountStrategy.php            # 会员折扣
│   └── CustomStrategy.php                    # Escape hatch (future ExpressionLanguage)
├── Exception/
│   └── PromotionException.php
└── Resources/
    └── config/
        └── services_promotion.yaml           # Strategy registration (tagged)
```

Modified files:
- `src/Trade/Service/Pricing/PriceCalculationContext.php` — add `$user`, `$storeCode`
- `src/Trade/Service/OrderService.php` — inject user + storeCode into context in `calculatePrices()`
- `config/routes.yaml` — add `api_promotion` route group

---

## 4. Entity Design

### 4.1 PromotionTemplate

| Field | Type | Description |
|-------|------|-------------|
| `id` | int auto | Primary key |
| `uuid` | string(36) unique | UUID v4 |
| `name` | string(255) unique | Template name |
| `description` | text nullable | Human-readable description |
| `type` | string(50) | Fast classifier: `full_reduction`, `discount`, `gift`, `nth_discount`, `tiered`, `free_shipping`, `member_discount` |
| `phase` | int default 0 | Execution phase: `PHASE_INNER=0`, `PHASE_OUTER=1` |
| `enabled` | bool default false | Whether this template can be used |
| `dsl` | text | DSL text: `when`/`do`/`priority` blocks |
| `fields` | json nullable | Config field definitions for admin UI (name, type, default, required) |
| `astCache` | json nullable | Serialized AST (set by parser on save, used by engine at runtime) |
| `createdAt` | datetime_immutable | Auto-set on persist |
| `updatedAt` | datetime_immutable nullable | Auto-set on update |

Constants:
```php
public const PHASE_ALL = -1;
public const PHASE_INNER = 0;
public const PHASE_OUTER = 1;
```

### 4.2 Promotion

| Field | Type | Description |
|-------|------|-------------|
| `id` | int auto | Primary key |
| `uuid` | string(36) unique | UUID v4 |
| `name` | string(255) unique | Campaign name |
| `description` | text nullable | Human-readable description |
| `template` | ManyToOne → PromotionTemplate | Which template this instance uses |
| `storeCode` | string(50) | Store identifier (e.g., `"store-a"`, `"store-b"`) |
| `enabled` | bool default false | Whether this promotion is active |
| `startTime` | datetime_immutable nullable | Campaign start |
| `endTime` | datetime_immutable nullable | Campaign end |
| `config` | json nullable | Values for DSL `config.*` placeholders (threshold, amount, gift spec ID, etc.) |
| `conflictMode` | string(30) default `'stackable'` | `stackable` | `exclusive` | `lock_item` |
| `createdAt` | datetime_immutable | Auto-set on persist |
| `updatedAt` | datetime_immutable nullable | Auto-set on update |

Specification targeting is done via DSL conditions (`item.spec.id in config.target_specs`), not via entity relationship.

### 4.3 Conflict Modes

| Mode | Behavior |
|------|----------|
| `stackable` | Default. Promotions can stack/chain. |
| `exclusive` | Once this promotion matches, no other promotions are applied in this iteration. |
| `lock_item` | Items affected by this promotion are excluded from subsequent promotions. |

### 4.4 Conventions

All entities follow crud-skeleton conventions:
- PHP 8 attributes (`#[ORM\Entity]`, `#[ORM\Column]`)
- `#[ORM\HasLifecycleCallbacks]` with `#[ORM\PrePersist]` / `#[ORM\PreUpdate]` for timestamps
- `__toString()` returns `$this->name`
- UUID v4 generated in constructor via `App\Core\Utils\UUID::v4()`
- Money amounts: `bigint` (cents) for config values that represent prices; decimal for rates

---

## 5. DSL Design

### 5.1 Structure

```
# Comment lines start with #

type: <promotion_type>       # Fast classifier for engine routing
phase: <inner|outer>         # Execution phase (default: inner)

when:
  <condition_1>
  <condition_2>

do:
  <action_1>
  <action_2>

priority: <expression>       # Sort key (optional)

fields:
  <name>:<type>:<label>      # Config field declarations (optional)
```

### 5.2 Condition Syntax

#### Cart-level

| Condition | Example |
|-----------|---------|
| `cart.subtotal >= <value>` | `cart.subtotal >= 200.00` |
| `cart.subtotal <= <value>` | `cart.subtotal <= 500.00` |
| `cart.items.count >= <value>` | `cart.items.count >= 3` |
| `cart.items.count <= <value>` | `cart.items.count <= 10` |

#### Item-level (scoped)

| Condition | Example |
|-----------|---------|
| `item.price >= <value>` | `item.price >= 50.00` |
| `item.quantity >= <value>` | `item.quantity >= 2` |
| `item.spec.id in [id1, id2, ...]` | `item.spec.id in [42, 43, 48]` |
| `item.tags includes "<tag>"` | `item.tags includes "fruit"` |

#### User-level

| Condition | Example |
|-----------|---------|
| `user.level >= "<level>"` | `user.level >= "vip"` |
| `user.level == "<level>"` | `user.level == "gold"` |
| `user.tags includes "<tag>"` | `user.tags includes "new_user"` |

#### Config references

Config values from the `Promotion.config` JSON are referenced as `config.<key>`:

| Condition | Example |
|-----------|---------|
| `cart.subtotal >= config.threshold` | threshold filled by instance config |
| `item.quantity >= config.min_qty` | min_qty filled by instance config |

#### Logic combinators

```
when:
  and:
    - cart.subtotal >= config.threshold
    - or:
        - user.level >= "vip"
        - item.tags includes "promo"
  not:
    item.tags includes "excluded"
```

### 5.3 Action Syntax

| Action | Example | Description |
|--------|---------|-------------|
| `discount order <value>` | `discount order 20.00` | Full-reduction: subtract fixed amount |
| `discount order <rate>%` | `discount order 10%` | Percentage discount on total |
| `discount order <value> max <cap>` | `discount order 10% max 50.00` | Percentage with cap |
| `add gift spec:<id> count:<n>` | `add gift spec:config.gift_spec count:1` | Add gift item |
| `add gift spec:<id> count:<n> price:<p>` | `add gift spec:42 count:1 price:0.00` | Add gift with explicit price |
| `discount item <n> <rate>%` | `discount item 3 50%` | Nth item discount |
| `discount item <n> <value>` | `discount item 3 9.99` | Nth item fixed price |
| `tiered` + tier block | (see below) | Tiered/ladder discount |
| `free shipping` | `free shipping` | Free shipping flag |
| `member discount <rate>%` | `member discount 95%` | Member discount rate |

### 5.4 Tiered Syntax

```
type: tiered

when:
  cart.subtotal >= config.from_1

do:
  tiered:
    - from: 100.00  less: 10.00
    - from: 200.00  less: 30.00
    - from: 500.00  less: 80.00
```

### 5.5 Priority

```
priority: config.priority       # Manual priority from instance config
priority: config.amount          # Sort by discount amount
priority: config.threshold desc  # Sort by threshold descending
```

If omitted, priority is `0` (last).

### 5.6 Fields

```
fields:
  threshold:number:消费门槛(元)
  amount:number:优惠金额(元)
  gift_spec:spec:赠品规格
  min_qty:number:最低件数
```

Each field: `name:type:label`. Types: `number`, `spec`, `string`, `bool`.

---

## 6. Complete DSL Examples (All 7 Types)

### 6.1 满减 (full_reduction)

```
# Spend X, save Y

type: full_reduction
phase: inner

when:
  cart.subtotal >= config.threshold

do:
  discount order config.amount

priority: config.amount
fields:
  threshold:number:消费门槛(元)
  amount:number:优惠金额(元)
```

### 6.2 折扣 (discount)

```
# Percentage discount on entire order

type: discount
phase: outer

when:
  cart.subtotal >= config.threshold

do:
  discount order config.rate% max config.cap

priority: config.rate
fields:
  threshold:number:满额门槛(元)
  rate:number:折扣率(%)
  cap:number:最高优惠上限(元)
```

### 6.3 满赠 (gift)

```
# Buy X, get free gift Y

type: gift
phase: inner

when:
  cart.subtotal >= config.threshold

do:
  add gift spec:config.gift_spec_id count:config.gift_qty price:0.00

fields:
  threshold:number:满额门槛(元)
  gift_spec_id:spec:赠品规格
  gift_qty:number:赠品数量
```

### 6.4 N件折扣 (nth_discount)

```
# Nth item at discounted price

type: nth_discount
phase: inner

when:
  item.quantity >= config.min_qty

do:
  discount item config.position config.rate%

priority: config.rate desc
fields:
  min_qty:number:最低件数
  position:number:第几件
  rate:number:折扣率(%)
```

### 6.5 阶梯优惠 (tiered)

```
# Tiered/ladder discount — highest-matching tier wins

type: tiered
phase: inner

when:
  cart.subtotal >= 100.00

do:
  tiered:
    - from: 100.00  less: 10.00
    - from: 200.00  less: 30.00
    - from: 500.00  less: 80.00
    - from: 1000.00  less: 200.00

priority: 1000
```

### 6.6 免运费 (free_shipping)

```
# Free shipping

type: free_shipping
phase: outer

when:
  cart.subtotal >= config.threshold

do:
  free shipping

priority: 0
fields:
  threshold:number:满额免运费(元)
```

### 6.7 会员折扣 (member_discount)

```
# Member-level discount

type: member_discount
phase: outer

when:
  user.level >= config.min_level

do:
  member discount config.rate%

priority: config.rate
fields:
  min_level:string:最低会员等级
  rate:number:折扣率(%)
```

---

## 7. DSL Lexer and Parser

### 7.1 Architecture

```mermaid
flowchart TD
    A["POST /manage/promotion-templates<br/>{ dsl: 'type: full_reduction ...' }"]
    B["Lexer::tokenize(dsl)<br/>Text → [TOKEN_EOL, KEYWORD_TYPE, COLON, STRING('full_reduction'), ...]"]
    C["Parser::parse(tokens)<br/>Tokens → AstNode tree<br/>Syntax error? → DslSyntaxException('Unexpected token at line 3, col 10')"]
    D["SemanticValidator::validate(ast)<br/>All config.* refs correspond to declared fields?<br/>All spec refs exist?"]
    E["Serialize AST → JSON → store in promotion_template.astCache"]
    F["Return 200 or error"]
    A --> B --> C --> D --> E --> F
```

### 7.2 AST Node Types

```mermaid
flowchart TD
    AstProgram["AstProgram<br/>type: string · phase: int"]
    AstProgram --> Cnds["conditions: AstCondition[]"]
    AstProgram --> Acts["actions: AstAction[]"]
    AstProgram --> Prio["priority: AstExpression / null"]
    AstProgram --> Flds["fields: AstFieldDeclaration[]"]
    Cnds --> C1["AstCondition { op, left, right }"]
    Cnds --> C2["AstAnd { children: AstCondition[] }"]
    Cnds --> C3["AstOr { children: AstCondition[] }"]
    Cnds --> C4["AstNot { child: AstCondition }"]
    Acts --> A1["AstDiscount { target, value, isPercent, maxCap }"]
    Acts --> A2["AstGift { specRef, count, price }"]
    Acts --> A3["AstNthDiscount { position, rate }"]
    Acts --> A4["AstTiered { tiers: [{from, less}] }"]
    Acts --> A5["AstFreeShipping {}"]
    Acts --> A6["AstMemberDiscount { rate }"]
```

### 7.3 Runtime Evaluator

```mermaid
flowchart TD
    subgraph Cond[Evaluator::evaluateCondition(...): bool]
        direction LR
        C1["Resolve left operand<br/>(cart.subtotal → $ctx->getSubtotal())"]
        C2["Resolve right operand<br/>(config.threshold → $config['threshold'])"]
        C3["Apply operator<br/>(>=, <=, ==, !=, in, includes)"]
        C4["Return bool"]
        C1 --> C2 --> C3 --> C4
    end
    subgraph Act[Evaluator::executeAction(...): void]
        direction LR
        A1["Match action type → delegate to PromotionStrategyInterface"]
        A2["Strategy::apply(action, context, config) → mutate context"]
        A1 --> A2
    end
```

### 7.4 Strategy Registration

Each promotion type maps to a strategy class:

```yaml
services:
    _instanceof:
        App\Promotion\Strategy\PromotionStrategyInterface:
            tags: ['promotion.strategy']
```

```php
interface PromotionStrategyInterface
{
    public static function supportedType(): string;   // e.g. 'full_reduction'
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void;
}
```

```php
// FullReductionStrategy.php
#[AutoconfigureTag('promotion.strategy')]
class FullReductionStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'full_reduction';
    }

    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $amount = (int)($action->value * 100); // convert to cents
        $context->totalAmount = max(0, $context->totalAmount - $amount);
    }
}
```

Engine resolves strategy by type at runtime — no ExpressionLanguage needed.

---

## 8. Core Engine Design

### 8.1 PromotionService

```php
interface PromotionServiceInterface extends BaseServiceInterface
{
    /**
     * Find all available (enabled, passing all conditions, sorted by priority)
     * promotions for the current price calculation context.
     */
    public function getAvailable(
        PriceCalculationContext $context,
        ?int $phase = null
    ): array;

    /**
     * Get the top-ranked available promotion, or null.
     */
    public function getFirstAvailable(
        PriceCalculationContext $context,
        ?int $phase = null
    ): ?Promotion;

    /**
     * Apply a promotion's actions to mutate the context.
     */
    public function apply(
        Promotion $promotion,
        PriceCalculationContext $context
    ): void;
}
```

### 8.2 Condition Pipeline (getAvailable)

```mermaid
flowchart TD
    Start["getAvailable(context)"]
    Query["Query: enabled + time-window + storeCode match + (phase filter)"]
    Loop["For each Promotion:"]
    C1["template.enabled == true?"]
    C2["time: startTime &lt;= now &lt;= endTime?"]
    C3["specification: context items match promotion's target specs?<br/>(empty = all)"]
    C4["DSL conditions: deserialize AST → Evaluator → bool"]
    C5["conflictResolution: handle exclusive/lock_item modes"]
    Sort["Sort by priority from DSL (or 0 if none)"]

    Start --> Query --> Loop --> C1 --> C2 --> C3 --> C4 --> C5 --> Sort
```

### 8.3 Promotion Application (apply)

```mermaid
flowchart TD
    Start["apply(promotion, context)"]
    A["Get AST from template.astCache (deserialized)"]
    B["Get config from promotion.config"]
    C["Resolve strategy by template.type"]
    D["Execute each action in order:<br/>Strategy::apply(actionAst, context, config)"]
    E["Mark promotion as applied in context.meta['promotion']"]
    Start --> A --> B --> C --> D --> E
```

### 8.4 Chaining (Loop)

```php
class PromotionCalculator implements PriceCalculatorInterface
{
    private const MAX_ITERATIONS = 20;

    public function calculate(PriceCalculationContext $context): void
    {
        $appliedIds = [];
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $promotion = $this->promotionService->getFirstAvailable(
                $context, PromotionTemplate::PHASE_INNER, $appliedIds
            );
            if ($promotion === null) break;

            $this->promotionService->apply($promotion, $context);

            $innerApplied[] = [
                'promotion_id' => $promotion->getId(),
                'promotion_name' => $promotion->getName(),
                'template_name' => $promotion->getTemplate()->getName(),
                'type' => $promotion->getTemplate()->getType(),
                'config' => $promotion->getConfig(),
                'context_snapshot' => [
                    'totalAmount' => $context->totalAmount,
                    'items_count' => count($context->items),
                ],
                'iteration' => $i,
            ];

            if ($promotion->getConflictMode() === 'exclusive') break;
            if ($promotion->getConflictMode() === 'lock_item') {
                $appliedIds[] = $promotion->getId();
            }
        }

        // Phase OUTER: order-level promotions after inner loop
        // (e.g., member discount, free shipping on total after inner promotions)
        $outerPromotion = $this->promotionService->getFirstAvailable(
            $context, PromotionTemplate::PHASE_OUTER
        );
        if ($outerPromotion) {
            $this->promotionService->apply($outerPromotion, $context);
            $context->meta['promotion'] = ['inner' => $innerApplied, 'outer' => [...]];
        }
    }

    public static function getPriority(): int { return 60; }
}
```

---

## 9. Price Calculation Pipeline Integration

### 9.1 PriceCalculationContext Changes

Three new fields:

```php
class PriceCalculationContext
{
    // ... existing fields ...

    /** User object for promotion condition evaluation (member level, etc.) */
    public ?object $user = null;

    /** Store identifier for multi-store promotion filtering */
    public ?string $storeCode = null;
}

// Promotion results are written to context.meta['promotion'] —
// Trade never reads this structure, maintaining full decoupling.
```

### 9.2 OrderService Change

```php
public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
{
    $context = new PriceCalculationContext($items, $currency);
    $context->user = $this->user;
    $context->storeCode = $storeCode;
    $context->meta = $meta;    // ← bidirectional channel for calculators

    // ... existing calculator chain ...
}
```

### 9.3 Calculator Chain (Final)

| Priority | Calculator | Module | Responsibility |
|----------|-----------|--------|----------------|
| -100 | `BasePriceCalculator` | Trade | Resolve specs, extract unit prices |
| 50 | `QuantityCalculator` | Trade | `price = unitPrice * quantity` |
| **60** | **`PromotionCalculator`** | **Promotion** | **DSL eval → match → apply (loop)** |
| 100 | `TotalAggregator` | Trade | Sum into `totalAmount` |

---

## 10. API Endpoints

### 10.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/promotion-templates` | List templates |
| GET | `/api/v1/manage/promotion-templates/{id}` | Template detail (includes DSL text) |
| POST | `/api/v1/manage/promotion-templates` | Create template + DSL parse/validate |
| PUT | `/api/v1/manage/promotion-templates/{id}` | Update template + DSL parse/validate |
| DELETE | `/api/v1/manage/promotion-templates/{id}` | Delete template |
| POST | `/api/v1/manage/promotion-templates/{id}/validate` | Dry-run: parse DSL, return AST + errors |
| POST | `/api/v1/manage/promotion-templates/{id}/dry-run` | Dry-run: simulate application with sample context |
| GET | `/api/v1/manage/promotions` | List promotions (filterable by storeCode) |
| GET | `/api/v1/manage/promotions/{id}` | Promotion detail |
| POST | `/api/v1/manage/promotions` | Create promotion |
| PUT | `/api/v1/manage/promotions/{id}` | Update promotion |
| DELETE | `/api/v1/manage/promotions/{id}` | Delete promotion |

### 10.2 App (Public)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/promotions` | List currently active/available promotions (optional `?storeCode=`) |
| GET | `/api/v1/app/promotions/{id}` | Promotion detail |

---

## 11. Route Registration

```yaml
# config/routes.yaml
api_promotion:
    prefix: /api/v1
    resource:
        path: ../src/Promotion/Controller/
        namespace: App\Promotion\Controller
    type: attribute
```

---

## 12. Controller Design

### 12.1 PromoteTemplateController

```php
#[Route('/manage/promotion-templates', name: 'manage-promotion-templates-')]
#[IsGranted('ROLE_ADMIN')]
class PromotionTemplateController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['name', 'type', 'dsl'];
    protected array $acceptedCreateProperties = [
        'name', 'description', 'type', 'phase', 'enabled', 'dsl', 'fields',
    ];
    protected array $acceptedUpdateProperties = [
        'name', 'description', 'type', 'phase', 'enabled', 'dsl', 'fields',
    ];

    public function __construct(
        protected readonly PromotionTemplateServiceInterface $service,
    ) {}

    #[Route('/{id}/validate', name: 'validate', methods: ['POST'])]
    public function validateAction(int $id): JsonResponse
    {
        /** @var PromotionTemplate $template */
        $template = $this->service->get($id);
        $result = $this->service->parseDsl($template->getDsl());
        return $this->success($result);
    }

    #[Route('/{id}/dry-run', name: 'dry-run', methods: ['POST'])]
    public function dryRunAction(int $id): JsonResponse
    {
        /** @var PromotionTemplate $template */
        $template = $this->service->get($id);
        $result = $this->service->simulate($template, /* sample context */);
        return $this->success($result);
    }
}
```

DSL parsing is triggered inside `PromotionTemplateService::update()` before persisting.
If parse fails, a 422 response is returned with error details including line:column.

### 12.2 PromotionController (Manage)

```php
#[Route('/manage/promotions', name: 'manage-promotions-')]
#[IsGranted('ROLE_ADMIN')]
class PromotionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    protected array $requiredCreateProperties = ['name', 'template', 'storeCode'];
    protected array $acceptedCreateProperties = [
        'name', 'description', 'template', 'storeCode',
        'enabled', 'startTime', 'endTime', 'config', 'specifications', 'conflictMode',
    ];
    protected array $acceptedUpdateProperties = [
        'name', 'description', 'template', 'storeCode',
        'enabled', 'startTime', 'endTime', 'config', 'specifications', 'conflictMode',
    ];

    public function __construct(
        protected readonly PromotionServiceInterface $service,
    ) {}
}
```

### 12.3 App/PromotionController

Read-only; uses only `ApiView`, `DetailApiViewMixin`, `ListApiViewMixin`.
`commonFilter()` restricts to `enabled = true` and time-window active, filterable by `?storeCode=`.

---

## 13. DSL Update Flow

```mermaid
flowchart TD
    A["PUT /manage/promotion-templates/{id}<br/>body: { dsl: 'type: full_reduction ...' }"]
    B["1. Lexer tokenizes DSL text"]
    C["2. Parser builds AST"]
    C -->|"Fail"| CErr["422 { errors: [{line:3, col:10, msg: 'Unexpected token ='}] }"]
    C -->|"Pass"| D["3. Semantic validator checks AST"]
    D -->|"Fail"| DErr["422 { errors: [{field: 'config.missing_key', msg: '...'}] }"]
    D -->|"Pass"| E["4. Serialize AST → JSON"]
    E --> F["5. Set template.dsl = raw text"]
    F --> G["6. Set template.astCache = serialized AST"]
    G --> H["7. Persist"]
    H --> I["8. Return 200 { data: { template, ast } }"]
    A --> B --> C
```

---

## 14. Service DI

```yaml
# src/Promotion/Resources/config/services_promotion.yaml
services:
    _instanceof:
        App\Promotion\Strategy\PromotionStrategyInterface:
            tags: ['promotion.strategy']

    App\Promotion\Service\Dsl\:
        resource: '../../Dsl/'

    App\Promotion\Strategy\:
        resource: '../../Strategy/'
```

`App\` autowiring covers the rest. `#[AutoconfigureTag('trade.price_calculator')]`
on `PromotionCalculator` handles the pipeline tag.

---

## 15. Testing

| Suite | Tests |
|-------|-------|
| `tests/Promotion/Entity/` | Promotion, PromotionTemplate unit tests |
| `tests/Promotion/Service/Dsl/` | Lexer tokenization, Parser AST generation, error edge cases |
| `tests/Promotion/Service/Dsl/` | Evaluator conditon resolution, action execution |
| `tests/Promotion/Service/` | PromotionService getAvailable, apply, condition filter chain |
| `tests/Promotion/Strategy/` | Each strategy class: full_reduction, discount, gift, nth_discount, tiered, free_shipping, member_discount |
| `tests/Promotion/Service/` | PromotionCalculator chaining, conflict modes, max iteration limit |
| `tests/Promotion/Integration/` | Full pipeline with Trade calculators + multi-store filtering |
| `tests/Promotion/Controller/` | Manage and App endpoint tests; DSL validation endpoint |

---

## 16. Implementation Plan

### Phase 1: Core Entities and DSL Parser

1. Create `PromotionTemplate` entity with attributes, repository.
2. Create `Promotion` entity with attributes, repository.
3. Implement `Lexer` — tokenize DSL text.
4. Implement `Parser` — tokens → AST, with error recovery.
5. Implement `AstNode` classes.
6. Implement `DslSyntaxException` with line:col metadata.
7. Unit tests for Lexer + Parser (valid DSLs, error DSLs, edge cases).

### Phase 2: CRUD + DSL Validation API

1. Create `PromotionTemplateService` + interface.
2. Implement `parseDsl()` and `simulate()` methods.
3. Save-time DSL parse + semantic validation in `update()`.
4. Create `PromotionTemplateController` (Manage, admin CRUD + validate/dry-run).
5. Create `PromotionService` + interface (thin CRUD initially).
6. Create `PromotionController` (Manage, admin CRUD).
7. Create `PromotionController` (App, readonly).
8. Add `api_promotion` route group in `config/routes.yaml`.
9. Generate migration for `promotion_template` and `promotion` tables.

### Phase 3: Engine and Strategies

1. Implement `PromotionStrategyInterface` and registry.
2. Implement all 7 strategy classes.
3. Implement `Evaluator` — condition AST → bool; action AST → strategy dispatch.
4. Build `PromotionService` engine methods:
   - `getAvailable()` — query + filter + sort
   - `getFirstAvailable()` — top match
   - `apply()` — execute actions via strategy
5. Add `$user`, `$storeCode` to `PriceCalculationContext`.
6. Modify `OrderService::calculatePrices()` to inject user + storeCode.
7. Create `PromotionCalculator` implementing `PriceCalculatorInterface`.
8. Unit tests for Evaluator, engine, and each strategy.

### Phase 4: Advanced Features

1. Conflict modes (`exclusive`, `lock_item`).
2. Phase OUTER support in `PromotionCalculator`.
3. Dry-run endpoint: simulate promotions against sample cart.
4. AST cache invalidation strategy.

### Phase 5: Observability and Reporting

1. Promotion results written to `context.meta['promotion']` with before/after snapshots.
2. Promotion execution log for debugging.
3. Endpoint: `/api/v1/app/promotions` with time-window + storeCode filtering.

---

## 17. Open Questions

| Question | Current Decision |
|----------|------------------|
| Should DSL support inline math expressions? (e.g., `cart.subtotal * 0.8 >= 100`) | No — keep DSL simple. Complex math belongs in strategy classes. |
| Should `fields` declarations auto-generate admin form? | Reserved: frontend task. `fields` is the contract. |
| Should `appliedPromotions` be persisted on Order? | No. Results stored in `context.meta['promotion']`, passed through `PriceCalculationResult.meta` to frontend. |
| Max loop iterations? | 20, configurable constant in `PromotionCalculator`. |
| AST cache storage: DB JSON column or filesystem? | DB JSON column (`astCache`). Zero deployment dependency. |
| How to handle circular promotions? | Max iteration + appliedId tracking + lock_item mode. |
| Should DSL support `import` for template composition? | No — keep each template self-contained. Composition via separate instances. |

---

## 18. Acceptance Criteria

The Promotion module is complete when:

| Criteria | Requirement |
|----------|-------------|
| Entities | PromotionTemplate and Promotion entities exist with PHP 8 attributes |
| DSL Parser | Lexer + Parser correctly tokenize and AST-build all 7 DSL examples; errors carry line:col |
| CRUD | Admin can CRUD templates and promotions via API |
| Save-time validation | DSL is parsed on template save; invalid DSL is rejected with precise errors |
| Calculator | `PromotionCalculator` is auto-discovered and runs in price pipeline |
| Strategies | All 7 promotion types (full_reduction, discount, gift, nth_discount, tiered, free_shipping, member_discount) execute correctly |
| Chaining | Multiple promotions can apply sequentially (max 20 iterations) |
| Conflict modes | exclusive, lock_item, stackable all behave correctly |
| Multi-store | Promotions filter by storeCode; same template serves multiple stores |
| Decoupling | Trade `OrderService` has zero promotion-specific code |
| Tests | Unit tests cover DSL parser, evaluator, all strategies, engine, pipeline integration |
