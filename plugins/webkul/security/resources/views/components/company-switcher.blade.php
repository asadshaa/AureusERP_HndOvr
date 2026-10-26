@php
    $user = \Webkul\Security\Services\CurrentCompany::user();
    $activeId = $user ? \Webkul\Security\Services\CurrentCompany::id($user) : null;
    $companies = $user
        ? \Webkul\Support\Models\Company::query()->whereIn('id', \Webkul\Security\Services\CurrentCompany::membershipIds($user))->orderBy('name')->get(['id', 'name'])
        : collect();
    $active = $companies->firstWhere('id', $activeId);
@endphp

@if ($active)
    @if ($companies->count() > 1)
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-building-office-2" outlined>
                    {{ $active->name }}
                </x-filament::button>
            </x-slot>

            <x-filament::dropdown.list>
                @foreach ($companies as $company)
                    <form method="POST" action="{{ route('security.switch-company') }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $company->id }}">
                        <x-filament::dropdown.list.item
                            tag="button"
                            type="submit"
                            :icon="$company->id === $active->id ? 'heroicon-m-check' : 'heroicon-o-building-office'"
                        >
                            {{ $company->name }}
                        </x-filament::dropdown.list.item>
                    </form>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @else
        <span class="text-sm font-medium text-gray-600 dark:text-gray-300 flex items-center gap-1">
            <x-filament::icon icon="heroicon-o-building-office-2" class="h-4 w-4" />
            {{ $active->name }}
        </span>
    @endif
@endif
