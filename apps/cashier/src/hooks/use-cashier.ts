import { useCallback, useEffect, useRef, useState } from 'react'
import { createDemoGateway } from '../adapters/demo'
import type { DemoOrder, PaymentMethod, PaymentOutcome } from '../adapters/gateway'
import { addProduct, emptyBasket, setQuantity, total, type Basket, type HeldBasket, type Selection } from '../domain/cart'
import type { Product } from '../domain/catalog'
import type { MessageKey } from '../lib/i18n'
import { useOnline } from './use-online'

export function useCashier() {
  const [gateway] = useState(() => createDemoGateway())
  const [products, setProducts] = useState<Product[]>([])
  const [basket, setBasket] = useState<Basket>(emptyBasket)
  const [held, setHeld] = useState<HeldBasket[]>([])
  const [orders, setOrders] = useState<DemoOrder[]>([])
  const [paymentStatus, setPaymentStatus] = useState<'idle' | 'pending' | PaymentOutcome>('idle')
  const [currentOrder, setCurrentOrder] = useState<DemoOrder | null>(null)
  const [paymentOpen, setPaymentOpen] = useState(false)
  const [notice, setNotice] = useState<MessageKey | null>(null)
  const online = useOnline()
  const operationLock = useRef(false)
  const requestId = useRef<string | null>(null)
  const locked = paymentStatus !== 'idle'
  useEffect(() => { void gateway.catalog().then(setProducts) }, [gateway])
  useEffect(() => {
    if (!notice) return
    const timer = setTimeout(() => setNotice(null), 4000)
    return () => clearTimeout(timer)
  }, [notice])
  const add = (product: Product, selection: Selection, quantity: number) => {
    if (locked || operationLock.current) return
    try { setBasket({ ...basket, lines: addProduct(basket.lines, product, selection, quantity) }) }
    catch { setNotice('cartLimit') }
  }
  const quantity = (id: string, value: number) => {
    if (locked || operationLock.current) return
    setBasket((current) => ({ ...current, lines: setQuantity(current.lines, id, value) }))
  }
  const updateBasket = (update: Partial<Basket>) => { if (!locked && !operationLock.current) setBasket((current) => ({ ...current, ...update })) }
  const clear = () => { if (!locked && !operationLock.current) setBasket(emptyBasket()) }
  const hold = () => {
    if (locked || operationLock.current || !basket.lines.length) return
    if (held.length >= 20) { setNotice('cartLimit'); return }
    setHeld((current) => [...current, { id: crypto.randomUUID(), createdAt: Date.now(), basket: structuredClone(basket) }])
    setBasket(emptyBasket()); setNotice('heldSuccess')
  }
  const restore = (id: string) => {
    if (locked || operationLock.current) { setNotice('paymentLocked'); return false }
    if (basket.lines.length) { setNotice('holdFirst'); return false }
    const item = held.find((item) => item.id === id)
    if (!item) return false
    setBasket(item.basket); setHeld((current) => current.filter((item) => item.id !== id)); return true
  }
  const applyResult = useCallback((result: Awaited<ReturnType<typeof gateway.collect>>) => {
    setPaymentStatus(result.status)
    if (result.status === 'success' && result.order) {
      setCurrentOrder(result.order)
      setOrders((current) => current.some((order) => order.id === result.order!.id) ? current : [result.order!, ...current])
    }
  }, [])
  const collect = async (method: PaymentMethod, received: number, outcome: PaymentOutcome) => {
    if (operationLock.current || !online || !navigator.onLine || !basket.lines.length || !['idle', 'failed'].includes(paymentStatus)) return
    operationLock.current = true
    setPaymentStatus('pending')
    const id = crypto.randomUUID()
    requestId.current = id
    try {
      const amount = await gateway.quote(basket)
      if (amount !== total(basket.lines) || (method === 'cash' && received < amount)) { setPaymentStatus('failed'); return }
      applyResult(await gateway.collect({ requestId: id, basket: structuredClone(basket), method, received, outcome }))
    } catch { setPaymentStatus('unknown') }
    finally { operationLock.current = false }
  }
  const checkPayment = async () => {
    if (operationLock.current || !online || !requestId.current) return
    operationLock.current = true
    try { applyResult(await gateway.lookup(requestId.current)) }
    catch { setPaymentStatus('unknown') }
    finally { operationLock.current = false }
  }
  const finish = () => {
    if (paymentStatus !== 'success') return
    setBasket(emptyBasket()); setPaymentStatus('idle'); setCurrentOrder(null); setPaymentOpen(false); requestId.current = null
  }
  const closePayment = () => {
    if (paymentStatus === 'pending' || paymentStatus === 'unknown') return
    if (paymentStatus === 'success') { finish(); return }
    setPaymentStatus('idle'); setPaymentOpen(false)
  }
  const reset = () => {
    if (locked || operationLock.current) return
    setBasket(emptyBasket()); setHeld([]); setOrders([]); setCurrentOrder(null); setNotice(null)
  }
  return { products, basket, held, orders, online, locked, paymentStatus, currentOrder, paymentOpen, setPaymentOpen, notice, setNotice, add, quantity, updateBasket, clear, hold, restore, collect, checkPayment, finish, closePayment, reset }
}
export type CashierState = ReturnType<typeof useCashier>
