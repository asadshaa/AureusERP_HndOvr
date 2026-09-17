<?php

namespace Webkul\Accounting\Support;

use Illuminate\Database\Eloquent\Model;
use Webkul\Accounting\Models\Document;

/**
 * Turns a Document into a concrete, ordered list of Drive folder names --
 * the ONLY place the "Aureus/{Company}/Accounting/{Type}/{Identifier}"
 * convention actually lives. config/accounting_drive.php owns the
 * template; this class just fills it in. Change the convention by
 * editing that config, never by editing this class or anything that
 * calls it.
 */
class DriveFolderPathResolver
{
    /**
     * @return array<int, string> ordered path segments, root folder name first
     */
    public function resolve(Document $document): array
    {
        $template = config('accounting_drive.path_templates.'.$document->document_type->value)
            ?? config('accounting_drive.path_templates.default');

        $segments = array_map(
            fn (string $segment) => $this->fillPlaceholder($segment, $document),
            $template,
        );

        return [
            $this->sanitize(config('accounting_drive.root_folder_name', 'Aureus')),
            ...array_map($this->sanitize(...), $segments),
        ];
    }

    private function fillPlaceholder(string $segment, Document $document): string
    {
        return match ($segment) {
            '{company}'    => $this->companySegmentFor($document),
            '{identifier}' => $this->identifierFor($document),
            default        => $segment,
        };
    }

    /**
     * Company folder segments MUST embed the immutable company_id, never
     * just the company's display name -- Company.name has no uniqueness
     * constraint (only id and tax_id are unique) and is editable by any
     * company admin via CompanyResource. Keying purely on name would let
     * two companies that happen to share (or are renamed to share) the
     * same name get merged into the exact same Drive folder tree --
     * exactly the cross-tenant leak company isolation exists to prevent.
     * The id suffix is what actually guarantees isolation; the name is
     * included only so the folder stays human-readable in Drive's UI.
     */
    private function companySegmentFor(Document $document): string
    {
        $name = $document->company?->name ?? 'Unknown company';

        return "{$name} ({$document->company_id})";
    }

    /**
     * The human-readable identifier for whatever this document is
     * attached to (an invoice/bill/journal-entry number, a bank
     * statement's own name) -- every attachable type in this codebase
     * exposes a `name` column, so this stays generic rather than
     * special-casing each one. A document not yet attached to anything,
     * or attached to something without a usable name, falls back to its
     * own id so the path is still deterministic and non-colliding.
     */
    private function identifierFor(Document $document): string
    {
        $attachable = $document->attachments()->with('attachable')->first()?->attachable;

        if ($attachable instanceof Model && filled($attachable->getAttribute('name') ?? null)) {
            return (string) $attachable->getAttribute('name');
        }

        return "document-{$document->id}";
    }

    /**
     * Drive folder/file names can't contain '/' (read as a path
     * separator by some tooling even though the API itself is lenient)
     * -- strip it and trim so a name like "Trade Debtors / Local" can't
     * silently create an extra folder level.
     */
    private function sanitize(string $segment): string
    {
        return trim(str_replace(['/', '\\'], '-', $segment));
    }
}
