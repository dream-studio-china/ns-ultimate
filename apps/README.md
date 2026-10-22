# Independent applications

This directory owns self-contained application packages. A package may be embedded in the composed admin/API deployment or have its own runtime. Keep its source, dependencies, configuration, adapters and operating instructions together, and document its host dependencies explicitly.

[`admin-agent/`](admin-agent/README.md) is an embeddable assistant app package: it owns its generic frontend, Symfony operation backend, and separate host-specific adapters and capability policies. Integration explicitly selects the adapters and composes the app; Docker and CI include it. It is not a new network service. Agent features reuse existing domain models and validation without adding code to `business/` or `core/`.

Other apps are not automatically discovered, built, tested, or deployed. Add explicit wiring and an operations guide before relying on a new app. The Docker context continues to exclude other app directories by default.
