<?php

namespace Webkul\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class Attachment extends Model
{
    protected $table = 'chatter_attachments';

    protected $fillable = [
        'company_id',
        'creator_id',
        'message_id',
        'file_size',
        'name',
        'messageable',
        'file_path',
        'original_file_name',
        'mime_type',
    ];

    protected $appends = ['url'];

    public function messageable()
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Chatter attachments can hold anything (receipts, IDs, payslips): private disk only. */
    public const DISK = 'local';

    /**
     * A short-lived signed link to the file. Pages that render it are already
     * permission-checked; the link itself expires. Files uploaded before the
     * move to the private disk are still found on the public disk until
     * `security:move-private-files` has run.
     */
    public function getUrlAttribute(): string
    {
        if (Storage::disk(self::DISK)->exists((string) $this->file_path)) {
            return Storage::disk(self::DISK)->temporaryUrl((string) $this->file_path, now()->addMinutes(30));
        }

        return Storage::disk('public')->url((string) $this->file_path);
    }

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($attachment) {
            $attachment->creator_id ??= Auth::id();
        });

        static::deleted(function ($attachment) {
            $filePath = $attachment->file_path;

            foreach ([self::DISK, 'public'] as $disk) {
                if ($filePath && Storage::disk($disk)->exists($filePath)) {
                    Storage::disk($disk)->delete($filePath);
                }
            }
        });
    }
}
