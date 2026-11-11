# Promotion business adapter

`PromotionDateService` decorates the existing Core promotion service. It fixes only campaign `startTime` and `endTime` writes through the existing administrator promotion API; it does not replace routes, authorization, entities, pricing strategies or the general Core date mapper.

- Calendar dates (`YYYY-MM-DD`), Admin picker timestamps (`YYYY-MM-DD HH:mm:ss`) and ISO-8601 timestamps are converted to `DateTimeImmutable` before delegation.
- Explicit `null` clears the supplied boundary; omitted fields remain unchanged. Empty strings, relative dates, invalid types and invalid calendar dates are rejected as HTTP 400 before date mutation.
- Explicit timezone offsets are normalized to the PHP application timezone before storage because the existing Doctrine `datetime_immutable` column does not retain timezone information. Inputs without an offset use that same application timezone; clients should send offsets when specifying local Store times.
- The original persistence, validation, transaction and `noFlush` behavior is retained. This adapter does not add a start-before-end rule or alter active-window matching, percentage rounding, stacking or exclusivity.

No migration or Core edit is required. The integration Kernel discovers this module by convention; rebuild the application container during normal deployment. Tests cover the composed administrator API and service delegation against an isolated database. No production data is modified by the fix or its tests.
