import { useCallback, useEffect, useState } from 'react'
import type { CustomerSnapshot, DesktopBridge, DesktopStatus } from '../../shared/desktop'
import { customerSnapshotSchema } from '../../shared/desktop'

declare global { interface Window { cashierDesktop?: DesktopBridge } }
const channelName = 'ns-cashier-demo-customer-v1'

export function useDesktop(onError: () => void) {
  const [status, setStatus] = useState<DesktopStatus | null>(null)
  useEffect(() => {
    const bridge = window.cashierDesktop
    if (!bridge) return
    void bridge.status().then(setStatus).catch(onError)
    return bridge.onStatus(setStatus)
  }, [onError])
  const act = useCallback(async (action: 'open' | 'close' | 'fullscreen', displayId?: number) => {
    const bridge = window.cashierDesktop
    if (!bridge) return
    try { setStatus(await (action === 'open' ? bridge.openCustomer(displayId) : action === 'close' ? bridge.closeCustomer() : bridge.toggleFullscreen())) }
    catch { onError() }
  }, [onError])
  return { status, act, isDesktop: !!window.cashierDesktop }
}
export function usePublishSnapshot(snapshot: CustomerSnapshot, onError: () => void) {
  useEffect(() => {
    if (window.cashierDesktop) { void window.cashierDesktop.publish(snapshot).catch(onError); return }
    const channel = new BroadcastChannel(channelName)
    channel.postMessage({ kind: 'snapshot', value: snapshot })
    channel.onmessage = (event) => { if (event.data?.kind === 'request') channel.postMessage({ kind: 'snapshot', value: snapshot }) }
    return () => channel.close()
  }, [snapshot, onError])
}
export function useCustomerSnapshot() {
  const [snapshot, setSnapshot] = useState<CustomerSnapshot | null>(null)
  useEffect(() => {
    const bridge = window.cashierDesktop
    let active = true
    let receivedUpdate = false
    if (bridge) {
      const unsubscribe = bridge.onSnapshot((value) => { receivedUpdate = true; setSnapshot(value) })
      void bridge.snapshot().then((value) => { if (active && !receivedUpdate) setSnapshot(value) }).catch(() => {})
      return () => { active = false; unsubscribe() }
    }
    const channel = new BroadcastChannel(channelName)
    channel.onmessage = (event) => {
      if (event.data?.kind !== 'snapshot') return
      const parsed = customerSnapshotSchema.safeParse(event.data.value)
      if (parsed.success) setSnapshot(parsed.data)
    }
    channel.postMessage({ kind: 'request' })
    return () => channel.close()
  }, [])
  return snapshot
}
