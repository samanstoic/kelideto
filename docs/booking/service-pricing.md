# Catalog, tariffs, and booking price snapshots

All monetary values in the pricing API use the platform currency (`IRR`); clients must not convert, submit, or override server prices. The public service catalog contains only active categories and services. It intentionally omits a static price because the payable estimate depends on service, scheduled time, and the covered area.

## Service and area setup

The catalog seeder creates categories for building, automotive, cylinder replacement, lock repair, lock opening, and other authorized services; it deliberately does not seed fabricated service prices. An active administrator (`super_admin`) can manage categories and services through `/api/admin/catalog/categories` and `/api/admin/catalog/services`. `PATCH` supports activating or deactivating an existing item; historical services and categories are not deleted. A service's `requires_ownership_proof` flag determines whether booking creation requires an explicit customer authority attestation.

The legacy `services.base_price` column remains in the existing schema and is required by service creation for compatibility, but it is not returned as a customer price and is never used for estimates. The only authoritative customer-facing rates are the versioned `service_tariffs`.

Coverage areas are circles defined by `center_lat`, `center_lng`, and `radius_km`. If multiple active circles contain a point, the nearest center wins; an estimate fails if that area's service tariff is missing. `minimum_trip_km` is the minimum billable distance, not a claim about the technician's real travel distance. Polygon data is not used in this phase.

## Immutable tariff versions

Create each price version with `POST /api/admin/pricing/tariffs`. Each version is scoped to one service and one coverage area. Its rate components are:

- `base_fare`, `dispatch_fee`, `per_km_rate`, and `per_minute_rate`
- `minimum_total`, `minimum_trip_km`, and `estimate_margin_percent`
- `night_multiplier`, `night_start`, and `night_end`
- `effective_from` and optional `effective_until`

Version numbers are assigned by the server. Rate rows have no update or delete endpoint. When a later version is scheduled after an open-ended version, the previous version's end time is closed to the new start time; historical amounts/rates are not changed. Overlapping validity periods are rejected. A service or area may be disabled without deleting its tariff history.

The estimate calculation selects the active service, the nearest active covering area, and the tariff valid at the requested time. The distance component uses the greater of the area-center distance and the configured minimum billable distance. A configured night multiplier applies when the requested local time falls in the tariff's night window. A missing area or active tariff is an explicit validation error; there is no client-side or hard-coded fallback amount.

## Estimate and booking

Authenticated customers with the `bookings.create` permission request an estimate through `POST /api/pricing/estimates` with `service_id`, `latitude`, `longitude`, and optional `scheduled_at`. The server returns an explicitly approximate range, component breakdown, currency, expiry, and tariff version. Amounts supplied by a client are not accepted as calculation inputs.

To create a request, post the estimate UUID and street address to `POST /api/bookings`. The estimate must belong to the signed-in customer and be unexpired. The server reuses its persisted price snapshot, coordinates, service, and tariff version; submitted price/service/location replacements are not used. The request is created in `draft` status and is not dispatched by this endpoint. Repeating the same estimate is idempotent and returns the existing request.

When the selected service requires authorization, the customer must provide `ownership_attested: true` and `property_type` (`residential`, `commercial`, `vehicle`, or `safe`). The system records the attestation version and timestamp. This is an attestation, not proof of ownership: when the assigned verified technician arrives, they must record the authority check through `POST /api/bookings/{id}/customer-authority/verify` before work. An unsuccessful check is recorded as rejected; work must stop and be escalated. Additional entry-related costs are blocked until this check is recorded as successful. No lock-bypass instructions or tools are part of this flow.

## Additional costs

An assigned, verified technician can propose an additional catalog service while the booking is `in_progress`, with a reason and quantity. The server calculates the amount from the active area-specific tariff; submitted amounts are ignored. The proposal and its tariff snapshot remain `pending` and must not be charged until the booking's customer records an approval. The customer can approve or reject a pending cost once; both responses are auditable in `customer_approvals`.

## Administrative endpoints

- `GET|POST /api/admin/catalog/categories`; `PATCH /api/admin/catalog/categories/{id}`
- `GET|POST /api/admin/catalog/services`; `PATCH /api/admin/catalog/services/{id}`
- `GET|POST /api/admin/service-areas`; `PATCH /api/admin/service-areas/{id}`
- `GET|POST /api/admin/pricing/tariffs`

All administration endpoints require Sanctum authentication and an active `super_admin` role. They do not trust `X-User-Id`.
