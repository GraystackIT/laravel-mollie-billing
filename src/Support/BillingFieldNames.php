<?php

declare(strict_types=1);

namespace GraystackIT\MollieBilling\Support;

/**
 * The names validation messages use for the billing forms' fields.
 *
 * Without them Laravel falls back to the property name, and the customer read
 * "company name muss ausgefüllt werden" under a field labelled "Name auf der Rechnung".
 * The address fields reuse the label keys the forms show, so an app that overrides a label
 * (`lang/vendor/billing/…/checkout.php`) gets the same word in the message.
 */
final class BillingFieldNames
{
    /**
     * For the checkout, the billing-data page and the plan change — the components whose
     * address properties carry these names.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'company_name' => __('billing::checkout.company_name'),
            'billing_street' => __('billing::checkout.street'),
            'billing_postal_code' => __('billing::checkout.postal_code'),
            'billing_city' => __('billing::checkout.city'),
            'billing_country' => __('billing::checkout.country'),
            // The label carries "(optional)", which reads wrong inside a sentence.
            'vat_number' => __('billing::checkout.vat_number_attribute'),
            'plan_code' => __('billing::checkout.plan'),
            'interval' => __('billing::checkout.interval_attribute'),
            'addon_codes' => __('billing::checkout.available_addons'),
            'addon_codes.*' => __('billing::checkout.available_addons'),
            'extra_seats' => __('billing::checkout.extra_seats'),
        ];
    }
}
