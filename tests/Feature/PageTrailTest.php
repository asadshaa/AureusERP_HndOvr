<?php

/**
 * The Back button / trail above every page: nothing on Home, a fallback to the
 * list for a record page, a Home > Group > Page trail for plain pages.
 */

use App\Filament\Pages\Dashboard;
use App\Support\PageTrail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Pages\ReportingLines;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

it('shows nothing on the home page', function (): void {
    expect(PageTrail::for([Dashboard::class]))->toBeNull();
});

it('falls back to the list from a record page and to home from the list', function (): void {
    $edit = PageTrail::for([EditEmployee::class, EmployeeResource::class]);
    $list = PageTrail::for([ListEmployees::class, EmployeeResource::class]);

    expect($edit['back'])->toBe(EmployeeResource::getUrl('index'))
        ->and($edit['crumbs'])->toBe([])
        ->and($list['back'])->toBe(Filament::getCurrentPanel()->getUrl());
});

it('gives a plain page a Home > group > page trail with Back going home', function (): void {
    $trail = PageTrail::for([ReportingLines::class]);

    expect($trail['back'])->toBe(Filament::getCurrentPanel()->getUrl())
        ->and(collect($trail['crumbs'])->pluck('label')->all())->toBe(['Home', 'Employees', 'Reporting lines'])
        ->and($trail['crumbs'][0]['url'])->not->toBeNull();
});

it('offers the company dropdown on list and report pages to people in more than one company, and not on a record form', function (): void {
    $first = Company::factory()->create(['name' => 'First Co', 'is_active' => true]);
    $second = Company::factory()->create(['name' => 'Second Co', 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $first->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$first->id, $second->id]);
    Auth::login($user);

    $report = PageTrail::for([ReportingLines::class]);
    $list = PageTrail::for([ListEmployees::class, EmployeeResource::class]);
    $form = PageTrail::for([EditEmployee::class, EmployeeResource::class]);

    expect($report['companies'])->toBe([$first->id => 'First Co', $second->id => 'Second Co'])
        ->and($report['active_company'])->toBe($first->id)
        ->and(array_keys($list['companies']))->toBe([$first->id, $second->id])
        ->and($form['companies'])->toBe([]);
});

it('does not offer the dropdown to someone who belongs to one company', function (): void {
    $only = Company::factory()->create(['is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $only->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$only->id]);
    Auth::login($user);

    expect(PageTrail::for([ReportingLines::class])['companies'])->toBe([]);
});

it('returns to the page the company was switched on, and never to an outside address', function (): void {
    $first = Company::factory()->create(['is_active' => true]);
    $second = Company::factory()->create(['is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $first->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$first->id, $second->id]);

    $this->actingAs($user)
        ->post(route('security.switch-company'), ['company_id' => $second->id, 'return_to' => '/admin/employees/reporting-lines'])
        ->assertRedirect(url('/admin/employees/reporting-lines'));
    expect($user->refresh()->default_company_id)->toBe($second->id);

    foreach (['https://evil.example/admin', '//evil.example/admin', '/admin/../etc', '/other'] as $bad) {
        $this->actingAs($user)
            ->post(route('security.switch-company'), ['company_id' => $first->id, 'return_to' => $bad])
            ->assertRedirect(Filament::getPanel('admin')->getUrl());
    }
});
