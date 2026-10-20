# Phase 17: Performance, Capacity, and Resilience

**Status:** Partial; working targets accepted by the user's instruction to continue. Functional validation and safe public-catalog caching are complete. The isolated full stack is now running and initial setup is complete; performance, queue-throughput, restart, and capacity measurements have not yet been run.

**Approval interpretation:** The user's “continue” response is treated as approval to use the proposed targets, workload, and synthetic dataset below. If “continue” was not intended to approve these values, replace them before running the benchmark.

## Scope

Measure API, PostgreSQL/PostGIS, and queue behavior; inspect slow query plans; verify response-size limits and pagination; review public-data caching and invalidation; run load, dependency-failure, timeout/retry, queue recovery, and data-continuity tests; estimate capacity only from observed saturation results.

The repository has no dedicated public technician-search route. The representative technician candidate-search workload is the `eligibleCandidates()` query invoked by the admin dispatch-offer endpoint. If a separate product workflow is intended, replace this scenario before approval.

## Proposed Test Environment

- Isolated local Linux containers through the repository's Docker Compose stack; synthetic data only, never production data.
- Current host inventory: Windows, 4 CPU cores / 8 logical processors, 15.7 GiB RAM.
- Available host runtimes: PHP 8.3.28, Composer 2.8.4, Node.js 26.3.1, npm 11.16.0.
- Docker Desktop's Linux engine is in Docker-only direct mode; PostGIS, Redis, PHP, and pinned k6 `0.54.0` images were pulled. The local PHP/Composer runtime is PHP 8.3.28 / Composer 2.8.4.
- The isolated Compose project `keypro-phase17-20261019` is running with fresh project-scoped volumes. Because Windows reserves default ports 5432/6379/8001/5173, temporary local overrides are: backend 18001, PostGIS 15432, Redis 16379, and Vite 5180. Existing `vegan-superapp` containers/data were not used or modified.
- Laravel health is HTTP 200 with database/cache healthy. Initial setup completed through the isolated setup route; `setup_required=false` and `ready=true` after changing the app container to run the direct PHP server so its HTTP worker inherits the synthetic container environment. A synthetic administrator is named `phase17-root`; its generated password was not printed.
- Frontend Vite and Laravel backend run together: Laravel page at `http://127.0.0.1:18001/` references assets from Vite `http://127.0.0.1:5180/`; Vite client and app entry both return HTTP 200.
- Synthetic dataset currently present: 10,000 verified technicians, 100 service areas, 100 synthetic customers, one dispatcher, one service, matching tariffs, and 100,000 historical completed bookings. No production data or real payment/SMS/geocoding provider was used.
- Compose defines Laravel, PostgreSQL/PostGIS, Redis, and scheduler, but no queue worker. Laravel's default queue connection is `database`; the app service does not configure Redis. Queue execution must be measured with the current database driver and a controlled worker process, or the environment must be changed with approval.
- Proposed synthetic dataset: 10,000 technician profiles, 100 service areas, and 100,000 historical bookings. Confirm or replace these counts with expected production scale.
- External geocoding and payment providers must not receive load-test traffic. Use controlled stubs for upstream timeout/failure tests; report geocoding cache-hit and cache-miss separately.

## Proposed Workload

1. Ramp to 20 virtual users over 2 minutes, sustain for 10 minutes, then run a 50-user burst for 2 minutes.
2. Booking workflow: authenticated price estimate followed by booking creation, using unique users, estimates, and idempotency keys.
3. Specialist candidate workflow: dispatch-offer endpoint against synthetic eligible/ineligible profiles; isolate candidate-query latency from notification delivery.
4. Frequent reads: public service catalog, authenticated booking/notification reads, and geocoding cache-hit requests.
5. Resilience: controlled upstream timeout/unavailability, application and worker restart, database restart in the isolated stack, and verification of queue retries, idempotency, and persisted records.

## Proposed Acceptance Targets

These are proposed SLOs, not measured results. Approve or replace them before the benchmark begins.

| Measure                                               |                                                                                  Proposed target |
| ----------------------------------------------------- | -----------------------------------------------------------------------------------------------: |
| p95 latency: ordinary API reads                       |                                                                                        <= 300 ms |
| p95 latency: price estimate and booking creation      |                                                                                      <= 1,000 ms |
| p95 latency: dispatch candidate search/offer creation |                                                                                      <= 1,500 ms |
| p95 latency: geocoding cache hit                      |                                                                                        <= 200 ms |
| Unexpected HTTP 5xx/timeouts during steady load       |                              < 1%; expected 4xx and deliberate 429 responses reported separately |
| Individual slow-query investigation threshold         |                                                     p95 > 100 ms or any recurring query > 250 ms |
| Queue enqueue-to-start latency                        |                                                     p95 <= 5 seconds under the proposed workload |
| Queue/data integrity after restart                    | no lost accepted jobs, no duplicate externally visible side effects, idempotent replay preserved |
| Container resource use at steady target               |                CPU and memory each <= 80% of their explicit container limit; no OOM/restart loop |

## Evidence and Reporting Rules

- Record run ID, commit/worktree state, dataset counts, container limits, workload profile, cache state, and tool versions.
- Report p50/p95/p99 latency, throughput, unexpected error rate, queue lag/retries/failures, CPU/RAM, and top database statements with execution plans.
- Add or change indexes only when an observed query plan and representative-data benchmark support the change; rerun the same workload after each optimization.
- Capacity estimates must identify the measured saturation point and bottleneck. Do not extrapolate beyond tested concurrency or claim production capacity from this local host.
- Record unexecuted checks and environment limitations explicitly.

