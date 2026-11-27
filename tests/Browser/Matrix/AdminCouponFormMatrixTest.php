<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Tests\Browser\Matrix;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use GraystackIT\MollieBilling\Enums\CouponType;
use GraystackIT\MollieBilling\Enums\DiscountType;
use GraystackIT\MollieBilling\Models\Coupon;
use GraystackIT\MollieBilling\Tests\Browser\BrowserTestCase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The operator's coupon screens, option by option: the form (every intent, every kind,
 * every field), the detail page (shows what was created; deactivate; delete and its
 * refusal), and the list (search, every sort column, the bulk action).
 *
 * Operated the way an operator does: the intent cards, the selects, the checkbox groups
 * and the single checkboxes are clicked on screen (a folded section is unfolded by its
 * heading first), plain inputs are typed into. The one exception are the two
 * `datetime-local` fields: what keyboard entry into them means depends on the browser's
 * locale, so their value is put into the input and announced with the `input` event a
 * typed value raises — it still travels through the field's own `wire:model`. Nothing on
 * this form is set on the component directly.
 */
class AdminCouponFormMatrixTest extends BrowserTestCase
{
    use CouponWorkbench;

    private const FORM = 'form[wire\\:submit="save"]';

    /**
     * One case per kind of coupon the form can create. `set` are Flux controls, `type`
     * plain inputs, `stored` the columns the coupon must then hold, `shown` what the
     * detail page must print.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function kinds(): array
    {
        return [
            'discount / once — fixed amount, every restriction' => [[
                'intent' => 'discount', 'type' => 'single_payment',
                'set' => [
                    'discount_type' => 'fixed',
                    'applicable_plans' => ['starter'], 'applicable_intervals' => ['monthly'],
                    'applicable_addons' => ['softdrinks'], 'applicable_products' => ['token-pack-500'],
                ],
                'type_into' => ['discount_value' => '500', 'minimum_order_amount_net' => '1000'],
                'stored' => [
                    'type' => CouponType::SinglePayment, 'discount_type' => DiscountType::Fixed, 'discount_value' => 500,
                    'minimum_order_amount_net' => 1000, 'applicable_plans' => ['starter'], 'applicable_intervals' => ['monthly'],
                    'applicable_addons' => ['softdrinks'], 'applicable_products' => ['token-pack-500'], 'discount_periods' => null,
                ],
                'shown' => ['starter', 'monthly', 'softdrinks', 'token-pack-500'],
            ]],
            'discount / on every renewal — percentage, for six periods' => [[
                'intent' => 'discount', 'type' => 'recurring',
                'set' => ['discount_type' => 'percentage'],
                'type_into' => ['discount_value' => '25', 'discount_periods' => '6'],
                'stored' => [
                    'type' => CouponType::Recurring, 'discount_type' => DiscountType::Percentage, 'discount_value' => 25,
                    'discount_periods' => 6, 'applicable_plans' => null,
                ],
                'shown' => ['25%'],
            ]],
            'free time / extend the trial' => [[
                'intent' => 'free_time', 'type' => 'trial_extension',
                'set' => [],
                'type_into' => ['trial_extension_days' => '7'],
                'stored' => ['type' => CouponType::TrialExtension, 'trial_extension_days' => 7],
                'shown' => ['7 '],
            ]],
            'free time / everything free for days' => [[
                'intent' => 'free_time', 'type' => 'period_extension',
                'set' => [],
                'type_into' => ['grant_duration_days' => '14'],
                'stored' => ['type' => CouponType::PeriodExtension, 'grant_duration_days' => 14],
                'shown' => ['14 '],
            ]],
            'free time / plan free for periods' => [[
                'intent' => 'free_time', 'type' => 'plan_waiver',
                'set' => [],
                'type_into' => ['waiver_periods' => '3'],
                'stored' => ['type' => CouponType::PlanWaiver, 'waiver_periods' => 3],
                'shown' => ['3 '],
            ]],
            'free time / give the product away — plan, addon, continues as paid' => [[
                'intent' => 'free_time', 'type' => 'access_grant',
                'set' => [
                    'grant_plan_code' => 'pro', 'grant_interval' => 'monthly',
                    'grant_on_expiry' => 'continue_paid', 'grant_addon_codes' => ['softdrinks'],
                ],
                'type_into' => ['grant_duration_days' => '90'],
                'stored' => [
                    'type' => CouponType::AccessGrant, 'grant_plan_code' => 'pro', 'grant_interval' => 'monthly',
                    'grant_duration_days' => 90, 'grant_on_expiry' => 'continue_paid', 'grant_addon_codes' => ['softdrinks'],
                ],
                'shown' => ['pro', '90 ', 'softdrinks'],
            ]],
            'advanced / addon-only grant' => [[
                'intent' => 'free_time', 'type' => 'access_grant',
                'set' => ['addonOnlyGrant' => true, 'grant_addon_codes' => ['softdrinks']],
                'type_into' => [],
                'stored' => [
                    'type' => CouponType::AccessGrant, 'grant_plan_code' => null, 'grant_interval' => null,
                    'grant_duration_days' => null, 'grant_addon_codes' => ['softdrinks'],
                ],
                'shown' => ['softdrinks'],
            ]],
            'credits' => [[
                'intent' => 'credits', 'type' => 'credits',
                'set' => [],
                'type_into' => ['credits_payload.Tokens' => '250'],
                'stored' => ['type' => CouponType::Credits, 'credits_payload' => ['Tokens' => 250]],
                'shown' => ['Tokens', '250'],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('kinds')]
    public function test_the_form_creates_this_kind_and_the_coupon_page_shows_it(array $case): void
    {
        $code = 'MX'.strtoupper(substr(md5(json_encode($case['stored']['type']).json_encode($case['set'])), 0, 6));

        $this->browse(function (Browser $browser) use ($case, $code): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

            // The intent first: it decides which kinds are on offer.
            $this->choose($browser, 'intent', $case['intent']);
            $browser->assertSee(__('billing::admin.coupons.intent_info.'.$case['intent']));

            if ($case['intent'] !== 'credits') {
                $this->waitForPresence($browser, 'select[wire\\:model\\.live="type"] option[value="'.$case['type'].'"]');
                $this->choose($browser, 'type', $case['type']);
            }

            // Then the fields this kind has, and the ones every coupon has.
            foreach ($case['set'] as $model => $value) {
                $this->choose($browser, $model, $value);
            }

            $this->choose($browser, 'stackable', true);
            $browser->type('textarea[wire\\:model="description"]', 'Created by the browser matrix');
            $this->enterDateTime($browser, 'valid_from', '2026-01-01T00:00');
            $this->enterDateTime($browser, 'valid_until', '2031-12-31T23:59');
            $this->unfoldSectionOf($browser, 'input[wire\\:model="auto_apply_token"]');
            $browser->type('input[wire\\:model="auto_apply_token"]', strtolower($code).'-link');

            $browser->assertSee(__('billing::admin.coupons.type_info.'.$case['type']));
            expect($browser->driver->getPageSource())->not->toContain('<flux:');

            $browser->type('input[wire\\:model="code"]', strtolower($code))
                ->type('input[wire\\:model="name"]', 'Matrix '.$code)
                ->type('input[wire\\:model="max_redemptions"]', '7')
                ->type('input[wire\\:model="max_redemptions_per_billable"]', '2');

            foreach ($case['type_into'] as $model => $value) {
                $browser->waitFor('input[wire\\:model="'.$model.'"]', 10)
                    ->type('input[wire\\:model="'.$model.'"]', $value);
            }

            $browser->pause(300);
            $this->shot($browser, 'Formular ausgefüllt');

            $this->clickWire($browser, self::FORM.' button[type="submit"]');
            $browser->waitForText(__('billing::admin.coupons.create.created', ['code' => $code]), 15)->pause(400);
        });

        $coupon = Coupon::query()->where('code', $code)->sole();

        // Every field, as typed in.
        expect($coupon->name)->toBe('Matrix '.$code)
            ->and($coupon->description)->toBe('Created by the browser matrix')
            ->and($coupon->valid_from->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and($coupon->valid_until->toIso8601String())->toBe('2031-12-31T23:59:00+00:00')
            ->and((int) $coupon->max_redemptions)->toBe(7)
            ->and($coupon->max_redemptions_per_billable)->toBe(2)
            ->and($coupon->stackable)->toBeTrue()
            ->and($coupon->active)->toBeTrue()
            ->and($coupon->auto_apply_token)->toBe(strtolower($code).'-link');

        foreach ($case['stored'] as $column => $expected) {
            expect($coupon->{$column})->toBe($expected, "coupons.{$column}");
        }

        $this->browse(function (Browser $browser) use ($coupon, $case): void {
            $browser->visit('/billing/admin/coupons/'.$coupon->id)
                ->waitForText($coupon->code, 15)->pause(400);

            $browser->assertSee('Matrix '.$coupon->code)
                ->assertSee('Created by the browser matrix')
                ->assertSee('2026-01-01 00:00')
                ->assertSee('2031-12-31 23:59')
                ->assertSee('0 / 7')
                ->assertSee($coupon->auto_apply_token);

            foreach ($case['shown'] as $text) {
                $browser->assertSee($text);
            }

            $this->shot($browser, 'Coupon-Detailseite');

            // Deactivate, then delete: the coupon has never been redeemed.
            $this->clickWire($browser, '[wire\\:click="deactivate"]');
            $browser->acceptDialog();
            $browser->waitForText(__('billing::admin.coupons.show.deactivated'), 15)->pause(300);
        });

        expect($coupon->fresh()->active)->toBeFalse();

        $this->browse(function (Browser $browser) use ($coupon): void {
            expect($this->domCount($browser, '[wire\\:click="deactivate"]'))->toBe(0);

            $this->clickWire($browser, '[wire\\:click="delete"]');
            $browser->acceptDialog();
            $browser->waitForText(__('billing::admin.coupons.show.deleted'), 15);
        });

        expect(Coupon::query()->whereKey($coupon->id)->exists())->toBeFalse();
    }

    public function test_an_inactive_coupon_can_be_created_and_is_refused_at_once(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

            $this->choose($browser, 'intent', 'credits');
            $this->choose($browser, 'active', false);
            $browser->type('input[wire\\:model="code"]', 'SLEEPER')
                ->type('input[wire\\:model="credits_payload.Tokens"]', '10')->pause(200);
            $this->clickWire($browser, self::FORM.' button[type="submit"]');
            $browser->waitForText(__('billing::admin.coupons.create.created', ['code' => 'SLEEPER']), 15);
        });

        expect(Coupon::query()->where('code', 'SLEEPER')->sole()->active)->toBeFalse();

        $billable = $this->couponBillableIn('active');

        $this->browse(function (Browser $browser) use ($billable): void {
            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'SLEEPER');
            $browser->waitForText(__('billing::checkout.coupon_inactive'), 15);
        });
    }

    /**
     * Every message the form can answer with. (Fields marked `required` or bounded by
     * `min`/`max` are stopped by the browser itself before the form is sent; what is
     * listed here is what reaches the server.)
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: string}>
     */
    public static function refusals(): array
    {
        return [
            'recurring without periods and without a date' => [
                ['intent' => 'discount', 'type' => 'recurring'],
                ['discount_value' => '20'],
                'Recurring coupons need a limit',
            ],
            'credits without any units' => [
                ['intent' => 'credits'],
                [],
                'Credits coupons require a credits payload.',
            ],
            'a gift without a plan' => [
                ['intent' => 'free_time', 'type' => 'access_grant'],
                [],
                'Please pick a plan with interval and duration',
            ],
            'a gift with a plan but no duration' => [
                ['intent' => 'free_time', 'type' => 'access_grant', 'grant_plan_code' => 'pro', 'grant_interval' => 'monthly'],
                [],
                'Please pick a plan, an interval and enter a duration in days.',
            ],
            'an addon-only grant without addons' => [
                ['addonOnlyGrant' => true],
                [],
                'Please pick a plan with interval and duration',
            ],
            'a code that already exists' => [
                ['intent' => 'credits'],
                ['credits_payload.Tokens' => '5', 'code' => 'TAKEN'],
                'A coupon with the code TAKEN already exists.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  array<string, string>  $typeInto
     */
    #[DataProvider('refusals')]
    public function test_the_form_says_why_a_coupon_cannot_be_created(array $set, array $typeInto, string $message): void
    {
        $this->coupons()->creditsCoupon('TAKEN', ['Tokens' => 1]);
        $before = Coupon::query()->count();

        $this->browse(function (Browser $browser) use ($set, $typeInto, $message): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

            foreach ($set as $model => $value) {
                $this->choose($browser, $model, $value);
            }

            $browser->type('input[wire\\:model="code"]', $typeInto['code'] ?? 'REFUSED');

            foreach ($typeInto as $model => $value) {
                if ($model !== 'code') {
                    $browser->type('input[wire\\:model="'.$model.'"]', $value);
                }
            }

            $browser->pause(200);
            $this->clickWire($browser, self::FORM.' button[type="submit"]');

            $browser->waitForText(__('billing::admin.coupons.create.error_heading'), 15)
                ->assertSee($message);
            $this->shot($browser, 'Abgelehnt: '.$message);
        });

        expect(Coupon::query()->count())->toBe($before);
    }

    /**
     * The service's refusal reaches a German operator in German. It used to arrive as the
     * exception's English message ("A coupon with the code TAKEN already exists.") on an
     * otherwise German form; the message now stays English for the log only.
     */
    public function test_the_form_says_why_in_the_operators_language(): void
    {
        \Workbench\App\Support\WorkbenchSetting::set('config.app.locale', json_encode('de'));
        config(['app.locale' => 'de']);
        app()->setLocale('de');

        $this->coupons()->creditsCoupon('TAKEN', ['Tokens' => 1]);
        $before = Coupon::query()->count();

        $this->browse(function (Browser $browser): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

            $this->choose($browser, 'intent', 'credits');
            $browser->type('input[wire\\:model="code"]', 'TAKEN')
                ->type('input[wire\\:model="credits_payload.Tokens"]', '5')
                ->pause(200);
            $this->clickWire($browser, self::FORM.' button[type="submit"]');

            $browser->waitForText(__('billing::admin.coupons.create.error_heading'), 15)
                ->assertSee('Ein Coupon mit dem Code TAKEN existiert bereits.')
                ->assertDontSee('already exists');
            $this->shot($browser, 'Abgelehnt auf Deutsch');
        });

        expect(Coupon::query()->count())->toBe($before);
    }

    /** @return array<string, array{0: string}> */
    public static function typesForTooltips(): array
    {
        $types = [];

        foreach (CouponType::cases() as $type) {
            $types[$type->value] = [$type->value];
        }

        return $types;
    }

    /**
     * Every (i) on the form, for every kind: it opens, it carries a sentence, and the
     * sentence wraps instead of running off the screen.
     */
    #[DataProvider('typesForTooltips')]
    public function test_every_tooltip_of_the_form_opens_and_wraps(string $type): void
    {
        $this->browse(function (Browser $browser) use ($type): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(500);

            $intent = match (true) {
                in_array($type, ['single_payment', 'recurring'], true) => 'discount',
                $type === 'credits' => 'credits',
                default => 'free_time',
            };
            $this->choose($browser, 'intent', $intent);

            if ($intent !== 'credits') {
                $this->waitForPresence($browser, 'select[wire\\:model\\.live="type"] option[value="'.$type.'"]');
                $this->choose($browser, 'type', $type);
            }

            // Sections that are folded away hold options too.
            $browser->script('document.querySelectorAll("ui-disclosure").forEach(d => { if (! d.hasAttribute("open")) { const b = d.querySelector("button"); if (b) { b.click(); } } });');
            $browser->pause(600);

            $buttons = $browser->driver->findElements(WebDriverBy::cssSelector(self::FORM.' [data-flux-tooltip] button'));
            $opened = 0;

            expect(count($buttons))->toBeGreaterThan(9, "tooltips on the {$type} form");

            foreach ($buttons as $index => $button) {
                expect($button->isDisplayed())->toBeTrue("tooltip #{$index} of the {$type} form is hidden");

                $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$button]);
                $button->click();
                $browser->pause(250);

                [$width, $length] = $browser->script(
                    'const t = document.querySelector("[data-flux-tooltip-content]:popover-open"); return t ? [Math.round(t.getBoundingClientRect().width), t.innerText.trim().length] : [-1, 0];'
                )[0];

                expect($width)->toBeGreaterThan(0, "tooltip #{$index} of the {$type} form did not open")
                    ->and($width)->toBeLessThanOrEqual(360, "tooltip #{$index} of the {$type} form does not wrap")
                    ->and($length)->toBeGreaterThan(20, "tooltip #{$index} of the {$type} form explains nothing");

                $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
                $browser->pause(120);
                $opened++;
            }

            expect($opened)->toBe(count($buttons));
        });
    }

    public function test_a_redeemed_coupon_cannot_be_deleted_only_deactivated(): void
    {
        $coupon = $this->coupons()->creditsCoupon('USEDONCE', ['Tokens' => 10]);
        $this->coupons()->redeem($coupon, $this->couponBillableIn('active'), []);

        $this->browse(function (Browser $browser) use ($coupon): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/'.$coupon->id)->waitForText('USEDONCE', 15)->pause(400);

            // The redemption is listed.
            $browser->assertDontSee(__('billing::admin.coupons.show.history_empty_title'));

            $this->clickWire($browser, '[wire\\:click="delete"]');
            $browser->acceptDialog();
            $browser->waitForText(__('billing::admin.coupons.show.delete_failed'), 15);
            $this->shot($browser, 'Eingelöster Coupon: Löschen verweigert');

            $this->clickWire($browser, '[wire\\:click="deactivate"]');
            $browser->acceptDialog();
            $browser->waitForText(__('billing::admin.coupons.show.deactivated'), 15);
        });

        expect($coupon->fresh())->not->toBeNull()
            ->and($coupon->fresh()->active)->toBeFalse();
    }

    public function test_the_list_searches_sorts_by_every_column_and_deactivates_in_bulk(): void
    {
        $coupons = $this->coupons();
        $alpha = $coupons->create(['code' => 'ALPHA', 'name' => 'Zulu campaign', 'type' => CouponType::Credits, 'credits_payload' => ['Tokens' => 1], 'valid_until' => '2030-03-01 00:00:00']);
        $bravo = $coupons->create(['code' => 'BRAVO', 'name' => 'Yankee campaign', 'type' => CouponType::PeriodExtension, 'grant_duration_days' => 5, 'valid_until' => '2030-01-01 00:00:00', 'active' => false]);
        $charlie = $coupons->create(['code' => 'CHARLIE', 'name' => 'X-ray campaign', 'type' => CouponType::TrialExtension, 'trial_extension_days' => 5, 'valid_until' => '2030-02-01 00:00:00']);
        $coupons->redeem($charlie, $this->couponBillableIn('trial'), []);

        $order = function (Browser $browser): array {
            $text = (string) $browser->script('return document.querySelector("table") ? document.querySelector("table").innerText : "";')[0];
            $positions = [];

            foreach (['ALPHA', 'BRAVO', 'CHARLIE'] as $code) {
                $at = strpos($text, $code);

                if ($at !== false) {
                    $positions[$code] = $at;
                }
            }

            asort($positions);

            return array_keys($positions);
        };

        $this->browse(function (Browser $browser) use ($order, $alpha, $bravo): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons')->waitForText('ALPHA', 15)->pause(400);

            // Every sortable column, ascending and then descending, against the database.
            foreach (['code', 'name', 'type', 'redemptions_count', 'valid_until', 'active'] as $column) {
                foreach (['asc', 'desc'] as $direction) {
                    $expected = Coupon::query()->orderBy($column, $direction)->pluck('code')->all();
                    $this->clickWire($browser, '[wire\\:click="sort(\''.$column.'\')"]');

                    // Wait for the re-render rather than a fixed pause: a 700 ms pause read
                    // the previous order once on a loaded machine. A wrong order still fails —
                    // the wait times out and the assertion below names what the screen shows.
                    try {
                        $browser->waitUsing(10, 100, fn (): bool => $order($browser) === $expected);
                    } catch (\Facebook\WebDriver\Exception\TimeoutException) {
                        // fall through to the assertion for a readable diff
                    }

                    expect($order($browser))->toBe($expected, "sorted by {$column} {$direction}");
                }
            }
            $this->shot($browser, 'Liste sortiert');

            // Search: by code, and by name.
            $browser->type('input[type="search"]', 'brav')->pause(900);
            expect($order($browser))->toBe(['BRAVO']);

            $browser->type('input[type="search"]', 'x-ray')->pause(900);
            expect($order($browser))->toBe(['CHARLIE']);

            $browser->clear('input[type="search"]')->type('input[type="search"]', ' ')->keys('input[type="search"]', '{backspace}')->pause(900);
            expect(count($order($browser)))->toBe(3);

            // Two ticked, deactivated in one go.
            $this->choose($browser, 'selected', [(string) $alpha->id, (string) $bravo->id]);
            $browser->waitFor('[wire\\:click="deactivateSelected"]', 10);
            $this->clickWire($browser, '[wire\\:click="deactivateSelected"]');
            $browser->pause(900);
            $this->shot($browser, 'Mehrfachaktion: deaktiviert');
        });

        expect($alpha->fresh()->active)->toBeFalse()
            ->and($bravo->fresh()->active)->toBeFalse()
            ->and($charlie->fresh()->active)->toBeTrue();
    }

    /**
     * `applicable_usages` restricts a coupon to metered usage. No charge in the package
     * carries a usage dimension a coupon could attach to, so the form does not offer the
     * field — a coupon that has it (created in code) is shown as restricted and is refused
     * wherever it is typed in.
     */
    public function test_a_usage_restricted_coupon_is_shown_as_such_and_refused(): void
    {
        $coupon = $this->coupons()->create([
            'code' => 'TOKENSONLY',
            'type' => CouponType::Credits,
            'credits_payload' => ['Tokens' => 10],
            'applicable_usages' => ['Tokens'],
        ]);
        $billable = $this->couponBillableIn('active');

        $this->browse(function (Browser $browser) use ($coupon, $billable): void {
            $this->loginAdmin($browser);
            $browser->visit('/billing/admin/coupons/'.$coupon->id)->waitForText('TOKENSONLY', 15)->pause(400);
            // (Detail labels are set in small caps, so the comparison ignores case.)
            expect(mb_strtolower((string) $browser->script('return document.body.innerText;')[0]))
                ->toContain(mb_strtolower(__('billing::admin.coupons.show.applicable_usages')));

            $browser->visit('/billing/admin/coupons/create')
                ->waitForText(__('billing::admin.coupons.create.title'), 15)->pause(400);
            expect($this->domCount($browser, '[wire\\:model="applicable_usages"]'))->toBe(0);

            $this->actAs($browser, $billable, '/billing');
            $this->redeemOnDashboard($browser, 'TOKENSONLY');
            $browser->waitForText(__('billing::checkout.coupon_usage_mismatch'), 15);
        });

        expect(\GraystackIT\MollieBilling\Models\CouponRedemption::query()->count())->toBe(0);
    }

    /**
     * Put a value into a Flux control by clicking what the operator sees.
     *
     * The control is found by the property it is bound to: a card of a radio group, an
     * option of a native select, the boxes of a checkbox group (or of a list whose rows
     * each carry one), or a single checkbox, clicked only when its state has to change. A
     * control inside a folded section is unfolded by a click on the section's heading.
     */
    private function choose(Browser $browser, string $model, mixed $value): void
    {
        $this->waitForPresence($browser, self::boundTo($model));
        $this->unfoldSectionOf($browser, self::boundTo($model));

        $kind = (string) $browser->script(sprintf(
            'const el = document.querySelector(%s); return el.tagName.toLowerCase() === "ui-checkbox" && document.querySelectorAll(%s).length > 1 ? "rows" : el.tagName.toLowerCase();',
            json_encode(self::boundTo($model)),
            json_encode(self::boundTo($model)),
        ))[0];

        match ($kind) {
            'select' => $this->selectOption($browser, $model, (string) $value),
            'ui-radio-group' => $this->clickOn($browser, self::boundTo($model).' ui-radio[value="'.$value.'"]'),
            'ui-checkbox-group' => $this->tick($browser, array_map(
                fn (string $v): string => self::boundTo($model).' ui-checkbox[value="'.$v.'"]',
                (array) $value,
            )),
            'rows' => $this->tick($browser, array_map(
                fn (string $v): string => self::boundTo($model).'[value="'.$v.'"]',
                (array) $value,
            )),
            'ui-checkbox' => $this->setChecked($browser, self::boundTo($model), (bool) $value),
            default => throw new \RuntimeException("No clickable control bound to [{$model}] ({$kind})."),
        };

        $browser->pause(500);
    }

    /** Every element a `wire:model` (with or without modifiers) binds to this property. */
    private static function boundTo(string $model): string
    {
        $selectors = [];

        foreach (['', '.live', '.self', '.live.self'] as $modifier) {
            $selectors[] = '[wire\\:model'.str_replace('.', '\\.', $modifier).'="'.$model.'"]';
        }

        return ':is('.implode(', ', $selectors).')';
    }

    private function selectOption(Browser $browser, string $model, string $value): void
    {
        $this->waitForPresence($browser, 'select'.self::boundTo($model).' option[value="'.$value.'"]');
        $element = $browser->driver->findElement(WebDriverBy::cssSelector('select'.self::boundTo($model)));
        $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$element]);
        (new \Facebook\WebDriver\WebDriverSelect($element))->selectByValue($value);
    }

    /** @param  list<string>  $boxes */
    private function tick(Browser $browser, array $boxes): void
    {
        foreach ($boxes as $box) {
            $this->setChecked($browser, $box, true);
        }
    }

    private function setChecked(Browser $browser, string $css, bool $checked): void
    {
        $this->waitForPresence($browser, $css);
        $isChecked = (bool) $browser->script(sprintf('return document.querySelector(%s).hasAttribute("data-checked");', json_encode($css)))[0];

        if ($isChecked !== $checked) {
            $this->clickOn($browser, $css);
            $browser->waitUsing(10, 100, fn (): bool => (bool) $browser->script(sprintf(
                'const el = document.querySelector(%s); return el !== null && el.hasAttribute("data-checked") === %s;',
                json_encode($css),
                $checked ? 'true' : 'false',
            ))[0], "[{$css}] did not become ".($checked ? 'checked' : 'unchecked').'.');
        }
    }

    /** A real click through WebDriver, on an element scrolled into view. */
    private function clickOn(Browser $browser, string $css): void
    {
        $this->waitForPresence($browser, $css);

        for ($attempt = 1; ; $attempt++) {
            try {
                $element = $browser->driver->findElement(WebDriverBy::cssSelector($css));
                // Centred, and then clear of the sticky page header, which otherwise takes
                // the click for anything near the top of the form.
                $browser->driver->executeScript(
                    'arguments[0].scrollIntoView({block: "center"}); const h = document.querySelector("[data-flux-header]"); const below = h ? h.getBoundingClientRect().bottom + 16 : 0; const top = arguments[0].getBoundingClientRect().top; if (top < below) { window.scrollBy(0, top - below); }',
                    [$element],
                );
                $browser->pause(150);
                $element->click();

                return;
            } catch (\Facebook\WebDriver\Exception\StaleElementReferenceException|\Facebook\WebDriver\Exception\ElementClickInterceptedException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }

                $browser->pause(250);
            }
        }
    }

    /** Unfold the folded section a control sits in, by clicking the section's heading. */
    private function unfoldSectionOf(Browser $browser, string $css): void
    {
        $this->waitForPresence($browser, $css);

        // Folded: inside a section whose content is hidden, so the control has no box.
        $folded = (bool) $browser->script(sprintf(
            'const el = document.querySelector(%s); return el.closest("ui-disclosure") !== null && el.getClientRects().length === 0;',
            json_encode($css),
        ))[0];

        if (! $folded) {
            return;
        }

        $browser->script(sprintf(
            'document.querySelectorAll("[data-matrix-heading]").forEach(h => h.removeAttribute("data-matrix-heading")); document.querySelector(%s).closest("ui-disclosure").querySelector("[data-flux-accordion-heading]").setAttribute("data-matrix-heading", "1");',
            json_encode($css),
        ));
        $this->clickOn($browser, '[data-matrix-heading="1"]');
        $browser->waitUsing(10, 100, fn (): bool => (bool) $browser->script(sprintf(
            'return document.querySelector(%s).getClientRects().length > 0;',
            json_encode($css),
        ))[0], 'The section did not unfold.')->pause(400);
    }

    /**
     * A `datetime-local` value, put into the field and announced with an `input` event —
     * the event a typed value raises, so the field's own `wire:model` carries it.
     */
    private function enterDateTime(Browser $browser, string $model, string $value): void
    {
        $css = 'input[type="datetime-local"]'.self::boundTo($model);
        $this->waitForPresence($browser, $css);
        $browser->script(sprintf(
            'const el = document.querySelector(%s); el.value = %s; el.dispatchEvent(new Event("input", {bubbles: true})); el.dispatchEvent(new Event("change", {bubbles: true}));',
            json_encode($css),
            json_encode($value),
        ));
        $browser->pause(150);
    }
}
