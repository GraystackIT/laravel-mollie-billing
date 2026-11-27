<?php

/*
 * Was ein Bildschirm zeigt, wenn ein Service ablehnt. Die Exception-Nachricht bleibt
 * englisch und ist für das Log; dies sind die Sätze für Menschen, aufgelöst über
 * GraystackIT\MollieBilling\Support\ExceptionMessage. Sie erreichen Admin-Bereich und
 * Kundenportal, deshalb sprechen sie niemanden direkt an.
 */
return [
    'generic' => 'Die Aktion konnte nicht ausgeführt werden.',
    'refused_with_detail' => 'Die Aktion wurde abgelehnt: :message',

    'subscription_change_in_progress' => 'Eine andere Änderung an diesem Abonnement wird gerade noch verarbeitet. Bitte in einem Moment erneut versuchen.',
    'refund_in_progress' => 'Für diese Rechnung läuft bereits eine Erstattung. Bitte erneut versuchen, sobald sie abgeschlossen ist.',
    'previous_subscription_still_running' => 'Das Abonnement kann noch nicht fortgesetzt werden: Das vorherige Mollie-Abonnement ist noch nicht gekündigt. Bitte in Kürze erneut versuchen.',
    'refund_exceeds_invoice' => 'Der Erstattungsbetrag übersteigt den noch erstattbaren Betrag dieser Rechnung.',
    'refund_exceeds_line' => 'Der Erstattungsbetrag übersteigt den Rest dieser Rechnungsposition.',
    'seat_count_exceeds_maximum' => ':requested Sitze überschreiten das Maximum von :max.',
    'seat_downgrade_required' => 'Dieser Tarif enthält :included Sitze, belegt sind :used. Zuerst die Sitze reduzieren.',
    'hidden_plan_not_selectable' => 'Der Tarif :plan kann nicht gewählt werden.',
    'interval_not_offered' => ':code wird mit diesem Abrechnungsintervall (:interval) nicht angeboten.',
    'addon_not_allowed' => 'Das Add-on :addon ist im Tarif :plan nicht verfügbar.',
    'local_cannot_purchase_products' => 'Mit einem kostenlosen Tarif können keine Produkte gekauft werden. Zuerst auf einen kostenpflichtigen Tarif wechseln.',
    'local_no_paid_extras' => 'Ein kostenloser Tarif kann keine kostenpflichtigen Add-ons oder zusätzlichen Sitze enthalten. Zuerst auf einen kostenpflichtigen Tarif wechseln.',
    'local_upgrade_requires_checkout' => 'Der Wechsel von einem kostenlosen Tarif zum kostenpflichtigen Tarif :plan erfordert einen Checkout mit Zahlungsmittel.',
    'non_eu_country' => 'Für das Land :country kann keine Umsatzsteuer berechnet werden: Es liegt weder in der EU, noch ist es als zusätzliches Land konfiguriert.',
    'billing_country_required' => 'Es ist kein Rechnungsland hinterlegt. Vor einem Kauf müssen die Rechnungsdaten vervollständigt werden.',
    'downgrade_requires_mandate' => 'Für den Wechsel zum Tarif :plan ist ein Zahlungsmittel erforderlich.',
    'usage_limit_exceeded' => 'Das Limit für :type im aktuellen Zeitraum ist erreicht.',
    'grant_mismatch' => 'Die Zugangsberechtigung passt nicht zum aktuellen Tarif oder Abrechnungsintervall.',
    'access_grant_conflicts_with_mollie' => 'Zugangsberechtigungen können nicht angewendet werden, solange ein kostenpflichtiges Abonnement aktiv ist.',
    'access_grant_requires_active_subscription' => 'Für diese Add-on-Berechtigung ist ein aktives Abonnement erforderlich.',
    'coupon_not_stackable' => 'Dieser Gutschein kann nicht mit anderen aktiven Gutscheinen kombiniert werden.',
    'vies_unavailable' => 'Der EU-Dienst zur Prüfung von USt-IdNrn. (VIES) ist gerade nicht erreichbar. Bitte später erneut versuchen.',

    'state' => [
        'no_subscription_to_cancel' => 'Es gibt kein Abonnement, das gekündigt werden kann.',
        'schedule_not_running' => 'Solange das Abonnement den Status „:status“ hat, kann keine Änderung geplant werden. Zuerst das Abonnement fortsetzen.',
        'period_not_renewed' => 'Das Abonnement kann gerade nicht geändert werden: Der aktuelle Abrechnungszeitraum endete am :date und wurde noch nicht verlängert. Bitte die Verlängerung abwarten.',
        'no_active_subscription' => 'Der Tarif kann nicht gewechselt werden: Es gibt kein aktives Abonnement. Zuerst den Checkout abschließen.',
        'interval_locked_while_free' => 'Das Abrechnungsintervall kann nicht geändert werden, solange der Tarif kostenlos ist. Erst nach Ende der kostenlosen Zeit ändern.',
        'interval_locked_while_free_until' => 'Das Abrechnungsintervall kann nicht geändert werden, solange der Tarif kostenlos ist (bis :date). Erst nach Ende der kostenlosen Zeit ändern.',
        'plan_change_pending_payment' => 'Ein Tarifwechsel wartet bereits auf die Bestätigung seiner Zahlung. Zuerst stornieren oder abwarten, bis die Zahlung abgeschlossen ist.',
        'upgrade_no_mandate' => 'Das Upgrade kann nicht abgerechnet werden: Es ist kein Zahlungsmittel (Mollie-Mandat) hinterlegt.',
        'upgrade_no_mollie_subscription' => 'Das Upgrade kann nicht abgerechnet werden: Das Mollie-Abonnement fehlt.',
        'resubscribe_mismatch_open' => 'Das Abonnement kann nicht fortgesetzt werden, solange eine Länderabweichung offen ist. Zuerst die Abweichung auflösen.',
        'not_extendable' => 'Dieser Mandant kann nicht verlängert werden.',
        'no_running_grant' => 'Auf diesem Abonnement läuft kein Grant, der verlängert werden könnte.',
        'no_running_grant_free_periods' => 'Auf diesem Abonnement läuft kein Grant, der verlängert werden könnte. Kostenlose Tarifperioden werden verlängert, indem weitere Perioden vergeben werden.',
        'trial_not_while_status' => 'Die Testphase kann nicht verlängert werden, solange das Abonnement den Status „:status“ hat. Stattdessen das Abonnement fortsetzen.',
        'trial_local_not_on_trial' => 'Ein kostenloses Abonnement ohne laufende Testphase kann keine Testphase verlängern. Stattdessen den Grant oder den Zeitraum verlängern.',
        'trial_before_next_charge' => 'Dieses Abonnement wird nicht vor dem :date abgebucht. Eine Testphase, die an oder vor diesem Datum endet, brächte dem Kunden nichts – bitte ein späteres Datum wählen.',
    ],

    'refund' => [
        'only_paid' => 'Nur bezahlte Rechnungen können erstattet werden.',
        'no_payment' => 'Zu dieser Rechnung gibt es keine Mollie-Zahlung, gegen die erstattet werden kann: Sie hat keine eingezogen, und auch keine Rechnung, aus der sie beglichen wurde.',
        'only_overage' => 'Einheiten können nur auf Mehrverbrauchsrechnungen erstattet werden.',
        'usage_type_not_found' => 'Die Verbrauchsart :type steht nicht auf dieser Rechnung.',
        'line_not_refundable' => 'Diese Rechnungsposition kann nicht einzeln erstattet werden.',
        'reason_required' => 'Ein Erstattungsgrund ist erforderlich.',
        'line_and_items' => 'Eine Erstattung nach Position kann nicht mit eigenen Rechnungspositionen kombiniert werden.',
        'reason_text_required' => 'Beim Grund „Sonstiges“ ist eine Beschreibung erforderlich.',
        'amount_positive' => 'Der Erstattungsbetrag muss größer als null sein.',
        'units_positive' => 'Die zu erstattenden Einheiten für :type müssen eine positive ganze Zahl sein.',
        'units_exceed' => ':units Einheiten :type können nicht erstattet werden: Auf dieser Rechnung sind nur noch :remaining erstattbar.',
    ],

    'coupon' => [
        'code_required' => 'Bitte einen Coupon-Code eingeben.',
        'code_taken' => 'Ein Coupon mit dem Code :code existiert bereits.',
        'code_locked' => 'Der Code eines bereits eingelösten Coupons kann nicht geändert werden.',
        'type_locked' => 'Der Typ eines bereits eingelösten Coupons kann nicht geändert werden.',
        'revoke_only_grants' => 'Nur Zugangsberechtigungen und Tarifbefreiungen können widerrufen werden.',
        'value_positive' => 'Der Rabattwert muss größer als null sein.',
        'percent_max' => 'Ein Rabattwert über 100 % ist nicht zulässig.',
        'discount_required' => 'Bitte eine Rabattart wählen und einen Rabattwert eingeben.',
        'periods_positive' => 'Die Anzahl der rabattierten Abrechnungszeiträume muss größer als null sein.',
        'recurring_needs_limit' => 'Wiederkehrende Coupons brauchen eine Begrenzung. Entweder „Rabatt gilt für … Zeiträume“ (Anzahl rabattierter Abbuchungen) oder ein „Gültig bis“-Datum festlegen.',
        'credits_required' => 'Guthaben-Coupons brauchen die gutzuschreibenden Einheiten.',
        'trial_days_required' => 'Bitte eingeben, um wie viele Tage die Testphase verlängert werden soll.',
        'grant_fields_required' => 'Bitte einen Tarif und ein Intervall wählen und eine Dauer in Tagen eingeben.',
        'grant_or_addons_required' => 'Bitte einen Tarif mit Intervall und Dauer wählen oder für eine reine Add-on-Berechtigung mindestens ein Add-on auswählen.',
        'extension_days_required' => 'Bitte eingeben, um wie viele Tage der Abrechnungszeitraum verlängert werden soll.',
        'waiver_periods_required' => 'Bitte eingeben, für wie viele Abrechnungszeiträume der Tarif kostenlos sein soll.',
    ],

    'extension' => [
        'days_positive' => 'Die Anzahl der Tage muss größer als null sein.',
        'periods_positive' => 'Die Anzahl der Zeiträume muss größer als null sein.',
    ],

    'country' => [
        'invalid' => ':country ist kein gültiger zweistelliger Ländercode.',
        'mismatch_already_resolved' => 'Diese Abweichung ist bereits auf :chosen aufgelöst. Für eine Korrektur auf :country eine neue Abweichung anlegen.',
        'needs_justification' => 'Das Land :country passt weder zur Zahlung (:payment) noch zum IP-Signal (:ip). Um es dennoch als geprüft zu erfassen, ist eine Begründung erforderlich.',
    ],
];
