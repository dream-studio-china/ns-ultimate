import { productSpecificationRows } from './product-list-updates.mjs'
import { productStoreUuid, referenceUuid } from './catalog-copy.mjs'

export const STORE_SETUP_AUDIT_STATUSES = Object.freeze({
  complete: 'complete',
  action: 'action',
  manual: 'manual',
  failed: 'failed'
})

function list(value) {
  return Array.isArray(value) ? value : []
}

function text(value) {
  return typeof value === 'string' ? value.trim() : ''
}

function activeMembers(members) {
  return list(members).filter(member => member?.status === 'active')
}

function activeProducts(products) {
  return list(products).filter(product =>
    product?.isDeleted !== true && String(product?.status || '').toLowerCase() === 'active'
  )
}

function enabledCategories(categories) {
  return list(categories).filter(category => category?.enabled !== false && category?.isDeleted !== true)
}

function enabledDevices(devices, storeUuid) {
  return list(devices).filter(device => device?.storeUuid === storeUuid && device?.enabled !== false)
}

function enabledRules(rules, storeUuid) {
  return list(rules).filter(rule => rule?.storeUuid === storeUuid && rule?.enabled === true)
}

function enabledTemplates(templates) {
  return list(templates).filter(template => template?.enabled !== false)
}

function byUuid(items) {
  return new Map(list(items)
    .filter(item => text(item?.uuid))
    .map(item => [item.uuid, item]))
}

function categoryKeys(category) {
  return [text(category?.uuid), String(category?.id ?? '')].filter(Boolean)
}

function categoryIndex(categories) {
  const index = new Map()
  for (const category of list(categories)) {
    for (const key of categoryKeys(category)) index.set(key, category)
  }
  return index
}

function findCategory(index, reference) {
  if (!reference || typeof reference !== 'object') {
    const key = text(reference)
    return key ? index.get(key) || null : null
  }
  for (const key of categoryKeys(reference)) {
    if (index.has(key)) return index.get(key)
  }
  return null
}

function activeSpecifications(product) {
  return productSpecificationRows(product).filter(specification => specification.active)
}

function productLabel(product) {
  return text(product?.name) || text(product?.uuid) || `#${product?.id ?? ''}`
}

function sampleLabel(items, readLabel, limit = 3) {
  const labels = list(items).map(readLabel).filter(Boolean)
  if (labels.length <= limit) return labels.join(', ')
  return `${labels.slice(0, limit).join(', ')} +${labels.length - limit}`
}

function issue(level, messageKey, args = []) {
  return { level, messageKey, args }
}

function failedSources(failures, keys) {
  return keys.filter(key => Boolean(failures?.[key]))
}

function buildStep(key, evidence, issues, failures) {
  const status = failures.length > 0
    ? STORE_SETUP_AUDIT_STATUSES.failed
    : issues.some(item => item.level === STORE_SETUP_AUDIT_STATUSES.action)
      ? STORE_SETUP_AUDIT_STATUSES.action
      : issues.some(item => item.level === STORE_SETUP_AUDIT_STATUSES.manual)
        ? STORE_SETUP_AUDIT_STATUSES.manual
        : STORE_SETUP_AUDIT_STATUSES.complete

  return { key, status, evidence, issues, failures }
}

function auditStore(store) {
  const issues = []
  const missing = []
  if (!text(store?.code)) missing.push('code')
  if (!text(store?.name)) missing.push('name')
  if (!text(store?.timezone)) missing.push('timezone')
  if (missing.length > 0) {
    issues.push(issue(
      STORE_SETUP_AUDIT_STATUSES.action,
      'Store required fields are missing: {0}',
      [missing.join(', ')]
    ))
  }
  const status = text(store?.status).toLowerCase()
  if (!['active', 'activate'].includes(status)) {
    issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'Store is not active'))
  }

  return buildStep('create-store', [
    { labelKey: 'Store status', value: text(store?.status) || '—' },
    { labelKey: 'Store code', value: text(store?.code) || '—' },
    { labelKey: 'Timezone', value: text(store?.timezone) || '—' }
  ], issues, [])
}

function auditMembers(members, failures) {
  const active = activeMembers(members)
  const activeOwners = active.filter(member => member?.role === 'owner')
  const sourceFailures = failedSources(failures, ['members'])
  const issues = []
  if (sourceFailures.length === 0) {
    if (active.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No active store member'))
    } else if (activeOwners.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No active store owner'))
    }
  }

  return buildStep('add-members', [
    { labelKey: 'Active members', value: String(active.length) },
    { labelKey: 'Active owners', value: String(activeOwners.length) }
  ], issues, sourceFailures)
}

