<?php

return [
    // Layout
    'title' => 'Checkout — :app',
    'back' => 'Zurück',

    // Step counter
    'step_counter' => 'Schritt :current von :total',

    // Step labels (timeline)
    'step_billing_details' => 'Rechnungsdaten',
    'step_plan' => 'Plan',
    'step_addons' => 'Extras',
    'step_confirm' => 'Bestätigen',

    // Step headlines
    'headline_billing' => 'Wohin sollen wir die Rechnungen senden?',
    'headline_plan' => 'Wählen Sie den passenden Plan',
    'headline_addons' => 'Passen Sie Ihr Abonnement an',
    'headline_confirm' => 'Ein letzter Blick',

    // Step descriptions
    'description_billing' => 'Diese Angaben erscheinen auf jeder Rechnung und Quittung.',
    'description_plan' => 'Sie können Ihren Plan jederzeit in den Abrechnungseinstellungen ändern.',
    'description_addons' => 'Fügen Sie optionale Add-ons oder zusätzliche Plätze hinzu.',
    'description_confirm' => 'Überprüfen Sie Ihre Auswahl. Sie werden nach der Bestätigung zu Mollie weitergeleitet.',

    // Step 1: billing address
    'company_name' => 'Firmenname',
    'street' => 'Straße und Hausnummer',
    'postal_code' => 'Postleitzahl',
    'city' => 'Stadt',
    'country' => 'Land',
    'address' => 'Adresse',
    'vat_number' => 'USt-IdNr. (optional)',

    // VAT validation
    'vat_invalid_format' => 'Ungültiges Format der USt-IdNr.',
    'vat_country_mismatch' => 'Das Länderkürzel der USt-IdNr. muss zum gewählten Land passen.',
    'vies_unavailable' => 'VIES ist vorübergehend nicht verfügbar. Wir prüfen vor der ersten Rechnung erneut.',
    'vies_validation_failed' => 'Die USt-IdNr. konnte nicht über VIES verifiziert werden.',
    'vat_verified' => 'USt-IdNr. verifiziert — Reverse-Charge wird angewendet.',
    'vat_correct_or_clear' => 'Bitte korrigieren Sie die USt-IdNr. oder lassen Sie das Feld leer.',

    // Step 2: plan
    'monthly' => 'Monatlich',
    'yearly' => 'Jährlich',
    'prices_net' => 'Preise netto (Reverse-Charge wird angewendet).',
    'prices_incl_vat' => 'Preise inkl. :rate% MwSt. für :country.',
    'tier' => 'Stufe :tier',
    'trial_days' => ':days Tage Testphase',
    'per_month' => 'Mo.',
    'per_year' => 'Jahr',
    'seats_included' => ':count Plätze inklusive',

    // Step 3: addons + seats
    'addons_heading' => 'Add-ons',
    'addons_description' => 'Zusätzliche Funktionen, die Sie zu Ihrem Plan hinzufügen können.',
    'extra_seats' => 'Zusätzliche Plätze',
    'extra_seat_price' => 'Jeder zusätzliche Platz:',

    // Step 4: confirm
    'billing_details' => 'Rechnungsdaten',
    'order' => 'Bestellung',
    'plan' => 'Plan',
    'billing_interval' => 'Abrechnung',
    'coupon_code' => 'Gutscheincode',
    'coupon_placeholder' => 'Gutscheincode eingeben',
    'apply_coupon' => 'Anwenden',
    'remove_coupon' => 'Gutschein entfernen',
    'subtotal' => 'Zwischensumme',
    'discount' => 'Rabatt (:code)',
    'net' => 'Netto',
    'vat_label' => 'MwSt.',
    'vat_with_rate' => 'MwSt. (:rate%)',
    'reverse_charge' => 'Reverse-Charge',
    'total' => 'Gesamt',
    'redirect_notice' => 'Nach der Bestätigung werden Sie zu Mollie weitergeleitet, um Ihre Zahlungsmethode zu autorisieren. Sie können jederzeit kündigen.',
    'confirm_and_pay' => 'Bestätigen und bezahlen',
    'continue' => 'Weiter',

    // Coupon errors
    'coupon_empty' => 'Bitte geben Sie einen Gutscheincode ein.',
    'coupon_already_applied' => 'Entfernen Sie den aktuellen Gutschein, bevor Sie einen neuen hinzufügen.',
    'coupon_failed' => 'Gutschein konnte nicht angewendet werden.',
    'coupon_not_found' => 'Gutscheincode nicht gefunden.',
    'coupon_inactive' => 'Dieser Gutschein ist nicht mehr aktiv.',
    'coupon_not_yet_valid' => 'Dieser Gutschein ist noch nicht gültig.',
    'coupon_expired' => 'Dieser Gutschein ist abgelaufen.',
    'coupon_exhausted' => 'Dieser Gutschein hat sein Einlöselimit erreicht.',
    'coupon_plan_mismatch' => 'Dieser Gutschein gilt nicht für den gewählten Plan.',
    'coupon_interval_mismatch' => 'Dieser Gutschein gilt nicht für das gewählte Abrechnungsintervall.',
    'coupon_addon_mismatch' => 'Dieser Gutschein gilt nicht für die gewählten Add-ons.',
    'coupon_min_order' => 'Die Bestellung erreicht nicht den Mindestbetrag für diesen Gutschein.',
    'coupon_requires_billable' => 'Dieser Gutschein kann beim Checkout nicht angewendet werden.',

    // Promo (existing)
    'coupon_applied' => 'Gutschein :code angewendet.',
    'promo_auto_applied' => 'Aktion :code wurde automatisch angewendet.',
    'promo_invalid' => 'Dieser Aktionslink ist abgelaufen oder nicht mehr gültig.',
    'yearly_savings' => ':percent% sparen mit jährlicher Abrechnung',

    // Errors
    'error_no_billable' => 'Das Abrechnungskonto konnte nicht aufgelöst werden. Bitte versuchen Sie es erneut.',
    'error_payment_creation' => 'Die Zahlung konnte nicht erstellt werden. Bitte versuchen Sie es erneut.',
];
