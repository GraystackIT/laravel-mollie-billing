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
    'renewal_collected_for_ended_subscription' => 'Eine Verlängerung wurde für ein nicht mehr laufendes Abo eingezogen (:status) — muss erstattet werden',
    'renewal_undercollected' => 'Eine Abrechnungsperiode wurde unter dem Preis ihrer Konfiguration eingezogen — die Differenz wird gesondert nachbelastet',
    'payment_amount_mismatch' => 'Gezahlter Betrag weicht vom erwarteten Betrag ab',
    'duplicate_payment_received' => 'Doppelte Zahlung erhalten — sie wird vollständig erstattet, wenn sie Geld eingezogen hat',
    'checkout_started' => 'Checkout begonnen',
    'checkout_abandoned' => 'Checkout abgebrochen',
    'one_time_order_completed' => 'Einmalkauf :product abgeschlossen',
    'one_time_order_failed' => 'Einmalkauf :product fehlgeschlagen: :reason',
    'overage_charged' => 'Mehrverbrauch abgerechnet',
    'overage_charge_failed' => 'Abrechnung des Mehrverbrauchs fehlgeschlagen (Versuch :attempt)',
    'overage_written_off' => 'Unbezahlter Mehrverbrauch nach :attempts weiteren Einzugsversuchen abgeschrieben',

    // Rechnungen
    'invoice_created' => 'Rechnung :serial erstellt',
    'invoice_refunded' => 'Rechnung :serial erstattet',
    'credit_note_issued' => 'Gutschrift :serial erstellt',
    'refund_reversed' => 'Erstattung nicht angekommen – Gutschrift mit :serial storniert',
    'grant_extras_purchase_refunded' => 'Kauf von Extras nicht ausgeführt — der Gratiszeitraum war beendet; Zahlung :serial erstattet',
    'unwanted_payment_refunded' => 'Eine nicht mehr gewünschte Zahlung (:serial) wurde automatisch erstattet',
    'chargeback_reversed' => 'Rückbuchung zu Rechnung :serial zurückgenommen',
    'chargeback_on_refunded_payment' => 'Zahlung zu Rechnung :serial zurückgebucht, obwohl sie erstattet war — doppelt ausgezahlt',
    'invoice_pdf_regenerated' => 'PDF der Rechnung :serial neu erzeugt',

    // Zahlungsmethode
    'mandate_updated' => 'Zahlungsmethode geändert',
    'mollie_subscription_reference_cleared' => 'Abonnement bei Mollie nicht mehr vorhanden — Referenz entfernt',
    'mollie_subscription_replaced' => 'Abonnement bei Mollie ersetzt, um die nächste Abbuchung zu verschieben',

    // Gutscheincodes / Freischaltungen
    'coupon_redeemed' => 'Gutscheincode :code eingelöst',
    'coupon_redemption_record_missing' => 'Gutscheincode :code wurde bei der Zahlung berücksichtigt, die Einlösung konnte aber nicht verbucht werden',
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
