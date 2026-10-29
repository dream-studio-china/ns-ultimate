export const printerSetupArticle = {
  id: 'ns-printer-setup',
  title: 'Printer setup and live printing (Xpyun)',
  module: 'Printer',
  kind: 'guide',
  locale: 'en',
  aliases: ['printer', 'printing', 'print job', 'printer device', 'printer account', '打印机', '打印', '小票打印机', '标签打印机', '芯烨云', 'Xpyun'],
  sourcePath: 'business/backend/Printer/README.md',
  content: [
    'Supported: Xpyun is the only registered live printer adapter. Management routes under /api/v1/manage/printer require ROLE_ADMIN.',
    'Setup: create an Xpyun provider account through the admin API. Set a private, stable PRINTER_CONFIG_KEY before creating accounts; losing or changing the key makes saved account credentials unreadable, and the key must not be committed. The client uses the platform fixed HTTPS host with JSON requests, and no credentials are ever returned to the frontend. Provider identity on an account is immutable after creation, and the account list offers no edit or delete action.',
    'Devices: device creation uses the standard CRUD create form and API lifecycle, and the backend registers the device with Xpyun before persisting it. Name, model, store, label dimensions and enabled state can be viewed and updated; account, serial number and kind are identity data and are not editable. Devices with print-job history cannot be deleted; deleting an unused device removes only the local record and does not unregister it from Xpyun.',
    'Jobs: device status refresh, preview, and print submission/status use dedicated admin actions. Preview never submits a print job. Live submission requires confirmation, disabled devices and accounts cannot be queried for provider status, and toolbar refresh skips disabled demo devices. Jobs are immutable after creation; deletion is allowed only for definitively failed jobs without a provider order ID. Submitted, printed, submitting and unknown jobs stay protected.',
    'Reprints: a reprint creates a new job from the immutable stored snapshot after explicit frontend confirmation, using a fresh provider idempotency key and the source copy count; the source device and account must be enabled. Unknown and submitting jobs are rejected for reprint to prevent duplicate output. A submission is capped at 500 physical copies/pages, and ambiguous transport outcomes are recorded as unknown and never automatically resubmitted.',
    'Unsupported: DeliCloud is listed for future support but cannot be activated yet. Its codec only builds and parses protocol messages; MQTT/HTTP transport, task conversion/file upload, credentials and test-environment access are not available, so the print-submission operation stays Xpyun-only.',
    'Troubleshooting: if credentials become unreadable, the encryption key was lost or changed and accounts must be recreated with the correct key. If a job sits at unknown, do not resend blindly; check whether it already printed, because a retry can duplicate output. If a device cannot be deleted, it has print-job history.',
    'Assistant boundary: custom registration, provider-status, job preview/submission and reprint actions are not ordinary form writes and are not exposed as tools. Automatic printing uses store-scoped Printer order rules, not the Store settings form. Never share developer keys or secrets with the assistant; enter credentials through the ordinary admin UI.'
  ].join('\n')
}
