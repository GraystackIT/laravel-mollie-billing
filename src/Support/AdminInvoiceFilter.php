<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use Carbon\CarbonImmutable;
use GraystackIT\MollieBilling\Enums\InvoiceKind;
use GraystackIT\MollieBilling\Enums\InvoiceStatus;
use GraystackIT\MollieBilling\Models\BillingInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The filter of the admin's invoice list, shared by the screen and the ZIP export so the
 * archive holds exactly the invoices the operator was looking at.
 *
 * Dates are calendar days in UTC, both ends inclusive, matched against `created_at` — the
 * issue date printed on the document. UTC because the whole admin panel works in UTC and
 * says so on every time it shows ({@see BillingTime::formatUtc()}): "last month" contains
 * exactly what the list prints as last month. An invoice of 31 October 23:30 UTC is
 * October here, whatever the operator's wall clock said.
 */
final class AdminInvoiceFilter
{
    public const PRESETS = ['this_month', 'last_month', 'this_year', 'last_year'];

    public readonly ?string $from;

    public readonly ?string $to;

    public readonly string $search;

    public readonly ?InvoiceKind $kind;

    public readonly ?InvoiceStatus $status;

    private const TIMEZONE = 'UTC';

    public function __construct(
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
        ?string $kind = null,
        ?string $status = null,
    ) {
        $from = $this->parseDay($from);
        $to = $this->parseDay($to);

        // A range entered backwards means the same days, not none of them.
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $this->from = $from?->toDateString();
        $this->to = $to?->toDateString();
        $this->search = trim((string) $search);
        $this->kind = InvoiceKind::tryFrom((string) $kind);
        $this->status = InvoiceStatus::tryFrom((string) $status);
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $string = static fn (string $key): ?string => is_string($input[$key] ?? null) ? $input[$key] : null;

        return new self($string('from'), $string('to'), $string('search'), $string('kind'), $string('status'));
    }

    /**
     * The first and last day of a quick filter, or null for an unknown name.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function presetRange(string $preset, ?CarbonImmutable $now = null): ?array
    {
        $now = ($now ?? BillingTime::nowUtc())->setTimezone(self::TIMEZONE);

        return match ($preset) {
            'this_month' => [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()],
            'last_month' => [
                $now->startOfMonth()->subMonthNoOverflow()->toDateString(),
                $now->startOfMonth()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'this_year' => [$now->startOfYear()->toDateString(), $now->endOfYear()->toDateString()],
            'last_year' => [
                $now->subYearNoOverflow()->startOfYear()->toDateString(),
                $now->subYearNoOverflow()->endOfYear()->toDateString(),
            ],
            default => null,
        };
    }

    /** The quick filter whose range this filter is, or null. */
    public function preset(?CarbonImmutable $now = null): ?string
    {
        foreach (self::PRESETS as $preset) {
            if (self::presetRange($preset, $now) === [$this->from, $this->to]) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Unordered on purpose: the export walks it with `lazyById()`, which pages by id and
     * would be thrown off by an ordering of its own in front. The screen sorts.
     *
     * @return Builder<BillingInvoice>
     */
    public function query(): Builder
    {
        $query = BillingInvoice::query();

        if ($this->from !== null) {
            $query->where('created_at', '>=', $this->utcStartOf($this->from));
        }

        if ($this->to !== null) {
            // Strictly before the next day: a `<= 23:59:59` bound loses a row stored with
            // fractional seconds on drivers that keep them.
            $nextDay = CarbonImmutable::createFromFormat('!Y-m-d', $this->to, self::TIMEZONE)->addDay()->toDateString();
            $query->where('created_at', '<', $this->utcStartOf($nextDay));
        }

        if ($this->kind !== null) {
            $query->where('invoice_kind', $this->kind->value);
        }

        if ($this->status !== null) {
            $query->where('status', $this->status->value);
        }

        if ($this->search !== '') {
            self::applySearch($query, $this->search);
        }

        return $query;
    }

    /**
     * The admin's free-text search over invoices and credit notes — also used by the refunds
     * list, so both find the same documents.
     *
     * - Serial number and Mollie payment id: substring, case-insensitive on every driver,
     *   `%` and `_` taken literally ({@see ContainsSearch}).
     * - Billable id: exact, and only when the term has the shape of the configured key type
     *   — a uuid or bigint column compared with "acme", or with a number past the bigint
     *   range, is a type error on Postgres.
     * - The billable's own `billableSearch` scope (name, email, or whatever the app defines).
     *
     * @param  Builder<BillingInvoice>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $like = ContainsSearch::pattern($search);

        $query->where(function (Builder $w) use ($like, $search): void {
            $w->whereRaw('lower(serial_number) '.ContainsSearch::SQL, [$like])
                ->orWhereRaw('lower(mollie_payment_id) '.ContainsSearch::SQL, [$like]);

            if (self::looksLikeBillableKey($search)) {
                $w->orWhere('billable_id', $search);
            }

            $w->orWhereHasMorph('billable', '*', function (Builder $mq) use ($search): void {
                $mq->billableSearch($search);
            });
        });
    }

    private static function looksLikeBillableKey(string $term): bool
    {
        return match (config('mollie-billing.billable_key_type', 'uuid')) {
            'int' => self::fitsBigint($term),
            'ulid' => Str::isUlid($term),
            default => Str::isUuid($term),
        };
    }

    /** Digits within a signed 64-bit integer — compared as strings, never cast. */
    private static function fitsBigint(string $term): bool
    {
        if (preg_match('/^\d+\z/', $term) !== 1) {
            return false;
        }

        $digits = ltrim($term, '0');
        if ($digits === '') {
            return true;
        }

        $max = (string) PHP_INT_MAX;

        return strlen($digits) < strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0);
    }

    /**
     * The filter as query-string parameters, empty ones left out.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'kind' => $this->kind?->value,
            'status' => $this->status?->value,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /** A name for the archive that says which invoices are in it. */
    public function archiveName(): string
    {
        $range = match (true) {
            $this->from !== null && $this->to !== null => $this->from.'_'.$this->to,
            $this->from !== null => 'from-'.$this->from,
            $this->to !== null => 'until-'.$this->to,
            default => 'all',
        };

        return 'invoices-'.$range.'.zip';
    }

    /** `Y-m-d H:i:s` of the start of a UTC day — what `created_at` is compared with. */
    private function utcStartOf(string $day): string
    {
        return $day.' 00:00:00';
    }

    private function parseDay(?string $value): ?CarbonImmutable
    {
        // `\z`, not `$`: `$` also matches before a trailing newline, and "2026-11-24\n" from
        // a crafted URL then reached createFromFormat, which throws on trailing data.
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat rolls "2026-02-31" over into March instead of refusing it.
        return $day !== false && $day->toDateString() === $value ? $day : null;
    }
}
