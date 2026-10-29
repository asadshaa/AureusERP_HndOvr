<?php

namespace Webkul\Employee\Support;

use Webkul\Employee\Models\AppraisalTemplate;

/**
 * The appraisal form every company starts with. HR can rename, reweight,
 * add or remove sections and items in Employees -> Configurations ->
 * Appraisal templates; provisioning never touches a company's templates
 * again once its default template exists.
 */
final class DefaultAppraisalTemplate
{
    public const Code = 'default';

    /**
     * @return array{name: string, description: string, sections: array<int, array{key: string, title: string, description: ?string, items: array<int, array{key: string, title: string, description: ?string, type: string, weight: float}>}>}
     */
    public static function definition(): array
    {
        return [
            'name'        => 'Annual appraisal',
            'description' => 'Key results and core competencies, each rated 1-5, with comments and goals for the next period.',
            'sections'    => [
                [
                    'key'         => 'results',
                    'title'       => 'Key results',
                    'description' => 'What was delivered in the period.',
                    'items'       => [
                        ['key' => 'quality', 'title' => 'Quality of work', 'description' => 'Accurate, complete and to standard; little rework.', 'type' => AppraisalKinds::ItemKpi, 'weight' => 20],
                        ['key' => 'delivery', 'title' => 'Productivity and on-time delivery', 'description' => 'Volume of work and deadlines met.', 'type' => AppraisalKinds::ItemKpi, 'weight' => 20],
                        ['key' => 'goals', 'title' => 'Agreed goals achieved', 'description' => 'Progress on the goals set at the start of the period.', 'type' => AppraisalKinds::ItemKpi, 'weight' => 20],
                    ],
                ],
                [
                    'key'         => 'competencies',
                    'title'       => 'Core competencies',
                    'description' => 'How the work was done.',
                    'items'       => [
                        ['key' => 'knowledge', 'title' => 'Job knowledge', 'description' => 'Knows the role, tools and procedures.', 'type' => AppraisalKinds::ItemCompetency, 'weight' => 10],
                        ['key' => 'communication', 'title' => 'Communication', 'description' => 'Clear, timely and respectful with colleagues and customers.', 'type' => AppraisalKinds::ItemCompetency, 'weight' => 10],
                        ['key' => 'teamwork', 'title' => 'Teamwork', 'description' => 'Helps others and works well across teams.', 'type' => AppraisalKinds::ItemCompetency, 'weight' => 10],
                        ['key' => 'ownership', 'title' => 'Ownership and reliability', 'description' => 'Takes responsibility and follows through.', 'type' => AppraisalKinds::ItemCompetency, 'weight' => 5],
                        ['key' => 'attendance', 'title' => 'Attendance and punctuality', 'description' => 'On time, present and available as agreed.', 'type' => AppraisalKinds::ItemCompetency, 'weight' => 5],
                    ],
                ],
            ],
        ];
    }

    /** Create the company's default template if it does not exist yet (idempotent). Returns whether one was created. */
    public static function provisionForCompany(int $companyId): bool
    {
        $definition = self::definition();

        $template = AppraisalTemplate::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => self::Code],
            [
                'name'               => $definition['name'],
                'description'        => $definition['description'],
                'sections'           => $definition['sections'],
                'rating_scale'       => AppraisalKinds::defaultRatingScale(),
                'rating_bands'       => AppraisalKinds::defaultRatingBands(),
                'include_goals'      => true,
                'results_visibility' => AppraisalKinds::VisibleAfterClose,
                'is_default'         => true,
                'is_active'          => true,
            ],
        );

        return $template->wasRecentlyCreated;
    }
}
