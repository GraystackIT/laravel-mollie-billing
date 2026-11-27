<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Exceptions\TranslatableException;

/*
 * The screens show a service's refusal through ExceptionMessage, which reads the
 * translation key the exception carries. An exception thrown without one falls back to a
 * generic sentence (customers) or to the English message (operators) — which is exactly
 * the English-on-a-German-screen this mechanism replaced. So:
 *
 * - every package exception a screen can show implements TranslatableException;
 * - the exceptions thrown for many different reasons (an invalid state, an invalid refund
 *   target) are given their sentence at every throw site;
 * - the services whose input refusals the admin forms show throw InvalidBillingInputException,
 *   never a bare \InvalidArgumentException.
 *
 * Literal keys are checked for existence in both locales by TranslationKeysExistTest.
 */

it('makes every exception a screen can show translatable', function (string $class): void {
    expect(is_subclass_of('GraystackIT\\MollieBilling\\Exceptions\\'.$class, TranslatableException::class))->toBeTrue();
})->with([
    'AccessGrantConflictsWithMollieSubscriptionException',
    'AccessGrantRequiresActiveSubscriptionException',
    'ActivationInFlightException',
    'AddonNotAllowedException',
    'BillingCountryRequiredException',
    'CouponNotStackableException',
    'DowngradeRequiresMandateException',
    'GrantMismatchException',
    'HiddenPlanNotSelectableException',
    'IntervalNotOfferedException',
    'InvalidBillingInputException',
    'InvalidRefundTargetException',
    'InvalidSubscriptionStateException',
    'LocalSubscriptionCannotPurchaseProductsException',
    'LocalSubscriptionDoesNotSupportPaidExtrasException',
    'LocalSubscriptionUpgradeRequiresMolliePathException',
    'NonEuCountryException',
    'PastDueExpansionNotAllowedException',
    'PendingPaymentForAnotherOrderException',
    'PreviousSubscriptionStillRunningException',
    'RefundExceedsInvoiceAmountException',
    'RefundExceedsLineAmountException',
    'RefundInProgressException',
    'SeatCountExceedsMaximumException',
    'SeatDowngradeRequiredException',
    'SubscriptionChangeInProgressException',
    'SubscriptionEndedChangeNotAllowedException',
    'UsageLimitExceededException',
    'ViesUnavailableException',
]);

/** @return array<string, string> relative path => contents, for every PHP file under src/ */
function excGuardSources(): array
{
    $root = dirname(__DIR__, 3);
    $out = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($out);

    return $out;
}

/**
 * Every `new <Class>(` in the source, with what follows its closing parenthesis.
 *
 * @return list<array{0: string, 1: string}> [file:line, the text after the constructor call]
 */
function excGuardConstructions(string $class): array
{
    $found = [];

    foreach (excGuardSources() as $path => $code) {
        if (str_starts_with($path, 'src/Exceptions/')) {
            continue;
        }

        $offset = 0;
        while (preg_match('/new\s+\\\\?(?:[A-Za-z_\\\\]+\\\\)?'.$class.'\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            $quote = null;

            for (; $i < strlen($code) && $depth > 0; $i++) {
                $c = $code[$i];
                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                }
            }

            $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
            $found[] = [$path.':'.$line, substr($code, $i, 40)];
            $offset = $i;
        }
    }

    return $found;
}

it('gives every throw of a many-reasons exception its sentence', function (string $class): void {
    $sites = excGuardConstructions($class);

    expect($sites)->not->toBe([]);

    $bare = array_values(array_map(
        fn (array $site) => $site[0],
        array_filter($sites, fn (array $site) => ! preg_match('/^\)\s*->\s*withTranslation\(/', $site[1])),
    ));

    expect($bare)->toBe([], "{$class} thrown without ->withTranslation():\n".implode("\n", $bare));
})->with(['InvalidSubscriptionStateException', 'InvalidRefundTargetException']);

it('refuses admin-form input with a translatable exception', function (string $path): void {
    $code = excGuardSources()[$path];

    expect(preg_match_all('/new\s+\\\\InvalidArgumentException\(/', $code))->toBe(0, "{$path} throws a bare \\InvalidArgumentException");
})->with([
    'src/Services/Billing/AdminExtensionService.php',
    'src/Services/Billing/CouponService.php',
    'src/Services/Billing/RefundInvoiceService.php',
    'src/Services/Vat/CountryMatchService.php',
]);
