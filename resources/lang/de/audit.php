<?php

// Siehe resources/lang/en/audit.php — in der Datenbank steht nur der Schlüssel
// plus Rohwerte, der Text entsteht erst beim Rendern.

return [
    'unknown_event' => 'Unbekanntes Billing-Ereignis',

    // Admin-Timeline
    'tab_label' => 'Audit',
    'tab_title' => 'Aktivitätsverlauf',
    'tab_description' => 'Alle für dieses Billable erfassten Billing-Ereignisse, neueste zuerst.',
    'all_categories' => 'Alle Kategorien',
    'details' => 'Details',
    'empty_title' => 'Noch keine Aktivität',
    'empty_description' => 'Tarifwechsel, Zahlungen, Rechnungen und eingelöste Gutscheincodes erscheinen hier.',

    'category' => [
        'subscription' => 'Abonnement',
        'payment' => 'Zahlung',
        'invoice' => 'Rechnung',
        'payment_method' => 'Zahlungsmethode',
        'coupon' => 'Gutscheincode',
        'trial' => 'Testphase',
        'usage' => 'Verbrauch',
        'compliance' => 'Compliance',
    ],

    'actor' => [
        'system' => 'System',
        'admin' => 'Administration',
        'customer' => 'Kunde',
    ],

    // Abonnement-Lebenszyklus
    'subscription_created' => 'Abonnement gestartet mit :plan (:interval)',
    'subscription_cancelled' => 'Abonnement gekündigt',
    'subscription_resumed' => 'Abonnement fortgesetzt',
    'subscription_expired' => 'Abonnement abgelaufen',
    'subscription_updated' => 'Abonnement geändert (:changes)',
    'subscription_extended' => 'Abrechnungszeitraum per Gutscheincode :code verlängert',
    'subscription_upgraded_from_local' => 'Upgrade vom kostenlosen Tarif :old_plan auf :new_plan (:new_interval)',
    'subscription_activation_failed' => 'Aktivierung von :plan fehlgeschlagen: :reason',
    'plan_changed' => 'Tarif von :old_plan auf :new_plan geändert (:interval)',
    'plan_change_pending' => 'Tarifwechsel auf :new_plan wartet auf Zahlung',
    'plan_change_failed' => 'Tarifwechsel auf :new_plan fehlgeschlagen: :reason',
    'subscription_change_scheduled' => 'Wechsel auf :new_plan vorgemerkt',
    'subscription_change_rescheduled' => 'Vorgemerkter Wechsel von :old_plan auf :new_plan geändert',
    'subscription_change_cancelled' => 'Vorgemerkter Wechsel auf :new_plan storniert',
    'subscription_change_apply_failed' => 'Vorgemerkter Wechsel auf :new_plan konnte nicht angewendet werden: :exception_message',
    'seats_changed' => 'Plätze von :old_count auf :new_count geändert',
    'addon_enabled' => 'Add-on :addon aktiviert',
    'addon_disabled' => 'Add-on :addon deaktiviert',

    // Zahlungen
    'payment_succeeded' => 'Zahlung eingegangen',
    'payment_failed' => 'Zahlung fehlgeschlagen: :reason',
    'payment_amount_mismatch' => 'Gezahlter Betrag weicht vom erwarteten Betrag ab',
    'duplicate_payment_received' => 'Doppelte Zahlung erhalten und ignoriert',
    'checkout_started' => 'Checkout begonnen',
    'checkout_abandoned' => 'Checkout abgebrochen',
    'one_time_order_completed' => 'Einmalkauf :product abgeschlossen',
    'one_time_order_failed' => 'Einmalkauf :product fehlgeschlagen: :reason',
    'overage_charged' => 'Mehrverbrauch abgerechnet',
    'overage_charge_failed' => 'Abrechnung des Mehrverbrauchs fehlgeschlagen (Versuch :attempt)',

    // Rechnungen
    'invoice_created' => 'Rechnung :serial erstellt',
    'invoice_refunded' => 'Rechnung :serial erstattet',
    'credit_note_issued' => 'Gutschrift :serial erstellt',
    'invoice_pdf_regenerated' => 'PDF der Rechnung :serial neu erzeugt',

    // Zahlungsmethode
    'mandate_updated' => 'Zahlungsmethode geändert',

    // Gutscheincodes / Freischaltungen
    'coupon_redeemed' => 'Gutscheincode :code eingelöst',
    'grant_revoked' => 'Über Gutscheincode :code gewährter Zugang widerrufen',

    // Testphase
    'trial_started' => 'Testphase gestartet mit :plan (:days Tage)',
    'trial_converted' => 'Testphase in ein kostenpflichtiges Abonnement überführt (:plan)',
    'trial_expired' => 'Testphase abgelaufen',
    'trial_extended' => 'Testphase verlängert',

    // Verbrauch / Wallet
    'wallet_credited' => ':units :usage_type gutgeschrieben',
    'wallet_reset' => 'Guthaben :usage_type von :previous_balance auf :new_balance zurückgesetzt',
    'usage_limit_reached' => 'Kontingent :usage_type erreicht (:remaining übrig, :attempted angefordert)',

    // USt / OSS-Compliance
    'country_mismatch_flagged' => 'Länderabweichung erkannt: angegeben :declared_country, gezahlt aus :payment_country',
    'country_mismatch_resolved' => 'Länderabweichung geklärt',
];
