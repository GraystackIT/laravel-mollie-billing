<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Support\ProrataLine;

/**
 * amount_net is the authoritative line total; unit_price_net is a derived display
 * value. fromArray() has to survive payloads that only carry part of the shape.
 */
function prorataLine(int $amountNet, int $quantity): ProrataLine
{
    return new ProrataLine(
        originalInvoice: null,
        originalLineItemIndex: null,
        kind: 'seats',
        code: null,
        label: 'Extra seats',
        quantity: $quantity,
        amountNet: $amountNet,
        vatRate: 19.0,
        amountVat: (int) round($amountNet * 19 / 100),
        amountGross: $amountNet + (int) round($amountNet * 19 / 100),
        periodStart: now(),
        periodEnd: now()->addMonth(),
        daysActive: 30,
        daysRemaining: 17,
        isCouponCovered: false,
        direction: $amountNet < 0 ? 'refund' : 'charge',
    );
}

it('rounds the derived unit price to the nearest cent instead of truncating', function (): void {
    // 3 seats @500 at factor 0.5667 → 850 charged. intdiv() gave 283 (849 rendered).
    $line = prorataLine(850, 3)->toArray();

    expect($line['unit_price_net'])->toBe(283)
        // The total stays authoritative regardless of the display rounding.
        ->and($line['amount_net'])->toBe(850);
});

it('rounds a refund unit price away from zero as well', function (): void {
    $line = prorataLine(-851, 3)->toArray();

    expect($line['unit_price_net'])->toBe(-284)
        ->and($line['amount_net'])->toBe(-851);
});

it('keeps an exact unit price when the total divides evenly', function (): void {
    expect(prorataLine(1500, 3)->toArray()['unit_price_net'])->toBe(500);
});

it('round-trips a serialized line', function (): void {
    $original = prorataLine(850, 3);
    $restored = ProrataLine::fromArray($original->toArray());

    expect($restored->amountNet)->toBe($original->amountNet)
        ->and($restored->quantity)->toBe($original->quantity)
        ->and($restored->vatRate)->toBe($original->vatRate)
        ->and($restored->amountGross)->toBe($original->amountGross)
        ->and($restored->direction)->toBe($original->direction)
        ->and($restored->label)->toBe($original->label);
});

it('derives the missing display and period fields of a partial payload', function (): void {
    // Shape a retry job assembles from a stored pending_refund_retries marker.
    $line = ProrataLine::fromArray([
        'kind' => 'addon',
        'code' => 'extra',
        'amount_net' => -500,
    ]);

    expect($line->label)->toBe('addon')
        ->and($line->quantity)->toBe(1)
        ->and($line->amountNet)->toBe(-500)
        ->and($line->amountGross)->toBe(-500)
        ->and($line->direction)->toBe('refund')
        ->and($line->periodStart)->not->toBeNull();
});

it('rejects a payload without amount_net rather than refunding a guess', function (): void {
    expect(fn () => ProrataLine::fromArray(['kind' => 'addon', 'quantity' => 1]))
        ->toThrow(InvalidArgumentException::class);
});
