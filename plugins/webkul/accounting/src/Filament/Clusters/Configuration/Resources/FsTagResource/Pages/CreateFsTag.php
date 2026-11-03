<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\Company;

class CreateFsTag extends CreateRecord
{
    protected static string $resource = FsTagResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $company = Company::query()->findOrFail(auth()->user()?->default_company_id);

        try {
            return app(FsTagService::class)->create($company, $data);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('The FS Tag was not created')->body($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
