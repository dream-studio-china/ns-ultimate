import { useEffect, useState } from 'react'
import { demoGateway } from './adapters/demo'
import { addLine, due, subtotal, updateQuantity, type Product, type Receipt, type Table } from './domain'
import { issuePayment, readPayments, resolvePayment, subscribePayments, voidPayment, type DemoPayment } from '../shared/demo-payment'
import { label, useI18n, type Label, type MessageKey } from '../shared/i18n'
import { useOnline } from '../shared/use-online'

export function useStore() {
  const { t } = useI18n()
  const [data, setData] = useState(() => ({ ...demoGateway.bootstrap(), receipts: [] as Receipt[] }))
  const [payments, setPayments] = useState<DemoPayment[]>([])
  const [notice, setNotice] = useState<MessageKey | null>(null)
  const online = useOnline()
  useEffect(() => {
    const sync = () => {
      try {
        const current = readPayments()
        setPayments(current)
        setData((previous) => {
          const settled = previous.tables.flatMap((table) => {
            const payment = current.find((item) => item.id === table.paymentId)
            return payment?.status === 'paid' && payment.method && payment.paidAt && payment.total === due(table)
              ? [{ table, payment }] : []
          })
          if (!settled.length) return previous
          const receipts: Receipt[] = settled.map(({ table, payment }) => ({
            id: `DEMO-${payment.id.slice(0, 8).toUpperCase()}`, tableName: table.name, lines: structuredClone(table.lines),
            discount: table.discount, total: payment.total, paidAt: payment.paidAt!, method: payment.method!,
          }))
          return {
            ...previous,
            receipts: [...receipts, ...previous.receipts],
            tables: previous.tables.map((table) => settled.some((item) => item.table.id === table.id)
              ? { ...table, lines: [], discount: 0, note: '', paymentId: null, guests: 0, openedAt: null } : table),
          }
        })
      } catch { /* A storage failure is surfaced when a payment operation is requested. */ }
    }
    sync()
    const unsubscribe = subscribePayments(sync)
    const onFocus = () => sync()
    window.addEventListener('focus', onFocus)
    return () => { unsubscribe(); window.removeEventListener('focus', onFocus) }
  }, [])
  useEffect(() => {
    if (!notice) return
    const timer = setTimeout(() => setNotice(null), 3500)
    return () => clearTimeout(timer)
  }, [notice])
  const requireOnline = () => { if (!navigator.onLine) { setNotice('offline'); return false } return true }
  const editTable = (id: string, update: (table: Table) => Table) => {
    if (!requireOnline()) return
    const table = data.tables.find((item) => item.id === id)
    if (!table || table.paymentId) { setNotice('resolvePayment'); return }
    setData((current) => ({ ...current, tables: current.tables.map((item) => item.id === id ? update(item) : item) }))
  }
  const addItem = (tableId: string, productId: string, variantId: string) => {
    const product = data.products.find((item) => item.id === productId)
    if (!product) return
    editTable(tableId, (table) => {
      try { return { ...table, lines: addLine(table.lines, product, variantId), guests: table.guests || 2, openedAt: table.openedAt ?? Date.now() } }
      catch { return table }
    })
  }
  const changeQuantity = (tableId: string, lineId: string, quantity: number) => editTable(tableId, (table) => {
    const lines = updateQuantity(table.lines, lineId, quantity)
    return { ...table, lines, discount: Math.min(table.discount, subtotal(lines)) }
  })
  const saveTable = (tableId: string, discount: number, note: string) => {
    const table = data.tables.find((item) => item.id === tableId)
    if (!table || !Number.isInteger(discount) || discount < 0 || discount > subtotal(table.lines)) { setNotice('invalidDiscount'); return false }
    if (!requireOnline() || table.paymentId) return false
    editTable(tableId, (table) => ({ ...table, discount, note: note.slice(0, 200) }))
    return true
  }
  const checkout = (tableId: string): DemoPayment | null => {
    if (!requireOnline()) return null
    const table = data.tables.find((item) => item.id === tableId)
    if (!table || !table.lines.length || due(table) <= 0) return null
    if (table.paymentId) return payments.find((item) => item.id === table.paymentId) ?? null
    try {
      const payment = issuePayment({
        storeName: t('storeFull'), tableName: table.name, discount: table.discount, total: due(table),
        lines: table.lines.map((line) => ({ name: label(line.name, t), variant: label(line.variant, t), quantity: line.quantity, unitPrice: line.unitPrice })),
      })
      setData((current) => ({ ...current, tables: current.tables.map((item) => item.id === tableId ? { ...item, paymentId: payment.id } : item) }))
      return payment
    } catch { setNotice('paymentStorageHint'); return null }
  }
  const cancelPayment = (tableId: string) => {
    if (!requireOnline()) return
    const table = data.tables.find((item) => item.id === tableId)
    if (!table?.paymentId) return
    try {
      voidPayment(table.paymentId)
      setData((current) => ({ ...current, tables: current.tables.map((item) => item.id === tableId ? { ...item, paymentId: null } : item) }))
      setNotice('paymentVoided')
    } catch { setNotice('paymentLocked') }
  }
  const lookup = (tableId: string) => {
    if (!requireOnline()) return
    const table = data.tables.find((item) => item.id === tableId)
    try { if (table?.paymentId) resolvePayment(table.paymentId) } catch { setNotice('paymentStorageHint') }
  }
  const saveProduct = (product: Product) => {
    if (!requireOnline()) return false
    setData((current) => ({ ...current, products: current.products.some((item) => item.id === product.id)
      ? current.products.map((item) => item.id === product.id ? product : item) : [...current.products, product] }))
    setNotice('productSaved')
    return true
  }
  const toggleProduct = (id: string) => {
    if (!requireOnline()) return
    setData((current) => ({ ...current, products: current.products.map((item) => item.id === id ? { ...item, soldOut: !item.soldOut } : item) }))
  }
  const restoreProducts = () => {
    if (requireOnline()) setData((current) => ({ ...current, products: current.products.map((item) => ({ ...item, soldOut: false })) }))
  }
  const deleteProduct = (id: string) => {
    if (!requireOnline()) return
    setData((current) => ({ ...current, products: current.products.filter((item) => item.id !== id) }))
    setNotice('productDeleted')
  }
  const moveProduct = (id: string, direction: -1 | 1) => {
    if (!requireOnline()) return
    setData((current) => {
      const products = [...current.products]
      const from = products.findIndex((item) => item.id === id)
      const to = from + direction
      if (from < 0 || to < 0 || to >= products.length) return current
      ;[products[from], products[to]] = [products[to], products[from]]
      return { ...current, products }
    })
  }
  const addCategory = (name: string) => {
    if (!requireOnline()) return false
    if (!name.trim() || data.categories.some((item) => label(item.name, t) === name.trim())) { setNotice('invalidCategory'); return false }
    setData((current) => ({ ...current, categories: [...current.categories, { id: crypto.randomUUID(), name: { custom: name.trim().slice(0, 40) } as Label }] }))
    return true
  }
  const moveCategory = (id: string, direction: -1 | 1) => {
    if (!requireOnline()) return
    setData((current) => {
      const categories = [...current.categories]
      const from = categories.findIndex((item) => item.id === id)
      const to = from + direction
      if (from < 0 || to < 0 || to >= categories.length) return current
      ;[categories[from], categories[to]] = [categories[to], categories[from]]
      return { ...current, categories }
    })
  }
  const addTable = (name: string, capacity: number) => {
    if (!requireOnline()) return false
    if (!name.trim() || name.length > 20 || !Number.isInteger(capacity) || capacity < 1 || capacity > 30 || data.tables.some((item) => item.name === name.trim())) { setNotice('invalidTable'); return false }
    setData((current) => ({ ...current, tables: [...current.tables, { id: crypto.randomUUID(), name: name.trim(), capacity, guests: 0, openedAt: null, lines: [], discount: 0, note: '', paymentId: null }] }))
    setNotice('tableSaved')
    return true
  }
  const cancelOrder = (id: string) => editTable(id, (table) => ({ ...table, lines: [], discount: 0, note: '', guests: 0, openedAt: null }))
  const reset = () => {
    if (!requireOnline()) return
    const active = payments.filter((payment) => data.tables.some((table) => table.paymentId === payment.id))
    if (active.some((payment) => ['processing', 'unknown'].includes(payment.status))) { setNotice('resetBlocked'); return }
    try {
      for (const payment of active) if (['pending', 'failed'].includes(payment.status)) voidPayment(payment.id)
      setData({ ...demoGateway.bootstrap(), receipts: [] })
    } catch { setNotice('paymentStorageHint') }
  }
  return { ...data, payments, online, notice, setNotice, addItem, changeQuantity, saveTable, checkout, cancelPayment, lookup, saveProduct, toggleProduct, restoreProducts, deleteProduct, moveProduct, addCategory, moveCategory, addTable, cancelOrder, reset }
}
export type StoreState = ReturnType<typeof useStore>
