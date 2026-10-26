<?php

namespace Webkul\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Models\ScreeningQuestion;
use Webkul\Support\Models\Company;

/**
 * Starter screening questions for each company's library. HR can edit,
 * deactivate or add to them; this only creates them when the company has none
 * with the same label (idempotent, never overwrites HR's changes).
 */
class ScreeningQuestionSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        foreach ($this->starterQuestions() as $sort => $question) {
            ScreeningQuestion::query()->firstOrCreate(
                ['company_id' => $company->id, 'label' => $question['label']],
                $question + ['sort' => $sort + 1, 'is_active' => true],
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function starterQuestions(): array
    {
        return [
            [
                'type'        => ScreeningQuestionType::YES_NO,
                'label'       => 'This job is on-site. Can you work from our office five days a week?',
                'help_text'   => 'Answer No if you are only looking for remote work.',
                'is_required' => true,
                'knockout'    => ['required_answer' => 'yes'],
            ],
            [
                'type'        => ScreeningQuestionType::NUMBER,
                'label'       => 'How many years of relevant experience do you have?',
                'is_required' => true,
                'knockout'    => ['min' => 1],
            ],
            [
                'type'        => ScreeningQuestionType::NUMBER,
                'label'       => 'What is your expected monthly salary (PKR)?',
                'is_required' => false,
                'knockout'    => null,
            ],
            [
                'type'        => ScreeningQuestionType::SINGLE_CHOICE,
                'label'       => 'What is your notice period?',
                'options'     => ['Immediately', '2 weeks', '1 month', '2 months or more'],
                'is_required' => true,
                'knockout'    => null,
            ],
            [
                'type'        => ScreeningQuestionType::YES_NO,
                'label'       => 'Do you have a valid driving licence?',
                'is_required' => false,
                'knockout'    => null,
            ],
        ];
    }
}
