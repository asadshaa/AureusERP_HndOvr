<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;

class ViewBankStatement extends ViewRecord
{
    protected static string $resource = BankStatementResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Statement')
                ->schema(BankStatementResource::infolistSchema()),
        ]);
    }
}
