<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\State;

class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Created once, eagerly, and reused below -- not left as independent
        // Company::factory() references on department_id/job_id/
        // work_location_id/user_id, which each used to resolve to their OWN
        // random company. That made a bare Employee::factory()->create()
        // structurally invalid the moment Employee::boot() started
        // enforcing "every hierarchy relationship must share the employee's
        // company" (see Employee::assertHierarchyIsSameCompany()) -- and
        // several OTHER plugins' factories (DepartmentFactory::manager_id,
        // time-off's Leave/LeaveAllocation, recruitments' Candidate/
        // JobPosition) create an Employee this same bare way.
        $company = Company::factory()->create();

        return [
            'company_id'                     => $company->id,
            'user_id'                        => User::factory()->create(['default_company_id' => $company->id])->id,
            'creator_id'                     => User::query()->value('id') ?? User::factory(),
            'calendar_id'                    => null,
            // manager_id: null overrides DepartmentFactory's own default
            // (Employee::factory()) -- left unoverridden, it recurses
            // straight back into this same definition().
            'department_id'                  => Department::factory()->create(['company_id' => $company->id, 'manager_id' => null])->id,
            'attendance_manager_id'          => User::query()->value('id') ?? User::factory(),
            // department_id: null for the identical reason -- EmployeeJobPositionFactory's
            // own default is Department::factory() (-> manager_id -> Employee::factory()).
            'job_id'                         => EmployeeJobPosition::factory()->create(['company_id' => $company->id, 'department_id' => null])->id,
            'partner_id'                     => null,
            // location_type: WorkLocationFactory's own default is fake()->word, which is
            // not constrained to the WorkLocation enum's actual cases (home/office/other)
            // -- an unrelated pre-existing bug, sidestepped here rather than fixed.
            'work_location_id'               => WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'office'])->id,
            'parent_id'                      => null,
            'coach_id'                       => null,
            'country_id'                     => Country::factory(),
            'private_state_id'               => State::factory(),
            'private_country_id'             => Country::factory(),
            'country_of_birth'               => Country::factory(),
            'bank_account_id'                => null,
            'departure_reason_id'            => DepartureReason::factory(),
            'name'                           => fake()->name,
            'job_title'                      => fake()->jobTitle,
            'work_phone'                     => fake()->phoneNumber,
            'mobile_phone'                   => fake()->phoneNumber,
            'color'                          => fake()->safeColorName,
            'work_email'                     => fake()->unique()->safeEmail,
            'children'                       => fake()->numberBetween(0, 5),
            'distance_home_work'             => fake()->numberBetween(5, 100),
            'km_home_work'                   => fake()->numberBetween(5, 100),
            'distance_home_work_unit'        => fake()->randomElement(['km', 'miles']),
            'private_street1'                => fake()->streetAddress,
            'private_street2'                => fake()->secondaryAddress,
            'private_city'                   => fake()->city,
            'private_zip'                    => fake()->postcode,
            'private_phone'                  => fake()->phoneNumber,
            'private_email'                  => fake()->unique()->safeEmail,
            'lang'                           => fake()->languageCode,
            'gender'                         => fake()->randomElement(),
            'birthday'                       => fake()->date(),
            'marital'                        => fake()->randomElement(['single', 'married', 'divorced', 'widowed']),
            'spouse_complete_name'           => fake()->name,
            'spouse_birthdate'               => fake()->date(),
            'place_of_birth'                 => fake()->city,
            'ssnid'                          => fake()->uuid,
            'sinid'                          => fake()->uuid,
            'identification_id'              => fake()->uuid,
            'passport_id'                    => fake()->uuid,
            'permit_no'                      => fake()->uuid,
            'visa_no'                        => fake()->uuid,
            'certificate'                    => fake()->word,
            'study_field'                    => fake()->word,
            'study_school'                   => fake()->company,
            'emergency_contact'              => fake()->name,
            'emergency_phone'                => fake()->phoneNumber,
            'employee_type'                  => fake()->randomElement(['full-time', 'part-time', 'contractor']),
            'barcode'                        => fake()->ean13,
            'pin'                            => fake()->randomNumber(6, true),
            'private_car_plate'              => fake()->bothify('??-###-##'),
            'visa_expire'                    => fake()->date(),
            'work_permit_expiration_date'    => fake()->date(),
            'departure_date'                 => fake()->optional()->date(),
            'departure_description'          => fake()->optional()->text,
            'employee_properties'            => fake()->optional()->json,
            'additional_note'                => fake()->optional()->text,
            'notes'                          => fake()->optional()->text,
            'is_active'                      => fake()->boolean(),
            'is_flexible'                    => fake()->boolean(),
            'is_fully_flexible'              => fake()->boolean(),
            'work_permit_scheduled_activity' => fake()->boolean(),
        ];
    }
}
