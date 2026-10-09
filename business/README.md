# Business layer

All application-specific frontend and backend code belongs under this directory. Organize modules by business capability, for example `business/<module>/admin`, `business/<module>/backend`, and `business/<module>/tests`.

Do not make business modules depend on implementation details inside `core/` where a stable interface or `integration/` adapter can be used. Backend business code should have a project-owned namespace rather than adding classes to the core's `App\\` namespace.
