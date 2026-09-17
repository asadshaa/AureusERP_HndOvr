<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Support\Models\Company;

class WorkLocationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkLocation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Two pre-existing, unrelated bugs found and fixed while onboarding
        // needed a real work location row: employees_work_locations has no
        // 'user_id' column at all (dropped entirely here rather than mapped
        // -- there is no real equivalent to redirect it to), and the real
        // boolean column is 'is_active', not 'active'. Both would have
        // failed on the first real call to this factory's create().
        return [
            'company_id'      => Company::factory(),
            'name'            => fake()->name,
            // Not fake()->word: unconstrained against the WorkLocation enum's
            // actual backing values (home/office/other).
            'location_type'   => fake()->randomElement(['home', 'office', 'other']),
            'location_number' => fake()->numberBetween(1, 100),
            'is_active'       => true,
        ];
    }
}
