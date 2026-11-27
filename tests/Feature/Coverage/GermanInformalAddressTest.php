<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * The German texts a customer reads say "du", everywhere (maintainer decision of
 * 26 November 2026). The portal and the checkout had drifted into a mix: one sentence
 * "Deine Testphase", the next "Bitte geben Sie …" — sometimes on the same screen.
 *
 * This walks every German file that reaches the customer (portal, checkout, mails,
 * errors, catalog labels) and refuses the formal pronouns. admin.php and audit.php are
 * for the operator and keep their own register; exceptions.php reaches both and is
 * written to address nobody. Notification groups whose subject starts with "[Admin]"
 * go to the operator and are skipped as well.
 *
 * "Sie" is also the third person ("Die Änderung … Sie wird erneut versucht"), which a
 * pattern cannot tell apart from the polite form. Those few sentences are listed below
 * by their exact wording, so a formal "Sie" slipped into the same text still fails.
 */

dataset('german customer lang files', [
    'addons', 'checkout', 'countries', 'emails', 'enums', 'errors', 'features',
    'notifications', 'plans', 'portal', 'product_groups', 'products', 'usages',
]);

it('addresses the customer as du in resources/lang/de/:dataset', function (string $file): void {
    // Third-person "Sie" (it / they), by key and exact phrase.
    $thirdPerson = [
        'portal.scheduled_change_retry' => ['Sie wird in der kommenden Nacht erneut versucht'],
        'portal.scheduled_change_dropped_payment' => ['Sie wird vollständig erstattet'],
        'portal.flash.scheduled_change_not_yet' => ['Sie bleibt für ihren Termin vorgemerkt'],
    ];

    $lines = require dirname(__DIR__, 3).'/resources/lang/de/'.$file.'.php';
    $offending = [];

    foreach (Arr::dot($lines) as $key => $text) {
        if (! is_string($text)) {
            continue;
        }

        $group = explode('.', (string) $key)[0];
        if ($file === 'notifications' && str_starts_with((string) ($lines[$group]['subject'] ?? ''), '[Admin]')) {
            continue;
        }

        $fullKey = $file.'.'.$key;
        $text = str_replace($thirdPerson[$fullKey] ?? [], '', $text);

        if (preg_match_all('/\b(Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u', $text, $m) > 0) {
            $offending[$fullKey] = implode(', ', array_unique($m[1]));
        }
    }

    expect($offending)->toBe([], 'Formal address in German customer texts: '.json_encode($offending, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
})->with('german customer lang files');

it('keeps the third-person exceptions pointing at sentences that still exist', function (): void {
    // If one of these sentences is reworded, the allow-list above would silently stop
    // matching and stop protecting anything; make that loud instead.
    expect(trans('billing::portal.scheduled_change_retry', ['attempt' => 1, 'max' => 3], 'de'))
        ->toContain('Sie wird in der kommenden Nacht erneut versucht')
        ->and(trans('billing::portal.scheduled_change_dropped_payment', [], 'de'))
        ->toContain('Sie wird vollständig erstattet')
        ->and(trans('billing::portal.flash.scheduled_change_not_yet', [], 'de'))
        ->toContain('Sie bleibt für ihren Termin vorgemerkt');
});
