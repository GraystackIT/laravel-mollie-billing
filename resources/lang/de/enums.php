<?php

return [
    'subscription_status' => [
        'new' => 'Neu',
        'active' => 'Aktiv',
        'trial' => 'Testphase',
        'past_due' => 'Überfällig',
        'cancelled' => 'Gekündigt',
        'expired' => 'Abgelaufen',
    ],
    'invoice_status' => [
        'paid' => 'Bezahlt',
        'open' => 'Offen',
        'failed' => 'Fehlgeschlagen',
        'refunded' => 'Erstattet',
    ],
    'invoice_kind' => [
        'subscription' => 'Abonnement',
        'prorata' => 'Anteilig',
        'addon' => 'Addon',
        'seats' => 'Plätze',
        'overage' => 'Mehrverbrauch',
        'one_time_order' => 'Einmalkauf',
        'credit_note' => 'Gutschrift',
    ],
    'subscription_source' => [
        'none' => 'Keine',
        'local' => 'Lokal',
        'mollie' => 'Mollie',
    ],
    'subscription_interval' => [
        'monthly' => 'Monatlich',
        'yearly' => 'Jährlich',
    ],
    'coupon_type' => [
        'first_payment' => 'Erstzahlung',
        'recurring' => 'Wiederkehrend',
        'credits' => 'Guthaben',
        'trial_extension' => 'Testverlängerung',
        'access_grant' => 'Zugangsgewährung',
    ],
    'discount_type' => [
        'percentage' => 'Prozentual',
        'fixed' => 'Festbetrag',
    ],
    'refund_reason_code' => [
        'service_outage' => 'Dienstausfall',
        'billing_error' => 'Abrechnungsfehler',
        'goodwill' => 'Kulanz',
        'chargeback' => 'Rückbuchung',
        'cancellation' => 'Kündigung',
        'plan_downgrade' => 'Plan-Downgrade',
        'other' => 'Sonstiges',
    ],
    'country_mismatch_status' => [
        'pending' => 'Ausstehend',
        'resolved' => 'Gelöst',
    ],
    'plan_change_mode' => [
        'immediate' => 'Sofort',
        'end_of_period' => 'Periodenende',
        'user_choice' => 'Benutzerauswahl',
    ],
    'mollie_subscription_status' => [
        'pending' => 'Ausstehend',
        'active' => 'Aktiv',
        'canceled' => 'Storniert',
        'suspended' => 'Ausgesetzt',
        'completed' => 'Abgeschlossen',
    ],
];
