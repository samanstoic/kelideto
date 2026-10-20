# Technician operations

Technicians are registered against an active account and remain in
`pending_verification` until the newest national-ID and guild-license documents
have both been approved by a user with `technicians.verify_kyc`. Rejecting a
required document sets the profile to `rejected`; a later upload must be
reviewed before the profile can be verified. Document uploads are private,
limited to 5 MiB, and recorded with their MIME type, size, and SHA-256 digest.

Before going online, a verified technician must select active services, service
areas, and working hours with capacity. The browser requests geolocation only
after the technician chooses to go online and sends the resulting coordinates
to the authenticated availability endpoint.

Dispatch is limited to users with `technicians.manage_dispatch`. The phase-nine
dispatch engine issues one offer at a time, ranked by PostGIS geodesic distance
and restricted to verified, active, online technicians matching the service
area and skill, with fresh telemetry and remaining capacity during their
scheduled work hours. Offers have a response window of 30–600 seconds (90
seconds by default); rejection or expiry advances to the next eligible
technician. Manual assignment requires `bookings.dispatch_manual` and a recorded
reason. A database uniqueness constraint allows only one assignment per order;
acceptance and manual allocation recheck qualifications under a row lock before
the assignment and order state transition are committed together. Driving ETA
is reported as unavailable unless a road-routing provider is configured; direct
distance is never presented as driving time.

Customer address and coordinates are returned only for the assigned verified
technician, while the assignment is active, the order is in an allowed
in-progress state, and `location_access_expires_at` has not elapsed. The
location window lasts 12 hours from assignment. The same authorization is
applied to the mission, location, and customer-authority endpoints; evidence
uploads are also denied after the window expires.
Starting work for a service requiring ownership proof requires the technician
to record the in-person verification. Required completion evidence must be
uploaded before the order can enter `payment_pending`.

The server-side phase-eight feature tests exercise document approval and
rejection, profile qualification and online availability, dispatch eligibility,
offer acceptance and expiry, mission lifecycle and evidence, earnings, and
location access before assignment and after expiry:
`tests/Feature/TechnicianOperationsPhaseEightTest.php`.

The authenticated routes are:

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/technician/register` | Start a technician profile |
| GET | `/api/technician/dashboard` | Read profile and active service-area catalogs |
| PUT | `/api/technician/profile` | Update profile, qualifications, coverage, and hours |
| POST | `/api/technician/documents` | Submit a private verification document |
| PUT | `/api/technician/availability` | Go online/offline; online requires coordinates |
| GET | `/api/technician/offers` | List live dispatch offers |
| POST | `/api/technician/offers/{offerId}/respond` | Accept or reject an offer |
| GET | `/api/technician/missions` | List assigned missions without customer location |
| GET | `/api/technician/missions/{bookingCode}` | Read an active mission and authorized location |
| POST | `/api/technician/missions/{bookingCode}/status` | Advance a mission through the lifecycle |
| POST | `/api/technician/missions/{bookingCode}/evidence` | Upload required completion evidence |
| GET | `/api/technician/earnings` | View earnings and settlement records |
| GET | `/api/admin/technicians/documents/pending` | List KYC documents for authorized reviewers |
| POST | `/api/admin/technicians/{technicianId}/documents/{documentId}/review` | Approve or reject KYC documents |
| POST | `/api/admin/bookings/{bookingId}/dispatch-offers` | Dispatch an order to eligible technicians |
| POST | `/api/admin/bookings/{bookingId}/dispatch` | Issue the next ranked offer |
| POST | `/api/admin/bookings/{bookingId}/assign` | Manually assign with an audited reason |
| GET | `/api/admin/bookings/{bookingId}/dispatch-events` | Review the dispatch audit timeline |

The frontend uses these live endpoints for the technician panel. Non-technician
panels remain demo-only and are explicitly labeled as such.
