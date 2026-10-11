export function watchBusinessAdmin(directory) {
  return {
    name: 'ns-ultimate-watch-business-admin',
    configureServer(server) {
      // New glob matches outside the Vite root must also produce watcher events.
      server.watcher.add(directory)
    }
  }
}
