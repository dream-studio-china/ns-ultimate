import type { Product } from '../domain/catalog'
import type { Basket } from '../domain/cart'

export type PaymentMethod = 'cash' | 'customerScan' | 'merchantScan'
export type PaymentOutcome = 'success' | 'failed' | 'unknown'
export interface DemoOrder {
  id: string; createdAt: number; basket: Basket; total: number; method: PaymentMethod; received: number; change: number
}
export interface PaymentResult { status: PaymentOutcome; order: DemoOrder | null }
export interface CashierGateway {
  readonly mode: 'demo'
  catalog(): Promise<Product[]>
  quote(basket: Basket): Promise<number>
  collect(input: { requestId: string; basket: Basket; method: PaymentMethod; received: number; outcome: PaymentOutcome }): Promise<PaymentResult>
  lookup(requestId: string): Promise<PaymentResult>
}
