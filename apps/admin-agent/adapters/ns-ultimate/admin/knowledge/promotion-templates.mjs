export const promotionTemplatesArticle = {
  id: 'ns-promotion-templates',
  title: 'Promotion template DSL, types, phases and safe examples',
  module: 'Promotion',
  kind: 'guide',
  locale: 'en',
  aliases: [
    'promotion template', 'promotion templates', 'promotion dsl', 'dsl', 'promotion rule',
    '优惠模板', '促销模板', '活动模板', '优惠规则', '满减规则', '折扣规则', '赠品规则', '会员折扣规则', '免运费规则'
  ],
  sourcePath: 'core/crud-skeleton/src/Promotion/Service/Dsl/Parser.php',
  content: [
    'Supported: PromotionTemplate is ordinary configured create/update CRUD at /api/v1/manage/promotion-templates. Required fields are name, type and dsl. Optional fields are description, phase, enabled and fields. Saving parses the DSL and rejects syntax errors. It also rejects a type or phase that contradicts the DSL header. The assistant cannot call a separate validate or dry-run endpoint; save the template and read the backend error.',
    'Header: start with type: <type>. Add phase: inner or phase: outer only when it matches the phase field, and optionally priority: <number> (higher values are selected first). Then add optional when: conditions and the required do: actions. Conditions use cart.subtotal, cart.items.count, user.id and config.<key>; support comparisons and and/or/not blocks. cart.subtotal conditions are evaluated in minor units: 10000 means 100.00 in the store currency. For full_reduction, discount order amounts are major units and are converted to minor units; for discount, order and item discounts use a percentage rate.',
    'Verified action examples: full_reduction / discount order 10.00 subtracts 10.00; discount order 90% keeps 90 percent; discount items 90% applies to matching item groups and uses config.specification_ids; discount item 3 20% discounts the third eligible unit; member discount 95% applies only when the user profile is at least config.min_level; add gift spec 12 count 1 adds the specification with numeric ID 12; free shipping marks the quote for free shipping.',
    'Config references: for a full_reduction template, discount order config.amount is supported. For a discount template, discount order config.rate% and discount items config.rate% are supported; set the matching config keys on the campaign. member discount does not accept a config rate. Gift config references are not reliably parseable in the add action, so use a literal specification ID and count. Use only the config keys listed in the promotion campaign guide; unknown or mistyped keys can be ignored by the strategy.',
    'Type boundaries: full_reduction, discount, member_discount, nth_discount, gift and free_shipping can be authored with the supported headers. The tiered action syntax exists, but the current parser rejects type: tiered, so do not create a tiered template through the assistant. Existing tiered templates need manual review or an upstream parser fix. Do not change a live template type, phase or DSL without checking every campaign that references it: all of them use the same template definition.',
    'Safe example: type: full_reduction, when: cart.subtotal >= 10000, do: discount order 10.00 creates a 100.00 threshold campaign that subtracts 10.00. A discount example is type: discount, do: discount order 90%. Keep the template and campaign enabled, and set the campaign store scope separately. Use a small test campaign and a controlled order before enabling a high-impact rule. Backend parsing and strategy behavior are authoritative.',
    'Assistant boundary: templates may be created and updated after explicit UI confirmation, but not deleted. The optional fields JSON is not exposed as a bounded assistant field; manage it in the ordinary admin form if a template truly needs it. Template changes apply to future quotes only and do not rewrite existing order promotion snapshots. Never claim that a saved template is active, matches a cart or produced a specific discount without an actual backend quote.'
  ].join('\n')
}
