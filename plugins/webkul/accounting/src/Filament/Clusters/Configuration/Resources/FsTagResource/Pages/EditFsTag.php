<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\FsTagService;

class EditFsTag extends EditRecord
{
    protected static string $resource = FsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Edits go through FsTagService like creation, so a blank or duplicate
     * code (or a GL outside the company) is reported instead of failing
     * on the database's unique index.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var FsTag $record */
        try {
            return app(FsTagService::class)->update($record, $data);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('The FS Tag was not saved')->body($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
