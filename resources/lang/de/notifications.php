<?php

return [
    'trial_ending_soon' => [
        'subject' => 'Deine Testphase endet in :days Tagen',
        'body_with_mandate' => 'Deine :app-Testphase endet am :date. Wir wandeln sie automatisch in ein bezahltes Abonnement mit deiner hinterlegten Zahlungsmethode um — du musst nichts weiter tun.',
        'body_without_mandate' => 'Deine :app-Testphase endet am :date. Bitte hinterlege bis dahin eine Zahlungsmethode, um den Dienst weiterhin nutzen zu können.',
    ],
    'trial_expired' => [
        'subject' => 'Deine :app-Testphase ist abgelaufen',
        'body' => 'Deine Testphase ist am :date abgelaufen und es wurde rechtzeitig keine Zahlungsmethode hinterlegt. Du kannst jederzeit ein Abonnement abschließen, um den Zugriff wiederherzustellen — deine bisherige Konfiguration bleibt erhalten.',
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
        'subject' => 'Dienst pausiert: Mehrverbrauch konnte nicht abgebucht werden',
        'body' => 'Wir haben dreimal versucht, :amount für deinen Mehrverbrauch abzubuchen, doch die Zahlung ist fehlgeschlagen. Der Dienst ist pausiert, bis du deine Zahlungsmethode aktualisierst.',
    ],
    'admin_overage_billing_failed' => [
        'subject' => '[Admin] Mehrverbrauchsabbuchung für :customer fehlgeschlagen',
        'body' => 'Nach 3 Versuchen konnte der Mehrverbrauch für :customer (:amount) nicht eingezogen werden. Der Kunde wurde auf past_due gesetzt.',
    ],
    'country_mismatch' => [
        'subject' => '[Admin] Länderabweichung für :customer markiert',
        'body' => 'Die Länderprüfung ist für :customer fehlgeschlagen. Angegeben: :user, IP: :ip, Zahlung: :payment. Manuelle Prüfung erforderlich.',
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
];
