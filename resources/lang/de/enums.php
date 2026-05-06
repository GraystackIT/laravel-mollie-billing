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
        'overage' => 'Mehrverbrauch',
        'one_time_order' => 'Einmalkauf',
        'refund' => 'Erstattung',
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
        'single_payment' => 'Einmalzahlung',
        'recurring' => 'Wiederkehrend',
        'credits' => 'Guthaben',
        'trial_extension' => 'Testverlängerung',
        'access_grant' => 'Zugangsgewährung',
        'period_extension' => 'Periodenverlängerung',
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
    'country_mismatch_strategy' => [
        'auto_vies' => 'Automatisch (VIES)',
        'auto_payment' => 'Automatisch (Zahlungsland)',
        'auto_noop' => 'Automatisch (keine Aktion nötig)',
        'manual' => 'Manuell',
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
    'oss_export_status' => [
        'queued' => 'In Warteschlange',
        'processing' => 'In Bearbeitung',
        'ready' => 'Bereit',
        'failed' => 'Fehlgeschlagen',
    ],
];
