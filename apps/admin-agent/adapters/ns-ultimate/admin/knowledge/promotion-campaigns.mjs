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
    'Creation order: create or enable a PromotionTemplate first, then create a Promotion. A campaign requires name and template; template is the numeric PromotionTemplate ID chosen from the relation field. Do not invent template IDs. Optional fields: description, storeCode, enabled, startTime, endTime, config and conflictMode. A campaign is eligible only when both the campaign and its template are enabled.',
    'Store scope: an empty storeCode creates a global campaign. A non-empty storeCode must exactly match the target Store code and restricts the campaign to that store. Store code is not a UUID and is not validated against a store on save; check the Store record when scoping a campaign.',
    'Conflict modes: use stackable, exclusive, best_price or lock_item. stackable allows later campaigns to continue; exclusive stops later campaigns after it applies; lock_item prevents later stacking; best_price participates in the cheapest-candidate comparison. The legacy priority option is not a backend conflict mode. Campaigns from the same phase are sorted by the DSL priority value, highest first.',
    'Scheduling: startTime and endTime are optional local datetimes. Accepted input forms include YYYY-MM-DD, YYYY-MM-DD HH:mm:ss and ISO-8601 with an offset; send an explicit offset when the intended local time matters. An explicit null clears a boundary; omitting a field leaves it unchanged. An empty boundary means no limit. Both the campaign window and the enabled flags are checked when quoting.',
    'Configuration: config is a JSON object whose keys are referenced by the template DSL as config.<key>. The assistant accepts only the declared safe subset: rate (0-100), amount (major units), specification_ids (numeric specification IDs), min_level (bronze/silver/gold/platinum/diamond), gift_spec_id and gift_qty. The gift keys apply only to existing templates whose DSL references them. Invalid or unrecognized keys are not a reliable way to extend behavior; strategies may ignore them. Item discounts use specification_ids and a rate; member discounts use min_level. The effective admin JSON editor remains authoritative for backend-specific extensions.',
    'Assistant boundary: there is no quote calculator, campaign simulation, validate/dry-run endpoint, payment call or provider action tool. Saving a campaign does not prove that it will match a particular cart; verify conditions and scope in the ordinary admin form or with a controlled test order. Changes affect future quotes and orders only. Existing orders keep the promotion snapshot recorded at creation. Never retry an uncertain submission automatically.'
  ].join('\n')
}
