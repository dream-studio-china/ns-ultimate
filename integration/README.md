# Integration layer

Project-owned application composition belongs here, not in either upstream subtree. Keep this layer limited to wiring: register business frontend config/routes, backend routes/services/entities, and adapters for core contracts.

The upstream projects do not yet provide a single turnkey external-module mechanism. Implement and verify the narrowest extension seam in a dedicated integration change; if a generic upstream seam is needed, contribute it to the corresponding core and keep product-specific registration here.
