# Concurrency tests

Every other test in this package runs in one process. The guards that keep two
processes apart — the cache locks around plan changes, refunds and seat deltas, the
webhook reservation and its takeover, the activation claim, the row locks behind a usage
debit and a coupon redemption — cannot do anything there: a lock nobody else asks for
always succeeds. `tests/Concurrency` runs the operation in **two PHP processes at the same
time** and checks that it comes out as if they had taken turns.

```bash
composer test:concurrency          # SQLite, file cache (default)
composer test:concurrency:pgsql    # PostgreSQL, file cache
composer test:concurrency:redis    # SQLite, Redis cache and locks
# any combination, by environment:
BILLING_CONCURRENCY_DB=pgsql BILLING_CONCURRENCY_CACHE=redis \
  ./vendor/bin/pest --compact --configuration=phpunit.concurrency.xml
```

| | file cache (default) | Redis (`BILLING_CONCURRENCY_CACHE=redis`) |
|---|---|---|
| **SQLite** (default) | `composer test:concurrency` | `composer test:concurrency:redis` |
| **PostgreSQL** (`BILLING_CONCURRENCY_DB=pgsql`) | `composer test:concurrency:pgsql` | both variables |

It is a suite of its own (`phpunit.concurrency.xml`), not part of `phpunit.xml.dist`: each
case starts two worker processes and holds one of them in a slowed call for a few seconds,
so the suite takes about 45 seconds and depends on wall-clock timing. It needs no Chrome
and no network.

## What is tested

| Guard | Case | Outcome |
|---|---|---|
| `UpdateSubscription` — `Cache::lock('billing:subscription-change:{id}')` | two "one more seat" changes (the closure form of `update()`) | one: seats 2 → 3, one prorata payment, one PATCH; other: `SubscriptionChangeInProgressException` |
| `RefundInvoiceService` — `Cache::lock('billing:invoice-refund:{id}')` | two partial refunds of the same invoice | one credit note, one refund at Mollie; other: `RefundInProgressException` |
| the same lock, `refundLine()` | two refunds of what is left of the same invoice line | one credit note on that line (`parent_line_item_index`), the line used up; other: `RefundInProgressException` |
| `MollieWebhookController::reserve()` | two deliveries of the same renewal payment | 200 with one invoice; 503 without fetching the payment |
| `reserve()` taking over an abandoned reservation | two deliveries meet a pending row older than `RESERVATION_TTL_MINUTES` | the conditional update lets one take it over (200, one invoice); 503 |
| activation claim (`WebhookSupport::claimActivation()`) | two different paid first payments of one customer | one activates (one `CreateSubscription`); 503, no invoice for the other payment yet |
| `SyncSeatsJob` — `Cache::lock('billing:seat-sync:{id}')`, blocking | two `byDelta(+1)` jobs | the second waits for the first, then reads its row and waits for the first charge — see below |
| the same, lock wait run out | both jobs wait 1 s, the first holds the lock for its Mollie calls | the second puts a copy of itself on the queue (`requeues = 1`, a minute later); run from there, it adds its seat |
| `WalletUsageService::debit()` — transaction with billable and wallet row locked | 30 + 30 SMS against 50 included, no overage price | one booked (balance 20); other: `UsageLimitExceededException` |
| `CouponService::redeem()` — `lockForUpdate()` on the coupon | two customers redeem the last free slot | one redemption, `redemptions_count` exact; other: `InvalidCouponException` (`globally_exhausted`) |

**Two seat jobs in a row.** The second job is not added on top of the first: it waits
for the seat lock, resolves its delta from the row the first one wrote — and finds the
first change's prorata payment still unconfirmed. `ValidateSubscriptionChange` allows one
unconfirmed change at a time, so the job puts a copy of itself back on the queue
(`waits = 1`, `PENDING_WAIT_SECONDS` later) instead of failing. The test confirms the first
payment through its webhook, runs the copy, and ends at 4 seats with two prorata payments —
no lost seat, no seat added on a stale read. (Not on a `sync` run, where a delay is
ignored; there the job fails as before.)

## How it is built

- **One world.** `ConcurrencyTestCase` gives each case a temporary directory with a
  file cache, a storage root and a Mollie request log, and a database migrated fresh — a
  SQLite file in that directory, or the suite's own Postgres database (no
  `RefreshDatabase`: its transaction would be invisible to the workers).
  `ConcurrencyServiceProvider` points every process at the same world through
  `BILLING_CONCURRENCY_DIR`, `BILLING_CONCURRENCY_DB` and `BILLING_CONCURRENCY_CACHE`: the
  same database, the same cache store (which `Cache::lock()` and bavix's wallet lock and
  balance cache use), the same catalog.
- **Redis.** `BILLING_CONCURRENCY_CACHE=redis` puts the cache, every `Cache::lock()` and
  bavix's lock and balance cache on Redis (phpredis; `BILLING_REDIS_HOST`, `_PORT`,
  `_PASSWORD`, `_DB`, defaults 127.0.0.1:6379, database 0). Every key carries a prefix of
  the test case's own (`billing-concurrency:<case directory>:`), and the keys under it are
  deleted when the case ends. No extension or no server skips every case with the reason.
  Laravel's Redis lock is a `SET NX` with an owner token — a different implementation from
  the file store's `flock`, which is why the suite is worth running on it.