function auditAssignments(storeUuid, members, assignments, failures) {
  const active = activeMembers(members)
  const activeUserUuids = new Set(active.map(member => member?.userUuid).filter(Boolean))
  const storeAssignments = list(assignments).filter(assignment =>
    assignment?.scopeType === 'store'
      && assignment?.scopeUuid === storeUuid
      && !assignment?.revokedAt
  )
  const activeMemberAssignments = storeAssignments.filter(assignment => activeUserUuids.has(assignment?.userUuid))
  const orphanAssignments = storeAssignments.filter(assignment => !activeUserUuids.has(assignment?.userUuid))
  const sourceFailures = failedSources(failures, ['members', 'assignments'])
  const issues = []

  if (!sourceFailures.length) {
    if (activeMemberAssignments.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No store-scoped role assignment for an active member'))
    }
    if (orphanAssignments.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Store role assignments belong to inactive members: {0}',
        [String(orphanAssignments.length)]
      ))
    }
    if (activeMemberAssignments.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.manual,
        'Confirm that assigned store roles cover each member\'s duties'
      ))
    }
  }

  return buildStep('grant-permissions', [
    { labelKey: 'Store role assignments', value: String(activeMemberAssignments.length) }
  ], issues, sourceFailures)
}

function auditProducts(storeUuid, categories, products, failures) {
  const localCategories = enabledCategories(categories).filter(category => referenceUuid(category?.store) === storeUuid)
  const localProducts = activeProducts(products).filter(product => productStoreUuid(product) === storeUuid)
  const categoryByKey = categoryIndex(categories)
  const withoutCategory = localProducts.filter(product => !findCategory(categoryByKey, product?.category))
  const unavailableCategory = localProducts.filter(product => {
    const category = findCategory(categoryByKey, product?.category)
    return category && (category.enabled === false || category.isDeleted === true)
  })
  const withoutPrice = localProducts.filter(product => activeSpecifications(product).length === 0)
  const withPrice = localProducts.length - withoutPrice.length
  const sourceFailures = failedSources(failures, ['categories', 'products'])
  const issues = []

  if (!sourceFailures.length) {
    if (localCategories.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No enabled store product category'))
    }
    if (localProducts.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No active store product'))
    }
    if (withoutCategory.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Active products without a category: {0}',
        [sampleLabel(withoutCategory, productLabel)]
      ))
    }
    if (unavailableCategory.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Active products use disabled or missing categories: {0}',
        [sampleLabel(unavailableCategory, productLabel)]
      ))
    }
    if (withoutPrice.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Active products without an active specification and price: {0}',
        [sampleLabel(withoutPrice, productLabel)]
      ))
    }
  }

  return buildStep('configure-products', [
    { labelKey: 'Enabled store categories', value: String(localCategories.length) },
    { labelKey: 'Active store products', value: String(localProducts.length) },
    { labelKey: 'Active products with prices', value: String(withPrice) }
  ], issues, sourceFailures)
}

function auditPrinters(storeUuid, accounts, devices, templates, failures) {
  const devicesForStore = enabledDevices(devices, storeUuid)
  const accountsByUuid = byUuid(accounts)
  const unavailableAccounts = devicesForStore.filter(device => {
    const account = accountsByUuid.get(referenceUuid(device?.account))
    return !account || account.enabled === false
  })
  const enabledTemplateList = enabledTemplates(templates)
  const deviceKinds = [...new Set(devicesForStore.map(device => text(device?.kind)).filter(Boolean))]
  const missingTemplateKinds = deviceKinds.filter(kind => !enabledTemplateList.some(template => template?.kind === kind))
  const sourceFailures = failedSources(failures, ['printerAccounts', 'printerDevices', 'printerTemplates'])
  const issues = []

  if (!sourceFailures.length) {
    if (devicesForStore.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No enabled printer device for this store'))
    }
    if (unavailableAccounts.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Enabled printers use a missing or disabled printer account: {0}',
        [String(unavailableAccounts.length)]
      ))
    }
    if (missingTemplateKinds.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'No enabled template matches printer kinds: {0}',
        [missingTemplateKinds.join(', ')]
      ))
    }
    if (devicesForStore.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.manual,
        'Verify printer power, network, and actual output'
      ))
    }
  }

  return buildStep('add-printers', [
    { labelKey: 'Enabled printers', value: String(devicesForStore.length) },
    { labelKey: 'Matching enabled templates', value: String(enabledTemplateList.filter(template => deviceKinds.includes(template?.kind)).length) }
  ], issues, sourceFailures)
}

