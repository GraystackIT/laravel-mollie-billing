<?php

use GraystackIT\MollieBilling\Enums\AuditCategory;
use GraystackIT\MollieBilling\Support\BillingAuditEntry;
use GraystackIT\MollieBilling\Support\BillingTime;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public mixed $billableId = null;
    public string $category = '';

    public function mount(mixed $billableId = null): void
    {
        $this->billableId = $billableId;
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function billable(): mixed
    {
        $class = config('mollie-billing.billable_model');

        return $class ? $class::find($this->billableId) : null;
    }

    public function with(): array
    {
        $billable = $this->billable();

        if (! $billable) {
            return ['activities' => null, 'categories' => AuditCategory::cases()];
        }

        $query = $billable->billingAuditTrail();

        if ($this->category !== '') {
            // `category` lives inside the JSON properties column; a whereJsonContains
            // on the scalar keeps this portable across MySQL/Postgres/SQLite.
            $query->where('properties->category', $this->category);
        }

        return [
            'activities' => $query->paginate(20),
            'categories' => AuditCategory::cases(),
        ];
    }
};

?>

<div class="space-y-4">
    <x-mollie-billing::admin.section
        :title="__('billing::audit.tab_title')"
        :description="__('billing::audit.tab_description')"
    >
        <x-slot:actions>
            <flux:select wire:model.live="category" size="sm" class="min-w-44">
                <flux:select.option value="">{{ __('billing::audit.all_categories') }}</flux:select.option>
                @foreach ($categories as $auditCategory)
                    <flux:select.option value="{{ $auditCategory->value }}">{{ $auditCategory->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>

        @if (! $activities || $activities->isEmpty())
            <x-mollie-billing::admin.empty
                icon="clock"
                :title="__('billing::audit.empty_title')"
                :description="__('billing::audit.empty_description')"
            />
        @else
            <ol class="relative space-y-0">
                @foreach ($activities as $activity)
                    @php
                        $entry = new BillingAuditEntry($activity);
                        $meta = array_filter($entry->meta(), fn ($v) => $v !== null && $v !== '');
                        $occurredAt = $entry->occurredAt();
                    @endphp

                    <li class="relative flex gap-4 pb-6 last:pb-0">
                        {{-- Connector line, hidden on the final entry. --}}
                        @unless ($loop->last)
                            <span aria-hidden="true" class="absolute left-4 top-9 -ml-px h-full w-px bg-zinc-200 dark:bg-zinc-700"></span>
                        @endunless

                        <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 ring-4 ring-white dark:bg-zinc-800 dark:ring-zinc-900">
                            <flux:icon :name="$entry->icon()" variant="micro" class="size-4 text-zinc-500 dark:text-zinc-400" />
                        </span>

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $entry->title() }}</flux:text>
                                @if ($entry->category())
                                    <flux:badge size="sm" :color="$entry->color()">{{ $entry->category()->label() }}</flux:badge>
                                @endif
                            </div>

                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                @if ($occurredAt)
                                    <span class="tabular-nums">{{ BillingTime::displayUtc($occurredAt)->format('Y-m-d H:i') }}</span>
                                    ·
                                @endif
                                {{ $entry->causerLabel() }}
                            </flux:text>

                            @if ($meta !== [])
                                <flux:accordion class="pt-1">
                                    <flux:accordion.item>
                                        <flux:accordion.heading class="text-xs!">
                                            {{ __('billing::audit.details') }}
                                        </flux:accordion.heading>
                                        <flux:accordion.content>
                                            <dl class="grid gap-x-6 gap-y-1 pb-2 pt-1 text-xs sm:grid-cols-2">
                                                @foreach ($meta as $key => $value)
                                                    <div class="flex gap-2">
                                                        <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $key }}</dt>
                                                        <dd class="min-w-0 break-all font-mono text-zinc-700 dark:text-zinc-300">
                                                            @if (str_ends_with((string) $key, '_cents'))
                                                                <x-mollie-billing::admin.money :cents="(int) $value" />
                                                            @elseif (is_bool($value))
                                                                {{ $value ? 'true' : 'false' }}
                                                            @else
                                                                {{ $value }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </flux:accordion.content>
                                    </flux:accordion.item>
                                </flux:accordion>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            <div>{{ $activities->links() }}</div>
        @endif
    </x-mollie-billing::admin.section>
</div>
