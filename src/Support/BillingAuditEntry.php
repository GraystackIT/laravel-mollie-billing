<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

use GraystackIT\MollieBilling\Contracts\SubscriptionCatalogInterface;
use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Enums\SubscriptionInterval;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Presents one stored audit row as human-readable text.
 *
 * The row holds a translation key plus raw values; everything readable is
 * produced here, at render time. That is the point of the design: a plan renamed
 * in the catalog, a new locale, or a changed number format all apply
 * retroactively to the whole history.
 */
final class BillingAuditEntry
{
    /** Codes that should be shown as catalog names rather than raw identifiers. */
    private const PLAN_PLACEHOLDERS = ['plan', 'old_plan', 'new_plan'];

    private const ADDON_PLACEHOLDERS = ['addon'];

    private const USAGE_PLACEHOLDERS = ['usage_type'];

    private const INTERVAL_PLACEHOLDERS = ['interval', 'old_interval', 'new_interval'];

    public function __construct(private readonly Model $activity)
    {
    }

    public function id(): int|string
    {
        return $this->activity->getKey();
    }

    public function title(): string
    {
        $key = 'billing::'.((string) $this->activity->description);
        $rendered = trans($key, $this->resolvedReplacements());

        // trans() echoes the key back when it is missing — most likely because the
        // app published an older lang file. Show something readable instead.
        if (! is_string($rendered) || $rendered === $key) {
            return (string) trans('billing::audit.unknown_event');
        }

        return $rendered;
    }

    public function category(): ?AuditCategory
    {
        $value = $this->property('category');

        return is_string($value) ? AuditCategory::tryFrom($value) : null;
    }

    public function icon(): string
    {
        return $this->category()?->icon() ?? 'information-circle';
    }

    public function color(): string
    {
        return $this->category()?->color() ?? 'zinc';
    }

    /** The name of whoever triggered this, or the localised "System" label. */
    public function causerLabel(): string
    {
        $causer = $this->activity->causer;

        if ($causer instanceof Model) {
            foreach (['name', 'full_name', 'email'] as $attribute) {
                $value = $causer->getAttribute($attribute);

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $actor = $this->property('actor');

        return (string) trans('billing::audit.actor.'.(is_string($actor) ? $actor : BillingAuditActor::SYSTEM));
    }

    public function occurredAt(): ?CarbonInterface
    {
        $value = $this->activity->created_at;

        // Apps may run with immutable dates (Date::use(CarbonImmutable::class)),
        // so accept any CarbonInterface rather than the mutable class only.
        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * Raw stored values, for the expandable technical detail block. Kept separate
     * from the rendered title so ids stay greppable in the UI.
     *
     * @return array<string, scalar|null>
     */
    public function meta(): array
    {
        $replace = $this->property('replace');

        return is_array($replace) ? $replace : [];
    }

    /**
     * Turn stored raw values into display values: plan/addon/usage codes become
     * catalog names, interval codes become localised labels. Anything unknown is
     * passed through as-is.
     *
     * @return array<string, scalar|null>
     */
    public function resolvedReplacements(): array
    {
        $values = $this->meta();
        $catalog = app(SubscriptionCatalogInterface::class);

        foreach ($values as $placeholder => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $values[$placeholder] = match (true) {
                in_array($placeholder, self::PLAN_PLACEHOLDERS, true) => $catalog->planName($value) ?? $value,
                in_array($placeholder, self::ADDON_PLACEHOLDERS, true) => $catalog->addonName($value) ?? $value,
                in_array($placeholder, self::USAGE_PLACEHOLDERS, true) => $catalog->usageTypeName($value),
                in_array($placeholder, self::INTERVAL_PLACEHOLDERS, true) => SubscriptionInterval::tryFrom($value)?->label() ?? $value,
                default => $value,
            };
        }

        // A missing optional value would otherwise render as the literal ":reason".
        return array_map(fn (mixed $v): mixed => $v ?? '—', $values);
    }

    private function property(string $key): mixed
    {
        $properties = $this->activity->properties;

        if ($properties instanceof \Illuminate\Support\Collection) {
            return $properties->get($key);
        }

        return is_array($properties) ? ($properties[$key] ?? null) : null;
    }

    /** @return class-string<Model> */
    public static function model(): string
    {
        /** @var class-string<Model> $model */
        $model = config('activitylog.activity_model', \Spatie\Activitylog\Models\Activity::class);

        return $model;
    }
}
