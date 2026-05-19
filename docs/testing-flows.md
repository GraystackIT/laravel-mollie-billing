# Testing Lifecycle Flows on Staging

This package ships two Artisan commands that let you reproduce every subscription-lifecycle
transition on a staging system without waiting for Mollie's monthly scheduler or fishing
around in the Mollie dashboard.

> **Non-production only.** Both commands abort immediately when
> `app()->environment('production')` is true. There is no override.

## Why these commands exist

Three classes of transitions need to be testable:

1. **Local job-driven transitions** — trial end, scheduled plan changes, past-due → cancelled,
   cancelled → expired. These happen inside two daily jobs
   (`ProcessTrialLifecycleJob`, `PrepareUsageOverageJob`) but are gated on dates. On staging
   you would otherwise have to wait, or hand-edit the DB and remember which job to run.
2. **Mollie-driven renewals** — recurring payments are triggered by Mollie's own scheduler
   at the subscription's `nextPaymentDate`. There is no "renew now" button in the Mollie
   dashboard, so you cannot test a renewal without waiting a full billing cycle.
3. **Webhook replay** — when a webhook handler has misbehaved in the past, the
   `BillingProcessedWebhook` idempotency layer prevents a retry.

`billing:simulate` covers (1) and (2). `billing:webhook-replay` covers (3).

## `billing:simulate`

```bash
# Interactive walkthrough — searchable billable picker (arrow keys), then a
# multi-select of flows (space to toggle, enter to confirm). Destructive
# transitions still require an explicit confirm.
php artisan billing:simulate

# Single flow (scriptable, no prompts):
php artisan billing:simulate trial-expired --billable=42

# Skip all confirmations (still non-interactive on options):
php artisan billing:simulate trial-expired --billable=42 --yes
```

### Available flows

| Flow | What it does | DB precondition set | Job run |
|---|---|---|---|
| `trial-ending-soon` | Schedules a trial to end tomorrow. | `trial_ends_at = tomorrow + 6h` | `ProcessTrialLifecycleJob` |
| `trial-expired` | Forces a trial past its expiry date. | `trial_ends_at = yesterday` | `ProcessTrialLifecycleJob` |
| `renewal` | Creates a real recurring payment in the Mollie sandbox, polls until paid, then invokes our webhook controller with the resulting payment id. Requires the billable to already have `mollie_customer_id` + `mollie_mandate_id` from a completed checkout. | — | — |
| `apply-scheduled-change` | Puts a scheduled plan change into the past so `ApplyScheduledChangesJob` picks it up. Pass `--plan=<code>` to schedule one inline, or leave empty to reuse an existing `scheduled_change`. | `scheduled_change_at = now - 1m` | `PrepareUsageOverageJob` (Pass 4) |
| `overage-charge` | Withdraws units from a wallet to force a negative balance, then triggers the overage payment pass. Pass `--usage-type=tokens --withdraw=100`. | wallet balance negative + `subscription_period_starts_at` ≈ 1 month ago | `PrepareUsageOverageJob` (Pass 1) |
| `past-due-auto-cancel` | Puts a billable in `PastDue` with `past_due_since` older than `past_due_max_days`, then runs the auto-cancel pass. | `status=PastDue`, `meta.past_due_since = now - (max_days + 1)` | `PrepareUsageOverageJob` (Pass 3a) |
| `cancelled-to-expired` | Sets `subscription_ends_at` into the past so the expiry pass flips the billable to `Expired`. | `status=Cancelled`, `subscription_ends_at = now - 1m` | `PrepareUsageOverageJob` (Pass 3b) |

### Options

| Option | Used by | Notes |
|---|---|---|
| `--billable=ID` | all flows | Billable primary key. Interactive runner prompts if not given. |
| `--usage-type=KEY` | `overage-charge` | Usage type key (e.g. `tokens`, `sms`). |
| `--withdraw=N` | `overage-charge` | Number of units to withdraw before the job runs. |
| `--plan=CODE` | `apply-scheduled-change` | Plan code to schedule if no scheduled change exists yet. |
| `--gross=AMOUNT` | `renewal` | Override the gross amount (major units, e.g. `19.99`). Defaults to the computed renewal amount for the current plan + addons + extra seats. |
| `--yes` | all flows | Skip every confirmation prompt. |

### Notes on the renewal flow

`renewal` is the only flow that hits a live API. It uses the existing pattern from
[`ChargeUsageOverageDirectly::createMolliePayment()`](../src/Services/Wallet/ChargeUsageOverageDirectly.php):
`sequenceType: 'recurring'` + `mandateId` + `customerId`, with our webhook URL attached.

After the payment moves to `paid` in the sandbox, the command calls the webhook controller
directly with the payment id rather than relying on Mollie's outbound delivery. This means
you don't need a public webhook endpoint or a tunnel — the loop is closed locally.

The webhook handler still verifies the payment against Mollie's API (`GetPaymentRequest`),
so the simulated renewal cannot succeed unless the sandbox payment really exists.

## `billing:webhook-replay`

```bash
# Replay a payment through the webhook handler.
php artisan billing:webhook-replay tr_xxxxxxxxx

# Also remove the existing invoice so the handler reprocesses fully
# (otherwise the invoiceAlreadyExistsForPayment guard short-circuits the run):
php artisan billing:webhook-replay tr_xxxxxxxxx --force-reset
```

The replay deletes the `BillingProcessedWebhook` reservation for the payment and re-invokes
`MollieWebhookController` with the payment id. The handler then re-fetches the payment from
Mollie and re-routes it through the matching service.

### When to use `--force-reset`

Without `--force-reset`, the `invoiceAlreadyExistsForPayment()` guard inside
`SubscriptionPaymentHandler` skips most non-idempotent side effects (invoice creation,
wallet recharge, coupon redemption) on the second run — which is exactly what you want for
production safety but blocks meaningful testing.

Use `--force-reset` only when you want a full reprocess and accept that the existing
invoice is deleted. The wallet credits performed during the first run will be added a
second time.

## Programmatic access

Both commands are thin wrappers around `GraystackIT\MollieBilling\Testing\LifecycleSimulator`.
You can invoke it from Tinker, a test, or your own admin tooling:

```php
$sim = app(GraystackIT\MollieBilling\Testing\LifecycleSimulator::class);
$billable = $sim->resolveBillable(42);

$sim->trialExpired($billable);
$sim->pastDueAutoCancel($billable);
$sim->renewal($billable); // returns ['payment_id', 'status', 'invoice_id']
$sim->replayWebhook('tr_xxxxx', forceReset: true);
```

## What is _not_ covered

- **Mandate creation / first checkout** — run a real checkout via the portal with the
  Mollie "Paid" test mandate. The simulator assumes a billable already has a customer +
  mandate before the `renewal` flow.
- **User-initiated cancellation** — call `CancelSubscription` directly; there is no
  Mollie webhook for cancellation, so nothing to simulate.
- **Payment-failure webhooks** — currently only the happy path of `renewal` is wired up.
  To exercise the failure handler, mock `Mollie::send` in a test or push a failing
  sandbox payment id into `billing:webhook-replay`.
