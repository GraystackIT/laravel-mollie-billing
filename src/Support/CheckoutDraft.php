<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\Billable;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;

/**
 * A checkout's unfinished input, kept in the session so a reload or a second visit resumes it.
 *
 * What it is NOT: a source of truth for anything that costs or grants. It holds what the customer
 * typed and picked — address fields, plan, interval, addons, seats, the coupon *code* and the step
 * they had reached — and nothing the server works out: no amount, no discount, no VAT rate, no
 * VIES verdict, no billable id. The checkout re-derives all of those on resume, re-validates the
 * steps in order and resumes at the furthest one that still holds.
 *
 * Separation. A draft is filed under a key derived from three things:
 *  - the signed-in user (or "guest") — so a logout followed by another login in the same browser,
 *    or a switch of account, never reads the previous person's draft even when the app keeps the
 *    session across it;
 *  - the billable the checkout was opened for (or "new" for a signup) — so two tenants of one
 *    user keep separate drafts;
 *  - the checkout URL (route name and parameters) — so two tenant-prefixed checkouts do not mix.
 * The session itself is the outer boundary: nothing is shared between browsers.
 *
 * Every read and write sanitises against the allow-list below, so a draft written by an older
 * version, or tampered with by anything that can write the session, still only ever carries
 * plain, bounded input.
 */
final class CheckoutDraft
{
    public const SESSION_KEY = 'billing.checkout_drafts';

    /** Drafts kept per session at most; the least recently changed are dropped first. */
    private const MAX_DRAFTS = 10;

    public static function ttlMinutes(): int
    {
        return max(0, (int) config('mollie-billing.checkout_draft_ttl_minutes', 1440));
    }

    public static function enabled(): bool
    {
        return self::ttlMinutes() > 0;
    }

    /**
     * Who is looking: the signed-in user of the default guard, or "guest". Only ever compared and
     * hashed, never shown.
     */
    public static function viewer(): string
    {
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            $user = null;
        }

