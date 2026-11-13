import { printerSetupArticle } from './printer-setup.mjs'
import { printerTemplatesArticle } from './printer-templates.mjs'
import { printerTemplateFieldsArticle } from './printer-template-fields.mjs'
import { printerAutoPrintingArticle } from './printer-auto-printing.mjs'
import { counterArticle } from './counter.mjs'
import { wechatArticle } from './wechat.mjs'
import { storeSettingsArticle } from './store-settings.mjs'
import { storeProductArticle } from './store-product.mjs'
import { contentArticle } from './content.mjs'
import { promotionCampaignsArticle } from './promotion-campaigns.mjs'
import { promotionTemplatesArticle } from './promotion-templates.mjs'
import { supportBoundariesArticle } from './support-boundaries.mjs'

export const knowledgeArticles = [
  printerSetupArticle,
  printerTemplatesArticle,
  printerTemplateFieldsArticle,
  printerAutoPrintingArticle,
  counterArticle,
  wechatArticle,
  storeSettingsArticle,
  storeProductArticle,
  contentArticle,
  promotionCampaignsArticle,
  promotionTemplatesArticle,
  supportBoundariesArticle
]

export function getKnowledgeArticles() {
  return knowledgeArticles
}

export default knowledgeArticles
