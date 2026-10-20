# Customer order lifecycle

## Customer flow

1. Choose an active service and enter the problem description.
2. Select a saved map point (or enter coordinates) and provide the service address.
3. Choose immediate service (`urgent`) or a future appointment (`scheduled`).
4. Request and review the server-generated approximate price range.
5. Confirm once to create the booking. The booking retains the estimate's tariff and location snapshots.
6. Follow status history and cancel while the booking is `draft`, `broadcasting`, or `assigned`.

An order remains `draft` until an authorized dispatch workflow moves it forward. This phase does not claim that a technician has been dispatched.

## State machine

All status changes use `BookingLifecycleService`; each accepted change locks and checks the current state and adds one `booking_status_history` record in the same database transaction.

```text
draft -> broadcasting -> assigned -> en_route -> arrived -> in_progress
  |             |           |                              |          |
  +-> cancelled +-> cancelled +-> cancelled               +-> payment_pending -> completed
                                                        in_progress -> disputed -> completed
                                              payment_pending -> disputed
```

Cancellation is customer-only and is accepted from `draft`, `broadcasting`, and `assigned`. Once the technician is en route, the API returns a conflict instead of silently cancelling. Repeating a successful cancellation returns the same terminal state without duplicating history.

## API

- `POST /api/pricing/estimates` accepts `urgency: urgent|scheduled`; a scheduled estimate requires `scheduled_at`.
- `POST /api/bookings` accepts an estimate, address, optional problem description, and an optional UUID `idempotency_key`. If provided, send the same value in `Idempotency-Key`. The server stores a payload hash and enforces uniqueness per customer. A matching retry returns the original booking, including after estimate expiry; reuse with different content returns `409 IDEMPOTENCY_KEY_REUSED`. The estimate's unique booking constraint also makes legacy retries using the same estimate safe.
- `GET /api/bookings` returns only the signed-in customer's bookings.
- `GET /api/bookings/{bookingCode}` returns an unguessable UUID public code, snapshot, location, status and status history only to its owner.
- `POST /api/bookings/{bookingCode}/cancel` accepts a reason and is safe to retry.

Only customer-owned data is exposed by these order-detail endpoints. Unauthorized requests return `403`; a nonexistent code returns `404`. Failed network requests preserve the same creation payload/key in the current form so the customer can retry without creating a duplicate.
