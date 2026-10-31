export interface CheckoutQuantityLine {
  specificationUuid: string
  quantity: number
}

export interface CheckoutReconciliation {
  additions: CheckoutQuantityLine[]
  reductions: CheckoutQuantityLine[]
  desired: CheckoutQuantityLine[]
  current: CheckoutQuantityLine[]
  changed: boolean
}

function quantities(lines: CheckoutQuantityLine[]): Map<string, number> {
  const result = new Map<string, number>()
  for (const line of lines) result.set(line.specificationUuid, (result.get(line.specificationUuid) ?? 0) + line.quantity)
  return result
}

function lines(values: Map<string, number>, desired = false): CheckoutQuantityLine[] {
  const result = [...values].map(([specificationUuid, quantity]) => ({ specificationUuid, quantity }))
  return desired ? result : result.sort((left, right) => left.specificationUuid.localeCompare(right.specificationUuid))
}

/**
 * Splits checkout changes into the minimum addition order and the quantities
 * that require a later replacement after all existing orders are merged.
 */
export function reconcileCheckoutQuantities(currentLines: CheckoutQuantityLine[], desiredLines: CheckoutQuantityLine[]): CheckoutReconciliation {
  const currentBySpecification = quantities(currentLines)
  const desiredBySpecification = quantities(desiredLines)
  const additions = new Map<string, number>()
  const reductions = new Map<string, number>()

  for (const [specificationUuid, desired] of desiredBySpecification) {
    const current = currentBySpecification.get(specificationUuid) ?? 0
    if (desired > current) additions.set(specificationUuid, desired - current)
    if (current > desired) reductions.set(specificationUuid, current - desired)
  }
  for (const [specificationUuid, current] of currentBySpecification) {
    if (!desiredBySpecification.has(specificationUuid)) reductions.set(specificationUuid, current)
  }

  return {
    additions: lines(additions),
    reductions: lines(reductions),
    desired: lines(desiredBySpecification, true),
    current: lines(currentBySpecification),
    changed: additions.size > 0 || reductions.size > 0,
  }
}
