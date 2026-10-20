# Operational administration console

The server-rendered Laravel shell serves the Vanilla JavaScript console at
`/admin?frontend=vanilla`. All records and mutations are loaded from protected
API routes; the browser does not supply an identity header.

## Access and audit

The `admin.*` permissions are seeded with the RBAC roles. Console endpoints
authorize the authenticated, active Sanctum user against the required
permission. `X-User-Id` is never an authentication source. Every console
mutation writes actor, timestamp, request IP/user agent, target, before/after
values, and reason (where provided) to `audit_logs` in the same transaction.

Role changes, account suspension, specialist status changes, content deletion,
policy changes, refund, and settlement review require the current password and
an explicit `CONFIRM` field, or a Sanctum login token issued during the prior
five minutes for passwordless OTP accounts. Role grants and role creation are
restricted to the super administrator; built-in super-administrator
permissions cannot be edited. Catalog deactivation is preferred over
destructive removal.

## Console data

The dashboard aggregates current database counts and recent bookings. User,
technician, booking, catalog, coverage, pricing-rule, support-ticket, content,
settings, audit, and policy lists expose search and bounded pagination
(`per_page` is capped at 100). Versioned service tariffs remain immutable:
operators create a new effective version rather than editing historical
pricing.

The operations map draws only bookings in `en_route`, `arrived`, or
`in_progress` with an active assignment created within the prior eight hours.
The endpoint records each map read in the audit log. The retention window is configurable from one to eight hours
by a reauthenticated administrator. The general booking list
omits customer coordinates and street addresses.

Financial reporting, settlement decisions, and wallet refunds use the existing
financial ledger services. A refund is a wallet credit, not a card reversal.
Provider readiness is reported by the existing gateway and notification
adapters; this console does not mark unconfigured services connected.

The console adds `content_pages`, non-sensitive `app_settings`, and
`security_policies`. Application secrets and credentials are deliberately not
accepted as general settings. The operational location-retention policy is
enforced by the map query; role-permission changes alter actual controller
authorization rather than only presentation.