function auditPrintRules(storeUuid, devices, templates, rules, failures) {
  const devicesByUuid = byUuid(devices)
  const templatesByUuid = byUuid(templates)
  const activeRules = enabledRules(rules, storeUuid)
  const invalidRules = activeRules.filter(rule => {
    const device = devicesByUuid.get(text(rule?.deviceUuid))
    const template = templatesByUuid.get(text(rule?.templateUuid))
    return !device || device.enabled === false || device.storeUuid !== storeUuid
      || !template || template.enabled === false || template.kind !== device.kind
      || !Array.isArray(rule?.events) || rule.events.length === 0
      || !Number.isInteger(Number(rule?.copies)) || Number(rule.copies) < 1
  })
  const sourceFailures = failedSources(failures, ['printerDevices', 'printerTemplates', 'printerRules'])
  const issues = []

  if (!sourceFailures.length) {
    if (activeRules.length === 0) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No enabled order print rule for this store'))
    }
    if (invalidRules.length > 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.action,
        'Enabled print rules have invalid device, template, event, or copies settings: {0}',
        [sampleLabel(invalidRules, rule => text(rule?.name) || text(rule?.uuid))]
      ))
    }
    if (activeRules.length > 0 && invalidRules.length === 0) {
      issues.push(issue(
        STORE_SETUP_AUDIT_STATUSES.manual,
        'Confirm the print dispatch worker is running and test an order print'
      ))
    }
  }

  return buildStep('configure-print-rules', [
    { labelKey: 'Enabled print rules', value: String(activeRules.length) },
    { labelKey: 'Valid enabled print rules', value: String(activeRules.length - invalidRules.length) }
  ], issues, sourceFailures)
}

function auditPayments(storeUuid, data, failures) {
  const setting = list(data.huifuSettings).find(item => item?.storeUuid === storeUuid) || null
  const merchantsByUuid = byUuid(data.huifuMerchants)
  const profilesByUuid = byUuid(data.huifuProfiles)
  const merchantUuid = text(setting?.merchantUuid)
  const merchant = merchantsByUuid.get(merchantUuid) || null
  const profile = profilesByUuid.get(referenceUuid(merchant?.providerUuid ?? merchant?.provider)) || null
  const grant = list(data.huifuGrants).find(item =>
    item?.storeUuid === storeUuid && text(item?.merchantUuid) === merchantUuid
  )
  const methods = Array.isArray(setting?.allowedMethods) ? setting.allowedMethods.filter(Boolean) : []
  const defaultAllowed = text(setting?.defaultMethod) && methods.includes(setting.defaultMethod)
  const unsupportedMethods = methods.filter(method => !Array.isArray(merchant?.capabilities) || !merchant.capabilities.includes(method))
  const posDevices = list(data.huifuPosDevices).filter(device =>
    device?.storeUuid === storeUuid
      && device?.enabled !== false
      && (!merchantUuid || text(device?.merchantUuid) === merchantUuid)
  )
  const paymentSourceFailures = failedSources(failures, [
    'huifuSettings',
    'huifuGrants',
    'huifuMerchants',
    'huifuProfiles'
  ])
  if (setting?.posEnabled === true) {
    paymentSourceFailures.push(...failedSources(failures, ['huifuPosDevices']))
  }
  const issues = []

  if (!paymentSourceFailures.length) {
    if (!setting) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No store Huifu payment setting'))
    } else {
      if (setting.enabled === false) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'Store Huifu payment setting is disabled'))
      }
      if (!merchantUuid) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'Store Huifu payment setting has no merchant'))
      } else if (!merchant) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The configured Huifu merchant does not exist'))
      } else if (merchant.enabled === false) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The configured Huifu merchant is disabled'))
      }
      if (merchant && (!profile || profile.enabled === false)) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The Huifu provider profile is missing or disabled'))
      }
      if (merchantUuid && !grant) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The Huifu merchant is not granted to this store'))
      }
      if (methods.length === 0) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No Huifu payment method is allowed'))
      }
      if (methods.length > 0 && !defaultAllowed) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The default Huifu payment method is not in the allowed methods'))
      }
      if (unsupportedMethods.length > 0) {
        issues.push(issue(
          STORE_SETUP_AUDIT_STATUSES.action,
          'The Huifu merchant does not support allowed payment methods: {0}',
          [unsupportedMethods.join(', ')]
        ))
      }
      if (setting.posEnabled === true && posDevices.length === 0) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'POS payment is enabled but no matching enabled POS device exists'))
      }
      if (issues.every(item => item.level !== STORE_SETUP_AUDIT_STATUSES.action)) {
        issues.push(issue(
          STORE_SETUP_AUDIT_STATUSES.manual,
          'Verify Huifu credentials and complete a test payment'
        ))
      }
    }
  }

  return buildStep('configure-payments', [
    { labelKey: 'Allowed payment methods', value: methods.length ? methods.join(', ') : '—' },
    { labelKey: 'Default payment method', value: text(setting?.defaultMethod) || '—' },
    { labelKey: 'POS payment', valueKey: setting?.posEnabled === true ? 'Enabled' : 'Disabled' }
  ], issues, [...new Set(paymentSourceFailures)])
}

