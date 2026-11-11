export const promotionCampaignsArticle = {
  id: 'ns-promotion-campaigns',
  title: 'Promotion campaigns, templates, scheduling and store scope',
  module: 'Promotion',
  kind: 'guide',
  locale: 'en',
  aliases: [
    'promotion', 'promotions', 'campaign', 'campaigns', 'discount', 'coupon',
    '满减', '折扣', '优惠', '促销', '活动', '优惠券', '会员折扣', '赠品', '免运费',
    '创建优惠', '修改优惠', '活动配置'
  ],
  sourcePath: 'business/backend/Promotion/README.md',
  content: [
    'Supported: Promotion and PromotionTemplate are ordinary configured create/update capabilities at /api/v1/manage/promotions and /api/v1/manage/promotion-templates. Creation is previewed locally and submitted only after explicit UI confirmation; backend authorization and validation remain authoritative. Deletion is prohibited by assistant policy.',
    'Creation order: build and check a template/config pair, obtain UI confirmation for the template, then create a Promotion using its acknowledged numeric PromotionTemplate ID. Do not invent template IDs or enable a template merely for testing. A campaign requires name and template. Optional fields: description, storeCode, enabled, startTime, endTime, config and conflictMode. Eligibility requires both campaign and template enabled.',
    'Store scope: an empty storeCode creates a global campaign. A non-empty storeCode must exactly match the target Store code and restricts the campaign to that store. Store code is not a UUID and is not validated against a store on save; check the Store record when scoping a campaign.',
    'Conflict modes: use stackable, exclusive, best_price or lock_item. stackable allows later campaigns to continue; exclusive stops later campaigns after it applies; lock_item prevents later stacking; best_price participates in the cheapest-candidate comparison. The legacy priority option is not a backend conflict mode. Campaigns from the same phase are sorted by the DSL priority value, highest first.',
    'Scheduling: startTime and endTime are optional local datetimes. Accepted input forms include YYYY-MM-DD, YYYY-MM-DD HH:mm:ss and ISO-8601 with an offset; send an explicit offset when the intended local time matters. An explicit null clears a boundary; omitting a field leaves it unchanged. An empty boundary means no limit. Both the campaign window and the enabled flags are checked when quoting.',
    'Configuration: use check_promotion_draft({templateId:<known numeric ID>}) to inspect the exact template parameterSchema, then supply complete config. Before editing an existing activity use read_promotion_draft({campaignId:<known numeric ID>}) to retrieve its validated config and template relation. Keys follow actual DSL/strategy usage, not a fixed global list. Missing, unknown and mistyped keys fail assistant previews. Amount actions use major units; cart subtotal thresholds use integer minor units. Supplied config replaces the JSON object; preserve intended existing keys explicitly. Legacy unsupported extensions require manual review.',
    'Verification: check_promotion_draft with templateId, exact config and 1-8 cases executes the real single-rule strategies without persisting anything. At least two distinct cases with expected.totalAmount must pass before campaign preparation; include threshold boundaries, non-target items and member/non-member variants as appropriate. Recheck after changing template or config. A single-rule pass does not prove catalog existence, actual prices, campaign time/store eligibility, priority, exclusivity or stacking.\n' +
    'Stacking: simulate_promotion_stack uses the real promotion calculator for mode environment (all currently eligible store campaigns), selected (exact existing campaignIds) or drafts (validated unsaved campaigns). Supply expected.totalAmount for every case, plus expected.appliedCampaignIds when order matters for existing campaigns. Report notAppliedCampaignIds, differences and whether verified is false; never claim a stack works from one case or infer why an unapplied campaign did not match. This is a single-store promotion snapshot, not base price, tax, shipping, payment, catalog or concurrency verification. Changes affect future quotes only; existing orders retain snapshots. Never retry an uncertain submission automatically.'
  ].join('\n')
}
