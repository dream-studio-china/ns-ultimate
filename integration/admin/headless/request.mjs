// Configuration modules import the request client for lazy callbacks. The
// headless loader never calls those callbacks and must never open a connection.
function unavailable() {
  return Promise.reject(new Error('Network access is disabled during admin manifest export.'))
}

const request = {
  get: unavailable,
  post: unavailable,
  put: unavailable,
  patch: unavailable,
  delete: unavailable,
  request: unavailable
}

export default request
