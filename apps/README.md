# Independent applications

This directory owns self-contained application packages. A package may be embedded in the composed admin/API deployment or have its own runtime. Keep its source, dependencies, configuration, adapters and operating instructions together, and document its host dependencies explicitly.

[`admin-agent/`](admin-agent/README.md) is an embeddable assistant app package: it owns its generic frontend and separate host-specific adapters and capability policies. Integration explicitly selects the adapters and composes the app; Docker and CI include it. Confirmed writes reuse existing admin CRUD APIs; no assistant backend package remains. It is not a new network service.

[`cashier/`](cashier/README.md) is an independent Electron/React desktop cashier with a touch-first operator interface and a separate customer display. It currently provides explicit demo flows, not live store authentication or payments. It owns its dependency lockfile, browser checks and manual desktop packaging workflow; it is not embedded in the admin or deployed by Docker. General domain rules remain in business modules.

[`store-console/`](store-console/README.md) is an independent React mobile Web workspace for product/kitchen management, table checkout and session reports, with a separately built customer payment entry. All business operations currently use labeled demo data; live store authentication, APIs and payment providers are not connected. It has its own lockfile and UI checks, without changing the composed admin/API deployment.

Other apps are not automatically discovered, built, tested, or deployed. Add explicit wiring and an operations guide before relying on a new app. The Docker context continues to exclude other app directories by default.
