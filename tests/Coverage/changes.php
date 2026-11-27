<?php

declare(strict_types=1);

/*
 * Changes to a running subscription — plan, seats, addons: which test exercises which
 * option.
 *
 * Read by tests/Feature/Coverage/OptionCoverageTest.php. A reference is
 * `file::fragment of the test name` and is checked to exist; what it promises is that
 * the test named there exercises the option and asserts its result.
 */

$actions = 'tests/Feature/Subscription/ChangeScreenActionsTest.php::';
$matrix = 'tests/Browser/Matrix/ChangeMatrixTest.php::';
$screens = 'tests/Browser/Matrix/ChangeScreensMatrixTest.php::';

// Feature: every action through its Livewire component.
$fSeats = $actions.'actions seats@increment, @decrement, @syncSeats';
$fSeatCoupons = $actions.'actions seats@applyCoupon, @removeCoupon, @canAddMoreCoupons';
$fAddons = $actions.'actions addons@enableAddon, @disableAddon, @pollForChange';
$fAddonDeclined = $actions.'action addons@pollForChange: a declined payment';
$fAddonCoupons = $actions.'actions addons@applyCoupon, @removeCoupon, @canAddMoreCouponsFor';
$fPlan = $actions.'actions plan-change@selectablePlans, @previewFor, @applyChange';
$fKeep = $actions.'action plan-change@toggleKeepExtraSeats';
$fScheduled = $actions.'actions plan-change@applyChange (end of period), @cancelScheduledChange, @applyScheduledNow';
$fMode = $actions.'enum PlanChangeMode / config plan_change_mode';
$fPending = $actions.'action plan-change@cancelPendingChange';
$fPendingPaid = $actions.'action plan-change@cancelPendingChange: a change whose payment has already settled';
$fPendingOutcome = $actions.'UpdateSubscription::tryCancelPendingPlanChange';
$fPendingNothing = 'tests/Feature/Webhook/ProrataChargeResumeTest.php::cancelling after the change went through';
$fPlanCoupons = $actions.'actions plan-change@applyCoupon, @removeCoupon, @canAddMoreCoupons';
$fAddress = $actions.'actions plan-change@openEditBillingModal, @saveBillingDetails';
$fLocal = $actions.'actions plan-change@applyChange, @backToPlanSelection, @confirmAndPay';
$fCeiling = $actions.'config max_seats: the seat screen refuses';

// Feature: the money matrix behind the screens.
$fMatrixSeats = 'tests/Feature/Subscription/ChangeMatrixSeatsAndAddonsTest.php::books what it previews, at the difference of the recurring amounts, and renews at what Mollie holds';
$fMatrixPlan = 'tests/Feature/Subscription/ChangeMatrixPlanChangeTest.php::charges the difference to what was paid, patches Mollie and renews at that amount';

// Browser.
$bCell = $matrix.'test_shown_amount_is_charged_booked_and_renewed';
$bSeveral = $matrix.'test_several_changes_in_one_period_each_move_what_they_show';
$bMode = $screens.'test_the_mode_offers_the_timing_it_allows_and_the_change_runs_then';
$bScheduled = $screens.'test_a_change_is_scheduled_withdrawn_scheduled_again_and_applied_now';
$bForm = $screens.'test_a_code_on_this_form_lowers_what_is_due_now_can_be_removed_and_is_what_is_collected';
$bLocal = $screens.'test_a_free_plan_upgrade_asks_first_and_takes_an_address_correction_before_it_charges';
$bPoll = $screens.'test_the_addon_screen_announces_the_addon_once_its_payment_has_arrived';
$bCeiling = $screens.'test_the_seat_ceiling_is_named_and_nothing_is_sent';
$bStates = $screens.'test_adding_a_seat_in_this_state_does_what_the_screen_says';
$bStateCells = $matrix.'test_a_change_in_this_state_moves_what_it_shows_or_is_refused_with_its_reason';
$bFree = $matrix.'test_a_downgrade_to_the_free_plan_returns_what_the_screen_says_and_ends_the_mollie_subscription';
$bRetry = $screens.'test_a_scheduled_change_shows_each_attempt_and_then_why_it_was_given_up';
$bSecond = $matrix.'test_a_second_change_credits_the_plan_reached_across_a_renewal';
$fSecond = 'tests/Feature/Subscription/ChangeMatrixRemainingCellsTest.php::settles a second change as if the period had been paid on the plan in between from its first day';
$fSchedulePastDue = 'tests/Feature/Subscription/ChangeMatrixRemainingCellsTest.php::refuses to schedule an expansion while past due, with the reason, and stores nothing';

