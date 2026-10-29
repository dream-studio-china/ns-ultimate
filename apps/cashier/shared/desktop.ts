import { z } from 'zod'
import { themeIds } from './theme'

export const customerSnapshotSchema = z.object({
  locale: z.enum(['zh-CN', 'en']),
  theme: z.enum(themeIds),
  storeName: z.string().max(100),
  online: z.boolean(),
  lines: z.array(z.object({
    id: z.string().max(100), name: z.string().max(160), detail: z.string().max(200),
    quantity: z.number().int().min(1).max(99), total: z.number().int().min(0).max(100_000_000),
  })).max(100),
  total: z.number().int().min(0).max(100_000_000),
  status: z.enum(['idle', 'pending', 'success', 'failed', 'unknown']),
})

export type CustomerSnapshot = z.infer<typeof customerSnapshotSchema>
export interface DisplayInfo { id: number; label: string; width: number; height: number; primary: boolean }
export interface DesktopStatus { displays: DisplayInfo[]; customerOpen: boolean; customerDisplayId: number | null; fullscreen: boolean }
export interface DesktopBridge {
  status(): Promise<DesktopStatus>
  openCustomer(displayId?: number): Promise<DesktopStatus>
  closeCustomer(): Promise<DesktopStatus>
  toggleFullscreen(): Promise<DesktopStatus>
  publish(snapshot: CustomerSnapshot): Promise<void>
  snapshot(): Promise<CustomerSnapshot | null>
  onSnapshot(listener: (snapshot: CustomerSnapshot) => void): () => void
  onStatus(listener: (status: DesktopStatus) => void): () => void
}
