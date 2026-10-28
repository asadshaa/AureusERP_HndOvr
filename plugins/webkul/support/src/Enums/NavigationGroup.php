<?php

namespace Webkul\Support\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Top navigation groups. Filament orders enum groups by case order, so the
 * cases are listed in the order people use them: home, HR (employees,
 * attendance, time off), recruitment (and its careers website), finance,
 * then the other modules, and settings last.
 */
enum NavigationGroup: string implements HasIcon, HasLabel
{
    case Dashboard = 'dashboard';

    case Employee = 'employee';

    case Attendance = 'attendance';

    case TimeOff = 'time-off';

    case Recruitment = 'recruitment';

    case Website = 'website';

    case Accounting = 'accounting';

    case Invoice = 'invoice';

    case Sale = 'sale';

    case Purchase = 'purchase';

    case Contact = 'contact';

    case Inventory = 'inventory';

    case Manufacturing = 'manufacturing';

    case Maintenance = 'maintenance';

    case Project = 'project';

    case Barcode = 'barcode';

    case Plugin = 'plugin';

    case Setting = 'setting';

    case Help = 'help';

    public function getLabel(): string
    {
        return __('admin.navigation.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Dashboard     => 'icon-dashboard',
            self::Contact       => 'icon-contacts',
            self::Sale          => 'icon-sales',
            self::Purchase      => 'icon-purchases',
            self::Maintenance   => 'icon-maintenance',
            self::Manufacturing => 'icon-manufacturing',
            self::Inventory     => 'icon-inventories',
            self::Invoice       => 'icon-invoices',
            self::Accounting    => 'icon-accounting',
            self::Project       => 'icon-projects',
            self::Employee      => 'icon-employees',
            self::TimeOff       => 'icon-time-offs',
            self::Attendance    => 'heroicon-o-finger-print',
            self::Recruitment   => 'icon-recruitments',
            self::Website       => 'icon-website',
            self::Barcode       => 'icon-barcode',
            self::Plugin        => 'icon-plugin',
            self::Setting       => 'icon-settings',
            self::Help          => 'icon-help',
        };
    }
}