- **SQLite for two writers.** `busy_timeout` as in the browser suite, and
  `transaction_mode = IMMEDIATE`: both processes open transactions on purpose at the same
  moment, and a deferred transaction that wants to upgrade to a write while the other
  holds the write lock is refused at once, without consulting the busy handler (see
  `WorkbenchSetting::retryingOnLock()`). An immediate transaction takes the write lock when
  it begins, so the other one waits. Not `journal_mode = wal` — see
  [decisions](decisions.md#the-browser-suite-sets-busy_timeout-never-journal_mode--wal).
  The consequence: on SQLite **every write transaction serialises on its own**, so a
  missing `lockForUpdate()` cannot be seen there. That is what the Postgres run is for.
- **PostgreSQL.** `BILLING_CONCURRENCY_DB=pgsql` uses the variables of
  `WalletMorphPostgresTest` (`BILLING_PG_HOST`, `_PORT`, `_USER`, `_PASSWORD`; defaults
  127.0.0.1:5432, `postgres`, no password) and its own database
  `mollie_billing_concurrency`, created when missing — `migrate:fresh` drops everything in
  it. An unreachable server skips every case with the reason.
- **Mollie** is the browser suite's fake (`Workbench\App\FakeMollie`), answered from the
  shared database, wrapped in `SlowFakeMollieHttpAdapter`: it logs every request with
  the worker that sent it, and sleeps `ConcurrencyWorld::DELAY_MS` (2.5 s) on the request
  that sits inside the critical section (the refund, the payment and PATCH of a change,
  the webhook's payment fetch, the subscription an activation creates). Whoever wins is
  still inside when the other arrives. The fake itself answers one request at a time (a
  file lock around it, not around the delay), like the real API: its object ids come from
  a read-then-write counter that two processes on Postgres would otherwise both read.
- **Pauses where there is no Mollie call.** A balance, a counter or a reservation is read
  and then written within microseconds. Those cases pass `pause` — a sleep after every
  load of that model (`retrieved` event, `ConcurrencyWorld::registerPauses()`). With the
  guard the other process waits or is turned away for that long; without it, both have
  read the same value before either writes.
- **A barrier.** `worker.php` boots its application, touches the database and resolves the
  services once, then waits until the other worker is booted too (one file per worker in
  `barrier/`). Booting is what would otherwise put seconds between them. Each worker prints
  its outcome as JSON, with the time it left the barrier and the time it finished.
- **The test proves the overlap.** Every case asserts that the later start lies before the
  earlier finish; a run in which the two happened one after the other fails instead of
  passing for the wrong reason. It also asserts what each worker really ran on — database
  driver, cache store, wallet lock driver — so a run meant for Postgres or Redis cannot
  pass on the defaults.

`CONCURRENCY_KEEP=1` keeps the directory (`sys_get_temp_dir()/billing-concurrency-*`) with
its `laravel.log` and `mollie.log` for a look after a failure.

## Adding a case

Add a scenario to `ConcurrencyWorld::run()` (it runs inside the worker, after the barrier,
and must return something JSON-encodable), build the world it needs in the test process,
call `ConcurrencyWorld::runPair()` with the Mollie requests to slow down (and `pause`
where the window has no Mollie call), and assert both the outcomes and the state on disk.
Check the counter-proof by hand once, on both databases where row locks are involved:
switch the guard off and see the case fail — a contention test that also passes without
its lock tests nothing.

## What switching a guard off shows

Checked on 16 and 17 November 2026, each guard disabled by a one-line change for one run
and the file restored byte for byte afterwards (file cache unless stated):

| Guard off | SQLite | PostgreSQL |
|---|---|---|
| change lock | red — both seat changes ran: two prorata payments of 3.33 net, two PATCHes | — |
| refund lock | red — two credit notes, two refunds at Mollie | — |
| refund lock, cache on Redis | red — both refunds booked: two credit notes | red — the same |
| refund lock, by line | red — both refunded the whole line: two credit notes on one line | red — the same |
| reservation | red — both deliveries processed; the second died on the invoice's unique payment id with 500 instead of 503 | — |
| takeover condition (`where received_at = …`) | red — both took the stale row over: 200 and 500 | — |
| activation claim | red — both payments activated, 200 and 200 | — |
| seat lock | red — both jobs ran beside each other: one ran into the change lock (`SubscriptionChangeInProgressException`), the lost delta was not requeued | — |
| seat job's wait for a pending change (`waitsForPendingChange()`) | red — the second job failed with `InvalidSubscriptionStateException`, its seat was lost | — |
| `lockForUpdate()` in `debit()` | green — the immediate transaction serialises | red — both booked: balance −10 on a quota that may not go negative |
| `lockForUpdate()` in `redeem()` | green — same reason | red — both redeemed: two redemptions, `redemptions_count` 3 of 2 |
| bavix's wallet lock (`wallet.lock.driver = array`) | green | green — not the guard for the quota: `debit()` checks the balance under its own row lock, before bavix's lock is taken |