return [
    // ── When a plan change is applied ───────────────────────────────────────
    'enum:PlanChangeMode.Immediate' => ['feature' => [$fMode], 'browser' => [$bMode]],
    'enum:PlanChangeMode.EndOfPeriod' => ['feature' => [$fMode], 'browser' => [$bMode]],
    'enum:PlanChangeMode.UserChoice' => ['feature' => [$fMode, $fScheduled], 'browser' => [$bMode, $bScheduled]],
    'config:plan_change_mode' => ['feature' => [$fMode], 'browser' => [$bMode]],
    'config:max_seats' => [
        'feature' => [$fCeiling, 'tests/Feature/Subscription/ChangeBoundaryGuardsTest.php::max_seats'],
        'browser' => [$bCeiling],
    ],

    // ── Addons screen ───────────────────────────────────────────────────────
    'screen:billing.addons' => ['feature' => [$fAddons, $fMatrixSeats], 'browser' => [$bCell, $bPoll, $bStateCells]],
    'action:addons@enableAddon' => ['feature' => [$fAddons], 'browser' => [$bCell, $bSeveral]],
    'action:addons@disableAddon' => ['feature' => [$fAddons], 'browser' => [$bCell, $bSeveral]],
    'action:addons@pollForChange' => ['feature' => [$fAddons, $fAddonDeclined], 'browser' => [$bPoll]],
    'action:addons@applyCoupon' => ['feature' => [$fAddonCoupons], 'browser' => [$bForm]],
    'action:addons@removeCoupon' => ['feature' => [$fAddonCoupons], 'browser' => [$bForm]],
    'action:addons@canAddMoreCouponsFor' => ['feature' => [$fAddonCoupons], 'browser' => [$bForm]],

    // ── Seats screen ────────────────────────────────────────────────────────
    'screen:billing.seats' => ['feature' => [$fSeats, $fMatrixSeats], 'browser' => [$bCell, $bStates, $bStateCells]],
    'action:seats@increment' => ['feature' => [$fSeats], 'browser' => [$bCell, $bSeveral]],
    'action:seats@decrement' => ['feature' => [$fSeats], 'browser' => [$bCell, $bSeveral]],
    'action:seats@syncSeats' => ['feature' => [$fSeats, $fCeiling], 'browser' => [$bCell, $bCeiling, $bStates]],
    'action:seats@applyCoupon' => ['feature' => [$fSeatCoupons], 'browser' => [$bForm, $screens.'test_a_second_discount_code_is_refused_on_the_seat_form_with_its_reason']],
    'action:seats@removeCoupon' => ['feature' => [$fSeatCoupons], 'browser' => [$bForm]],
    'action:seats@canAddMoreCoupons' => ['feature' => [$fSeatCoupons], 'browser' => [$bForm]],

    // ── Plan screen ─────────────────────────────────────────────────────────
    'screen:billing.plan' => ['feature' => [$fPlan, $fMatrixPlan], 'browser' => [$bCell, $bMode, $bScheduled, $bStateCells, $bFree, $bRetry]],
    'action:plan-change@selectablePlans' => [
        'feature' => [$fPlan, 'tests/Feature/Portal/HiddenPlanListingTest.php::selectablePlans'],
        'browser' => [$bCell, $bMode],
    ],
    'action:plan-change@previewFor' => ['feature' => [$fPlan], 'browser' => [$bCell]],
    'action:plan-change@applyChange' => ['feature' => [$fPlan, $fScheduled, $fMode, $fLocal, $fSecond, $fSchedulePastDue], 'browser' => [$bCell, $bMode, $bScheduled, $bSecond, $bStateCells]],
    'action:plan-change@toggleKeepExtraSeats' => ['feature' => [$fKeep], 'browser' => [$bCell]],
    'action:plan-change@cancelScheduledChange' => ['feature' => [$fScheduled], 'browser' => [$bScheduled]],
    'action:plan-change@applyScheduledNow' => [
        'feature' => [
            $fScheduled,
            'tests/Feature/Portal/ApplyScheduledChangeNowTest.php::keeps the scheduled change when applying it early fails',
            'tests/Feature/Portal/ApplyScheduledNowAwaitingPaymentTest.php::does not announce an upgrade applied now as changed while it waits for its payment',
        ],
        'browser' => [
            $bScheduled,
            'tests/Browser/ChangesInFlightTest.php::test_a_scheduled_upgrade_applied_now_and_withdrawn_while_its_payment_is_open_leaves_pro_at_mollie',
            'tests/Browser/ChangesInFlightTest.php::test_a_scheduled_upgrade_applied_now_whose_payment_is_declined_reads_failed_and_leaves_pro_at_mollie',
        ],
    ],
    'action:plan-change@cancelPendingChange' => ['feature' => [$fPending], 'browser' => [$screens.'test_a_payment_for_a_withdrawn_change_is_returned_in_full_and_both_documents_are_listed']],
    // The screen tells these apart only by its toast, which no browser test reads yet.
    'enum:PendingChangeCancelOutcome.Cancelled' => ['feature' => [$fPendingOutcome], 'no_browser' => 'only the toast differs from Cleared'],
    'enum:PendingChangeCancelOutcome.Cleared' => ['feature' => [$fPendingOutcome, $fPending], 'no_browser' => 'only the toast differs from Cancelled'],
    'enum:PendingChangeCancelOutcome.NothingPending' => ['feature' => [$fPendingNothing], 'no_browser' => 'needs the webhook to finish between render and click; the screen shows only the render\'s own toast'],
    'enum:PendingChangeCancelOutcome.AlreadyPaid' => ['feature' => [$fPendingPaid], 'no_browser' => 'needs a prorata charge that settles before the click; the screen shows only a toast'],
    'action:plan-change@applyCoupon' => ['feature' => [$fPlanCoupons], 'browser' => [$bForm]],
    'action:plan-change@removeCoupon' => ['feature' => [$fPlanCoupons], 'browser' => [$bForm]],
    'action:plan-change@canAddMoreCoupons' => ['feature' => [$fPlanCoupons], 'browser' => [$bForm]],
    'action:plan-change@openEditBillingModal' => ['feature' => [$fAddress], 'browser' => [$bLocal]],
    'action:plan-change@saveBillingDetails' => ['feature' => [$fAddress], 'browser' => [$bLocal]],
    'action:plan-change@backToPlanSelection' => ['feature' => [$fLocal], 'browser' => [$bLocal]],
    'action:plan-change@confirmAndPay' => [
        'feature' => [$fLocal, 'tests/Feature/Subscription/PlanChangeAmountTamperingTest.php::charges the catalog price, not the amount carried in the preview'],
        'browser' => [$bLocal],
    ],
];
