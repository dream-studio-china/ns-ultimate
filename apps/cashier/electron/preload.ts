import { contextBridge, ipcRenderer } from 'electron'
import type { CustomerSnapshot, DesktopBridge, DesktopStatus } from '../shared/desktop'

const bridge: DesktopBridge = {
  status: () => ipcRenderer.invoke('desktop:status'),
  openCustomer: (id) => ipcRenderer.invoke('desktop:open-customer', id),
  closeCustomer: () => ipcRenderer.invoke('desktop:close-customer'),
  toggleFullscreen: () => ipcRenderer.invoke('desktop:fullscreen'),
  publish: (snapshot) => ipcRenderer.invoke('customer:publish', snapshot),
  snapshot: () => ipcRenderer.invoke('customer:snapshot'),
  onSnapshot: (listener) => {
    const handler = (_event: Electron.IpcRendererEvent, snapshot: CustomerSnapshot) => listener(snapshot)
    ipcRenderer.on('customer:updated', handler)
    return () => ipcRenderer.removeListener('customer:updated', handler)
  },
  onStatus: (listener) => {
    const handler = (_event: Electron.IpcRendererEvent, status: DesktopStatus) => listener(status)
    ipcRenderer.on('desktop:updated', handler)
    return () => ipcRenderer.removeListener('desktop:updated', handler)
  },
}
contextBridge.exposeInMainWorld('cashierDesktop', bridge)