## Current Findings (No Performance Claims)

### Runtime Gate

- Docker Desktop's Linux engine is responsive, but required pulls for `redis:7.2-alpine` and `postgis/postgis:16-3.4-alpine` fail because nothing listens on its configured local proxy port `127.0.0.1:10801`. Proxy-related processes exist but have no TCP listener. A direct host HTTPS request to Docker Registry returned 401, confirming direct registry connectivity. Cached images include `redis:7-alpine` and `postgres:16-alpine`, but no PostGIS or PHP image. `keypro-app`, PostGIS, and Redis have no local listeners; the application was not load-tested.
- The user authorized bypassing the proxy for Docker only. Docker Desktop's official setting is **Settings > Resources > Proxies**; set both **Docker Desktop proxy** and **Containers proxy** to **No proxy**, then apply/restart. No system-wide proxy setting or other proxy/VPN process should be changed. The available tools cannot interact with this native settings UI, and Docker Desktop CLI exposes no proxy-setting command.
- Docker VM reports 8 vCPU and 7.6 GiB RAM; host inventory is 4 cores / 8 logical processors and 15.7 GiB RAM. These are resource limits/inventory, not a capacity result.
- k6 `0.54.0` is available locally as a Docker image. No benchmark workload, EXPLAIN ANALYZE against representative data, upstream fault injection, queue-worker restart, or database recovery test has yet run.

### API, Response Size, and Query Shape

- Public service catalog previously queried each active category and its services on every request. It is now cached for five minutes under `public:service-catalog:v1`; category/service writes through both the dedicated catalog endpoints and generic admin resource endpoints invalidate that key.
- The default cache store is database-backed, so the benchmark must measure the cache-hit benefit and database cache-store overhead separately. Redis is present in Compose but is not currently configured as the Laravel cache store.
- Admin console pagination defaults to 20 and clamps `per_page` to 1-100. Content-admin lists also clamp to 1-100. Several operational endpoints have explicit fixed caps (typically 20-1,000). Report export is capped at 10,000 rows.
- Public service/category, area, and tariff catalog responses and sitemap assembly currently use unbounded `get()` calls. Their data is presently expected to be small, but no measured size bound is established. Review these response contracts after observing actual row counts; do not silently truncate or change response shapes.
- Dispatch candidate selection fetches the eligible candidate set with `get()` and performs working-hours/capacity lookups per candidate. This is a likely query-count/scaling hotspot, not a measured bottleneck. Capture SQL timings and `EXPLAIN (ANALYZE, BUFFERS)` on the proposed dataset before adding indexes or rewriting it.
- PostGIS is required for the spatial candidate test; that test is skipped in the current SQLite feature environment. The GeoJSON polygon predicate constructs geometry from JSON at query time, so spatial-index value cannot be assumed from the current schema.

### Queue and Dependency Behavior

- Laravel's default queue connection is `database`, with `retry_after=90`; failed jobs use the `database-uuids` store. `DeliverNotificationJob` has five tries and `[30, 120, 600, 1800]` backoff values.
- Compose has no `queue:work` worker and no queue dashboard/alert listener. A scheduler container does not consume jobs. Until a worker is run, queue wait time is unbounded; this is a configuration gap, not a measured queue result.
- Notification delivery also tracks provider attempts/status in `notifications_log`. A functional test verifies failure/retry state, but no load or dead-letter recovery test has run. Existing `failed_jobs` is the Laravel dead-letter equivalent; monitoring and alert routing are not configured.
- Nominatim calls have an 8-second HTTP timeout, a one-second global rate limit, and a 30-day result cache. Its timeout/failure behavior is covered by functional tests, not by load or resilience measurements.

### Functional Checks (Not Benchmarks)

- `BookingLifecyclePhaseSevenTest`, `DispatchEnginePhaseNineTest`, `LocationPhaseFiveTest`, `NotificationPhaseTenTest`, and `ServicePricingPhaseSixTest`: 37 passed, 1 skipped, 300 assertions, 2.50 seconds total test-run time using PHP 8.3.28.
- The skipped case requires PostGIS. Test-run duration is not API latency and must not be cited as p95.
- Public catalog cache population and invalidation are covered for the dedicated and generic admin mutation paths.

### Measured Results and Remaining Gate

| Required result                                                      | Current status                                           |
| -------------------------------------------------------------------- | -------------------------------------------------------- |
| API p50/p95/p99, throughput, and unexpected-error rate               | Not measured; stack/data ready, load run pending         |
| Database query p95, slow-query plans, and index evidence             | Not measured; PostGIS/data ready, query-plan run pending |
| Queue lag, retry rate, failed-job count, and recovery                | Not measured; controlled worker and queue run pending    |
| CPU/RAM under load and saturation point                              | Not measured; only host inventory is known               |
| Timeout/failure behavior under load and data recovery after restarts | Not run; isolated stack ready, fault test pending        |
| Capacity estimate                                                    | Not provided; no measured saturation evidence            |

The isolated app/database/cache stack, setup, and initial synthetic dataset are ready. Next: capture the agreed baseline, run the database-backed queue with a controlled worker, profile the candidate query and plans, add further load data only as needed, inject controlled failures, and execute restart/data-continuity tests. Do not run recovery tests against any existing project volume or production data.

## Run Gate

The targets are approved as working acceptance goals. The isolated stack is available; no measurements have been captured yet. Benchmark the current database-backed queue with a controlled worker first; do not change queue infrastructure before recording that baseline. Keep all data in the new `keypro-phase17-20261019` project/volumes and use generated credentials and synthetic records only.