        return $user === null ? 'guest' : get_class($user).':'.(string) $user->getAuthIdentifier();
    }

    /** The billable a checkout is for, as "class:key", or "new" for a signup. */
    public static function subject(?Billable $billable): string
    {
        if (! $billable instanceof Model) {
            return 'new';
        }

        return $billable->getMorphClass().':'.(string) $billable->getKey();
    }

    /**
     * The checkout URL as a stable string: route name plus its parameters. Bound models count by
     * their route key, anything not scalar is left out.
     */
    public static function context(?Route $route): string
    {
        if ($route === null) {
            return '';
        }

        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof UrlRoutable) {
                $value = $value->getRouteKey();
            }

            if (is_scalar($value)) {
                $parameters[(string) $name] = (string) $value;
            }
        }

        ksort($parameters);

        return (string) $route->getName().'|'.json_encode($parameters);
    }

    public static function key(string $viewer, string $subject, string $context): string
    {
        return hash('sha256', $viewer."\n".$subject."\n".$context);
    }

    public static function viewerFingerprint(string $viewer): string
    {
        return hash('sha256', 'viewer:'.$viewer);
    }

    /**
     * The draft filed under `$key`, sanitised, or null when there is none or it has expired.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        $drafts = self::prune(self::all());
        self::store($drafts);

        $entry = $drafts[$key] ?? null;

        if (! is_array($entry) || ! is_array($entry['data'] ?? null)) {
            return null;
        }

        return self::sanitize($entry['data']);
    }

    /**
     * The billable a checkout under this draft created, as recorded by {@see linkCreated()}.
     *
     * @return array{class:string,id:string}|null
     */
    public static function createdBillable(string $key): ?array
    {
        $created = self::all()[$key]['created'] ?? null;

        if (! is_array($created) || ! is_string($created['class'] ?? null) || ! is_scalar($created['id'] ?? null)) {
            return null;
        }

        return ['class' => $created['class'], 'id' => (string) $created['id']];
    }

    /**
     * File the draft under `$key`, refreshing its expiry.
     *
     * `$subject` and `$created` (the billable a signup checkout created, once it has) are kept so
     * {@see forgetFor()} finds the draft when that billable's checkout is done, and so a resume
     * can tell when the created billable has been deleted since. `$viewer` is the
     * {@see viewerFingerprint()} of who wrote it, for {@see adoptCreated()}.
     *
     * @param  array<string, mixed>  $data
     */
    public static function write(string $key, array $data, string $subject, string $viewer, ?Billable $created = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $drafts = self::prune(self::all());
        $previous = is_array($drafts[$key] ?? null) ? $drafts[$key] : [];
        unset($drafts[$key]);

        $drafts[$key] = [
            'data' => self::sanitize($data),
            'subject' => $subject,
            'viewer' => $viewer,
            'created' => $created instanceof Model
                ? ['class' => $created->getMorphClass(), 'id' => (string) $created->getKey()]
                : ($previous['created'] ?? null),
            'expires_at' => BillingTime::nowUtc()->addMinutes(self::ttlMinutes())->getTimestamp(),
        ];

        // Newest last; keep the most recent few.
        if (count($drafts) > self::MAX_DRAFTS) {
            $drafts = array_slice($drafts, -self::MAX_DRAFTS, null, true);
        }

        self::store($drafts);
    }

    /**
     * Move a signup draft to the billable it created.
     *
     * A signup's draft is filed as "new". When the customer leaves at Mollie and comes back, the
     * checkout is opened for the billable that signup created — a different key. The draft whose
     * checkout created exactly `$subject`, written by the same signed-in user in this session, is
     * re-filed under `$key` and returned. The caller has authorized `$subject`; nothing here
     * grants access to anything.
     *
     * @return array<string, mixed>|null
     */
    public static function adoptCreated(string $viewer, string $subject, string $key): ?array
    {
        $drafts = self::prune(self::all());

        foreach ($drafts as $candidate => $entry) {
            $created = is_array($entry['created'] ?? null)
                ? ($entry['created']['class'] ?? '').':'.($entry['created']['id'] ?? '')
                : null;

            if ($created !== $subject || ! hash_equals((string) ($entry['viewer'] ?? ''), $viewer) || ! is_array($entry['data'] ?? null)) {
                continue;
            }

            unset($drafts[$candidate]);
            $entry['subject'] = $subject;
            $drafts[$key] = $entry;
            self::store($drafts);

            return self::sanitize($entry['data']);
        }

        return null;
    }

    public static function forget(string $key): void
    {
        $drafts = self::all();

        if (array_key_exists($key, $drafts)) {
            unset($drafts[$key]);
            self::store($drafts);
        }
    }

    /** Drop every draft of this session that was for, or created, this billable. */
    public static function forgetFor(Billable $billable): void
    {
        $subject = self::subject($billable);
        $drafts = self::all();
        $kept = [];

        foreach ($drafts as $key => $entry) {
            $created = is_array($entry['created'] ?? null)
                ? ($entry['created']['class'] ?? '').':'.($entry['created']['id'] ?? '')
                : null;

            if (($entry['subject'] ?? null) === $subject || $created === $subject) {
                continue;
            }

            $kept[$key] = $entry;
        }

        if (count($kept) !== count($drafts)) {
            self::store($kept);
        }
    }

    /**
     * Only the inputs a draft may carry, each bounded and typed. Anything else is dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $string = static function (mixed $value, int $max): ?string {
            if (! is_scalar($value)) {
                return null;
            }

            return mb_substr(trim((string) $value), 0, $max);
        };

        $out = [];

        if (isset($data['step']) && is_numeric($data['step'])) {
            $out['step'] = max(1, min(50, (int) $data['step']));
        }

        if (isset($data['custom_steps']) && is_numeric($data['custom_steps'])) {
            $out['custom_steps'] = max(0, min(45, (int) $data['custom_steps']));
        }

        foreach (['company_name' => 255, 'billing_street' => 255, 'billing_postal_code' => 20, 'billing_city' => 255, 'billing_country' => 8, 'vat_number' => 50, 'plan_code' => 100, 'coupon_code' => 100] as $field => $max) {
            if (array_key_exists($field, $data) && ($value = $string($data[$field], $max)) !== null) {
                $out[$field] = $value;
            }
        }

        if (in_array($data['interval'] ?? null, ['monthly', 'yearly'], true)) {
            $out['interval'] = $data['interval'];
        }

        if (is_array($data['addon_codes'] ?? null)) {
            $out['addon_codes'] = array_values(array_unique(array_filter(
                array_map(fn ($code) => $string($code, 100), array_slice(array_values($data['addon_codes']), 0, 50)),
                fn (?string $code): bool => $code !== null && $code !== '',
            )));
        }

        if (isset($data['extra_seats']) && is_numeric($data['extra_seats'])) {
            $out['extra_seats'] = max(0, min(100, (int) $data['extra_seats']));
        }

        $allowedCustom = array_values(array_filter((array) config('mollie-billing.checkout_draft_custom_data', []), 'is_string'));

        if ($allowedCustom !== [] && is_array($data['custom_data'] ?? null)) {
            $custom = [];

            foreach ($allowedCustom as $customKey) {
                if (! array_key_exists($customKey, $data['custom_data'])) {
                    continue;
                }

                $value = $data['custom_data'][$customKey];

                if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                    $custom[$customKey] = $value;
                } elseif (is_string($value)) {
                    $custom[$customKey] = mb_substr($value, 0, 1000);
                }
            }

            $out['custom_data'] = $custom;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function all(): array
    {
        try {
            $drafts = session(self::SESSION_KEY, []);
        } catch (\Throwable) {
            return [];
        }

        return is_array($drafts) ? array_filter($drafts, 'is_array') : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $drafts
     * @return array<string, array<string, mixed>>
     */
    private static function prune(array $drafts): array
    {
        $now = BillingTime::nowUtc()->getTimestamp();

        return array_filter($drafts, fn (array $entry): bool => (int) ($entry['expires_at'] ?? 0) > $now);
    }

    /** @param  array<string, array<string, mixed>>  $drafts */
    private static function store(array $drafts): void
    {
        try {
            if ($drafts === []) {
                session()->forget(self::SESSION_KEY);
            } else {
                session([self::SESSION_KEY => $drafts]);
            }
        } catch (\Throwable) {
            // No session (console, a stateless request): there is nothing to keep a draft in.
        }
    }
}