function auditWechat(storeUuid, data, failures) {
  const setting = list(data.wechatSettings).find(item => item?.storeUuid === storeUuid) || null
  const applicationsByUuid = byUuid(data.wechatApplications)
  const merchantsByUuid = byUuid(data.wechatMerchants)
  const miniappUuid = text(setting?.miniappUuid)
  const officialUuid = text(setting?.officialUuid)
  const merchantUuid = text(setting?.merchantUuid)
  const miniapp = applicationsByUuid.get(miniappUuid) || null
  const official = applicationsByUuid.get(officialUuid) || null
  const merchant = merchantsByUuid.get(merchantUuid) || null
  const boundApplications = [miniapp, official].filter(Boolean)
  const sourceFailures = failedSources(failures, ['wechatSettings', 'wechatApplications', 'wechatMerchants'])
  const huifuSetting = list(data.huifuSettings).find(item => item?.storeUuid === storeUuid) || null
  const requiresJspay = Array.isArray(huifuSetting?.allowedMethods) && huifuSetting.allowedMethods.includes('jspay')
  const issues = []

  if (!sourceFailures.length) {
    if (!setting) {
      issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'No store WeChat setting'))
    } else {
      if (!miniappUuid && !officialUuid) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'Store WeChat setting has no Mini Program or Official Account application'))
      }
      if (miniappUuid && !miniapp) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The bound Mini Program application does not exist'))
      } else if (miniapp && miniapp.type !== 'miniapp') {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The bound Mini Program application has the wrong type'))
      }
      if (officialUuid && !official) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The bound Official Account application does not exist'))
      } else if (official && official.type !== 'official') {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The bound Official Account application has the wrong type'))
      }
      if (merchantUuid && !merchant) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'The bound WeChat merchant does not exist'))
      }
      if (requiresJspay && boundApplications.length === 0) {
        issues.push(issue(STORE_SETUP_AUDIT_STATUSES.action, 'Huifu JSAPI requires a bound WeChat application'))
      }
      if (issues.every(item => item.level !== STORE_SETUP_AUDIT_STATUSES.action) && boundApplications.length > 0) {
        issues.push(issue(
          STORE_SETUP_AUDIT_STATUSES.manual,
          'Verify WeChat login and complete a JSAPI test payment when enabled'
        ))
      }
    }
  }

  return buildStep('configure-wechat', [
    { labelKey: 'WeChat applications bound', value: String(boundApplications.length) },
    { labelKey: 'WeChat merchant bound', value: merchant ? text(merchant.name) || 'yes' : '—' }
  ], issues, sourceFailures)
}

export function auditStoreSetup({ store, data = {}, failures = {} }) {
  const storeUuid = text(store?.uuid)
  const steps = [
    auditStore(store),
    auditMembers(data.members, failures),
    auditAssignments(storeUuid, data.members, data.assignments, failures),
    auditProducts(storeUuid, data.categories, data.products, failures),
    auditPrinters(storeUuid, data.printerAccounts, data.printerDevices, data.printerTemplates, failures),
    auditPrintRules(storeUuid, data.printerDevices, data.printerTemplates, data.printerRules, failures),
    auditPayments(storeUuid, data, failures),
    auditWechat(storeUuid, data, failures)
  ]
  const summary = {
    complete: steps.filter(step => step.status === STORE_SETUP_AUDIT_STATUSES.complete).length,
    action: steps.filter(step => step.status === STORE_SETUP_AUDIT_STATUSES.action).length,
    manual: steps.filter(step => step.status === STORE_SETUP_AUDIT_STATUSES.manual).length,
    failed: steps.filter(step => step.status === STORE_SETUP_AUDIT_STATUSES.failed).length
  }
  const overallStatus = summary.failed > 0
    ? STORE_SETUP_AUDIT_STATUSES.failed
    : summary.action > 0
      ? STORE_SETUP_AUDIT_STATUSES.action
      : summary.manual > 0
        ? STORE_SETUP_AUDIT_STATUSES.manual
        : STORE_SETUP_AUDIT_STATUSES.complete

  return { overallStatus, summary, steps }
}
