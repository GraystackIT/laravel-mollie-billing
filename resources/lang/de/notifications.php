<?php

return [
    // Stands in for :amount when the amount behind a failed charge cannot be
    // resolved, so no notification ever renders a wrong or zero amount.
    'amount_unknown' => 'einen unbekannten Betrag',
    'trial_ending_soon' => [
        'subject' => 'Deine Testphase endet in :days Tagen',
        'body_with_mandate' => 'Deine :app-Testphase endet am :date. Wir wandeln sie automatisch in ein bezahltes Abonnement mit deiner hinterlegten Zahlungsmethode um — du musst nichts weiter tun.',
        'body_without_mandate' => 'Deine :app-Testphase endet am :date. Bitte hinterlege bis dahin eine Zahlungsmethode, um den Dienst weiterhin nutzen zu können.',
    ],
    'trial_expired' => [
        'subject' => 'Deine :app-Testphase ist abgelaufen',
        'body' => 'Deine Testphase ist am :date abgelaufen und es wurde rechtzeitig keine Zahlungsmethode hinterlegt. Du kannst jederzeit ein Abonnement abschließen, um den Zugriff wiederherzustellen — deine bisherige Konfiguration bleibt erhalten.',
        'body_with_mandate' => 'Deine Testphase ist am :date abgelaufen, die erste Zahlung für dein Abonnement ist aber nicht bei uns eingegangen. Bitte prüfe deine Zahlungsmethode und schließe das Abonnement erneut ab, um den Zugriff wiederherzustellen — deine bisherige Konfiguration bleibt erhalten.',
    ],
    'unwanted_payment_refunded' => [
        'subject' => 'Ihre Zahlung bei :app wird erstattet',
        'refund' => 'Der Betrag von :amount wird auf das verwendete Zahlungsmittel zurückerstattet. Je nach Bank kann das einige Tage dauern.',
        'reasons' => [
            'default' => 'Wir haben eine Zahlung von Ihnen erhalten, die nicht zugeordnet werden konnte.',
            'renewal_after_end' => 'Wir haben eine Verlängerung für Ihr Abo abgebucht, obwohl es bereits beendet ist. Das war ein Versehen unsererseits; das Abo wird nicht weiter belastet.',
            'activation_failed' => 'Ihre Zahlung ist bei uns eingegangen, Ihr Abo konnte jedoch trotz mehrerer Versuche nicht eingerichtet werden.',
            'change_cancelled' => 'Wir haben die Zahlung für eine Änderung Ihres Abos erhalten, die Sie zwischenzeitlich storniert hatten. Die Änderung wurde nicht durchgeführt.',
            'subscription_ended' => 'Wir haben die Zahlung für eine Änderung Ihres Abos erhalten, das Abo war zwischenzeitlich jedoch beendet. Die Änderung wurde nicht durchgeführt.',
        ],
    ],
    'scheduled_change_not_applied' => [
        'subject' => 'Ihre geplante Änderung bei :app wurde nicht durchgeführt',
        'body' => 'Die von Ihnen geplante Änderung auf :plan konnte nicht durchgeführt werden. Ihr Abo läuft unverändert weiter.',
        'next' => 'Sie können die Änderung jederzeit erneut vornehmen.',
        'payment_returned' => 'Eine Zahlung für diese Änderung war bereits unterwegs und ließ sich nicht mehr aufhalten. Wir erstatten sie vollständig, sobald sie eingeht, und bestätigen das gesondert.',
        'action' => 'Abo verwalten',
        'reasons' => [
            'default' => 'Die Änderung ist mit Ihrem Abo in seinem jetzigen Zustand nicht möglich.',
            'attempts_exhausted' => 'Wir haben es mehrfach versucht; ein technisches Problem hat es jedes Mal verhindert.',
            'seats_in_use' => 'Es sind mehr Plätze belegt, als der gewählte Plan zulässt. Bitte geben Sie zuerst Plätze frei.',
            'payment_outstanding' => 'Es ist noch eine Zahlung offen. Bitte begleichen Sie diese zuerst.',
            'not_available' => 'Der gewählte Plan oder eine seiner Optionen steht für Ihr Abo nicht mehr zur Verfügung.',
        ],
    ],
    'undercollection_charged' => [
        'subject' => 'Nachbelastung für Ihr Abo bei :app',
        'charge' => 'Wir ziehen den Differenzbetrag von :amount als gesonderte Zahlung von Ihrem Zahlungsmittel ein.',
        'invoice' => 'Sie erhalten dafür eine eigene Rechnung.',
        'reasons' => [
            'default' => 'Ihre letzte reguläre Zahlung hat nicht alles abgedeckt, was Ihr Abo in diesem Abrechnungszeitraum umfasst hat.',
            'renewal_created_before_change' => 'Sie haben kurz vor der Verlängerung Ihres Abos Plätze oder ein Addon hinzugebucht. Die Verlängerung war zu diesem Zeitpunkt bereits mit dem bisherigen Betrag vorbereitet und enthielt diese daher nicht.',
            'plan_change_spanned_renewal' => 'Ihr Planwechsel wurde wirksam, nachdem sich Ihr Abo bereits zum Preis des bisherigen Plans verlängert hatte. Die Verlängerung hat den neuen Plan daher nicht abgedeckt.',
        ],
    ],
    'grant_extras_purchase_refunded' => [
        'subject' => 'Dein Kauf bei :app konnte nicht ausgeführt werden',
        'body' => 'Dein Kauf von Extras konnte nicht ausgeführt werden, weil dein Gratiszeitraum inzwischen beendet ist.',
        'refund' => 'Der Betrag von :amount wird auf die verwendete Zahlungsmethode erstattet. Je nach Bank kann das einige Tage dauern.',
        'next' => 'Du kannst jederzeit einen Plan buchen.',
    ],
    'trial_converted' => [
        'subject' => 'Willkommen bei :app',
        'body' => 'Deine Testphase wurde in ein bezahltes :plan-Abonnement umgewandelt. Deine erste Rechnung über :amount wurde ausgestellt.',
    ],
    'payment_failed' => [
        'subject' => 'Aktion erforderlich: Zahlung fehlgeschlagen',
        'body' => 'Wir konnten deine letzte Zahlung über :amount am :date nicht verarbeiten. Bitte überprüfe deine Zahlungsmethode, damit dein :app-Abonnement aktiv bleibt.',
    ],
    'subscription_cancelled' => [
        'subject' => 'Dein :app-Abonnement wurde gekündigt',
        'body' => 'Dein Abonnement wurde gekündigt und bleibt bis :date aktiv. Du kannst es jederzeit bis dahin wieder aktivieren, um deinen aktuellen Tarif zu behalten.',
    ],
    'invoice_available' => [
        'subject' => 'Neue Rechnung von :app',
        'body' => 'Eine neue Rechnung über :amount steht in deinem Abrechnungsbereich bereit.',
    ],
    'usage_threshold' => [
        'subject' => ':type-Nutzung bei :percent%',
        'body' => 'Du hast :percent% deines :type-Kontingents für die laufende Abrechnungsperiode genutzt. :overage_hint',
        'overage_allowed' => 'Zusätzliche Nutzung wird am Ende der Periode abgerechnet.',
        'overage_blocked' => 'Weitere :type-Nutzung ist erst in der nächsten Periode oder nach einem Tarifwechsel möglich.',
    ],
    'overage_billing_failed' => [
        'subject' => 'Mehrverbrauch konnte nicht abgebucht werden',
        'body' => 'Wir haben dreimal versucht, :amount für deinen Mehrverbrauch abzubuchen, doch die Zahlung ist fehlgeschlagen.',
        'will_retry' => 'Wir versuchen es mit deiner nächsten Verlängerung automatisch erneut. Bitte prüfe deine Zahlungsmethode. Solange der Betrag offen ist, kannst du in deinem Abo nichts Neues buchen.',
        'final' => 'Wir werden diesen Betrag nicht erneut einziehen.',
    ],
    'admin_overage_billing_failed' => [
        'subject' => '[Admin] Mehrverbrauch für :customer abgeschrieben',
        'body' => 'Der Mehrverbrauch für :customer (:amount) konnte nicht eingezogen werden – weder im eigenen Mahnlauf noch bei den :attempts Verlängerungen, die es erneut versucht haben. Er wurde abgeschrieben; im Wallet ist nichts mehr offen. Die Buchungssperre ist aufgehoben; ist zusätzlich eine Verlängerung unbezahlt, bleibt das Abo deshalb überfällig.',
    ],
    'country_mismatch_self' => [
        'subject' => 'Wir müssen dein Rechnungsland verifizieren',
        'body_intro' => 'Wir konnten das von dir angegebene Rechnungsland nicht bestätigen. Es stimmt nicht mit den Daten deiner Zahlungsmethode oder deiner IP-Adresse überein.',
        'body_user' => 'Eingegeben: :country',
        'body_payment' => 'Land der Zahlungsmethode: :country',
        'body_ip' => 'Land der IP-Adresse: :country',
        'body_consequence' => 'Deine Subscription wird zum Ende der aktuellen Periode gekündigt. Bitte korrigiere deine Rechnungsadresse über das Dashboard, damit sie aktiv bleibt.',
        'cta' => 'Zum Billing-Dashboard',
    ],
    'country_mismatch_reissue_failed' => [
        'subject' => '[Admin] Länderkorrektur-Re-Charge fehlgeschlagen (Mismatch #:mismatch)',
        'body' => 'Ein Re-Charge zur Länderkorrektur für :customer ist bei Mollie fehlgeschlagen: :reason. Der Mismatch wurde auf Pending zurückgesetzt, damit der Kunde erneut auflösen kann; eine manuelle Nachverfolgung ist empfehlenswert.',
    ],
    'refund_processed' => [
        'subject' => 'Deine Erstattung über :amount wurde verarbeitet',
        'body' => 'Eine Gutschrift über :amount wurde deinem Konto ausgestellt. Die Erstattung erscheint je nach Bank innerhalb von 5 Werktagen auf deinem Kontoauszug.',
    ],
    'admin_refund_failed' => [
        'subject' => '[Admin] Erstattung für Rechnung :invoice fehlgeschlagen',
        'body' => 'Ein Erstattungsversuch ist fehlgeschlagen: :reason. Manuelles Eingreifen erforderlich.',
    ],
    'plan_change_failed' => [
        'subject' => 'Dein Plan-Upgrade konnte nicht durchgeführt werden',
        'body' => 'Wir konnten die Zahlung für dein Plan-Upgrade nicht verarbeiten. Bitte aktualisiere deine Zahlungsmethode und versuche es erneut, oder kontaktiere den :app-Support.',
    ],
    'admin_plan_change_failed' => [
        'subject' => '[Admin] Plan-Change-Operation dauerhaft fehlgeschlagen',
        'reason' => 'Grund: :reason',
        'context' => 'Kontext: :context',
    ],
];
