<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeJobPositionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EmployeeJobPosition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'               => fake()->randomNumber(),
            'name'               => fake()->word,
            'description'        => fake()->text,
            'requirements'       => fake()->text,
            'expected_employees' => fake()->randomNumber(),
            'no_of_employee'     => fake()->randomNumber(),
            // The migration column is is_active (boolean('is_active')), not
            // 'status' -- that key does not exist on employees_job_positions
            // at all, so any prior call to this factory's create() would
            // have failed with an unknown-column SQL error. Pre-existing,
            // found while onboarding needed a real job position row.
            'is_active'          => true,
            'no_of_recruitment'  => fake()->randomNumber(),
            'department_id'      => Department::factory(),
            'company_id'         => Company::factory(),
            // 'open_date' does not exist on employees_job_positions either
            // (real columns are date_from/date_to) -- same pre-existing
            // class of bug as the 'status' column above.
            'date_from'          => fake()->date(),
            'creator_id'         => User::query()->value('id') ?? User::factory(),
        ];
    }
}
