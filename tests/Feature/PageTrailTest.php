<?php

/**
 * The Back button / trail above every page: nothing on Home, a fallback to the
 * list for a record page, a Home > Group > Page trail for plain pages.
 */

use App\Filament\Pages\Dashboard;
use App\Support\PageTrail;
use Filament\Facades\Filament;
use Webkul\Employee\Filament\Pages\ReportingLines;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;

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
