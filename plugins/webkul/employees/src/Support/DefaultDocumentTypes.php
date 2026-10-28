<?php

namespace Webkul\Employee\Support;

use Webkul\Employee\Models\DocumentType;

/**
 * The document types every company starts with. HR can rename them, switch
 * flags or add more in Employees -> Configurations -> Document types.
 */
final class DefaultDocumentTypes
{
    /**
     * @return array<int, array{code: string, name: string, has_expiry: bool, visible_to_employee: bool, required_for_new_hires: bool, sort: int}>
     */
    public static function rows(): array
    {
        $types = [
            ['cnic', 'CNIC', true, true, true],
            ['passport', 'Passport', true, true, false],
            ['driving_licence', 'Driving licence', true, true, false],
            ['employment_contract', 'Employment contract', false, true, true],
            ['offer_letter', 'Offer letter', false, true, true],
            ['educational_certificate', 'Educational certificate', false, true, false],
            ['experience_letter', 'Experience letter', false, true, false],
            ['medical_certificate', 'Medical certificate', true, false, false],
            ['police_verification', 'Police verification', true, true, false],
            ['bank_details', 'Bank details', false, true, false],
            ['other', 'Other', false, true, false],
        ];

        return array_map(fn (array $type, int $index): array => [
            'code'                   => $type[0],
            'name'                   => $type[1],
            'has_expiry'             => $type[2],
            'visible_to_employee'    => $type[3],
            'required_for_new_hires' => $type[4],
            'sort'                   => ($index + 1) * 10,
        ], $types, array_keys($types));
    }

    /** Create the missing default types of one company (idempotent). */
    public static function provisionForCompany(int $companyId): int
    {
        $created = 0;

        foreach (self::rows() as $row) {
            $type = DocumentType::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                $row + ['is_active' => true],
            );

            $created += $type->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
