<?php

namespace Database\Seeders\Demo;

/**
 * The people, jobs and candidates of the demo companies (ShipLine and
 * OpenPort, both Karachi logistics companies). Staff are keyed by the local
 * part of their login (<first>@<domain>); "parent" is the line manager's key.
 * The seven base staff of DemoCompanyStaffSeeder are under "existing": only
 * their empty fields are filled in.
 */
final class DemoCatalog
{
    /** Departments added under Operations (the base seeder made HR, Operations, Finance and Sales). */
    public const NEW_DEPARTMENTS = ['Fleet', 'Dispatch', 'Customer Service', 'Warehouse'];

    /**
     * @var array<string, array{
     *     domain: string,
     *     base_manager: string,
     *     office: string,
     *     existing: array<string, array<string, mixed>>,
     *     staff: array<string, array<string, mixed>>,
     *     department_managers: array<string, string>,
     *     jobs: array<int, array<string, mixed>>,
     *     candidates: array<int, array<string, mixed>>
     * }>
     */
    public const COMPANIES = [
        'ShipLine' => [
            'domain'       => 'shipline.demo',
            'base_manager' => 'kamran',
            'office'       => 'ShipLine House, Plot 14, West Wharf Road, Kemari, Karachi',
            'existing'     => [
                'hina'   => ['joined' => '2020-02-03', 'gender' => 'female', 'born' => '1987-06-14', 'salary' => 340000, 'grade' => 'M2', 'city' => 'karachi', 'parent' => 'tariq', 'marital' => 'married', 'study' => ['MBA (HR)', 'IBA Karachi']],
                'saad'   => ['joined' => '2021-08-16', 'gender' => 'male', 'born' => '1993-01-22', 'salary' => 165000, 'grade' => 'E3', 'city' => 'karachi', 'parent' => 'hina', 'marital' => 'single', 'study' => ['BBA', 'SZABIST Karachi']],
                'kamran' => ['joined' => '2019-09-02', 'gender' => 'male', 'born' => '1981-11-05', 'salary' => 480000, 'grade' => 'M3', 'city' => 'karachi', 'parent' => 'tariq', 'marital' => 'married', 'study' => ['BE Industrial Engineering', 'NED University']],
                'bilal'  => ['joined' => '2022-03-07', 'gender' => 'male', 'born' => '1995-04-18', 'salary' => 115000, 'grade' => 'E2', 'city' => 'karachi', 'parent' => 'kamran', 'marital' => 'single', 'study' => ['BBA (Supply Chain)', 'Iqra University']],
                'sana'   => ['joined' => '2022-11-14', 'gender' => 'female', 'born' => '1996-09-30', 'salary' => 98000, 'grade' => 'E2', 'city' => 'karachi', 'parent' => 'kamran', 'marital' => 'single', 'study' => ['B.Com', 'University of Karachi']],
                'fahad'  => ['joined' => '2021-01-11', 'gender' => 'male', 'born' => '1990-12-02', 'salary' => 145000, 'grade' => 'E3', 'city' => 'karachi', 'parent' => 'imran', 'marital' => 'married', 'study' => ['ACCA (finalist)', 'PAF-KIET']],
                'rabia'  => ['joined' => '2023-05-08', 'gender' => 'female', 'born' => '1997-07-21', 'salary' => 110000, 'grade' => 'E2', 'city' => 'lahore', 'parent' => 'faisal', 'marital' => 'single', 'study' => ['BBA (Marketing)', 'University of the Punjab']],
            ],
            'staff' => [
                'tariq'   => ['name' => 'Tariq Mehmood', 'title' => 'General Manager', 'dept' => 'Operations', 'roles' => ['manager'], 'parent' => null, 'joined' => '2019-03-04', 'gender' => 'male', 'born' => '1975-02-11', 'salary' => 900000, 'grade' => 'M4', 'city' => 'karachi', 'marital' => 'married', 'study' => ['MBA', 'IBA Karachi']],
                'imran'   => ['name' => 'Imran Siddiqui', 'title' => 'Finance Manager', 'dept' => 'Finance', 'roles' => ['manager', 'accounting_manager'], 'parent' => 'tariq', 'joined' => '2020-06-15', 'gender' => 'male', 'born' => '1983-08-19', 'salary' => 420000, 'grade' => 'M3', 'city' => 'karachi', 'marital' => 'married', 'study' => ['ACA', 'ICAP']],
                'faisal'  => ['name' => 'Faisal Butt', 'title' => 'Sales Manager', 'dept' => 'Sales', 'roles' => ['manager'], 'parent' => 'tariq', 'joined' => '2021-04-05', 'gender' => 'male', 'born' => '1986-03-27', 'salary' => 360000, 'grade' => 'M2', 'city' => 'lahore', 'marital' => 'married', 'study' => ['MBA (Marketing)', 'LUMS']],
                'naveed'  => ['name' => 'Naveed Akhtar', 'title' => 'Fleet Supervisor', 'dept' => 'Fleet', 'roles' => ['manager'], 'parent' => 'kamran', 'joined' => '2020-10-12', 'gender' => 'male', 'born' => '1984-05-09', 'salary' => 210000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['DAE Mechanical', 'Government College of Technology, Karachi']],
                'adnan'   => ['name' => 'Adnan Malik', 'title' => 'Dispatch Lead', 'dept' => 'Dispatch', 'roles' => ['manager'], 'parent' => 'kamran', 'joined' => '2021-02-01', 'gender' => 'male', 'born' => '1989-10-23', 'salary' => 185000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['BBA (Supply Chain)', 'Bahria University Karachi']],
                'nida'    => ['name' => 'Nida Hassan', 'title' => 'Customer Service Lead', 'dept' => 'Customer Service', 'roles' => ['manager'], 'parent' => 'kamran', 'joined' => '2022-01-17', 'gender' => 'female', 'born' => '1991-02-15', 'salary' => 160000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['BS Mass Communication', 'University of Karachi']],
                'shahid'  => ['name' => 'Shahid Latif', 'title' => 'Warehouse Supervisor', 'dept' => 'Warehouse', 'roles' => ['manager'], 'parent' => 'kamran', 'joined' => '2020-07-20', 'gender' => 'male', 'born' => '1982-12-30', 'salary' => 150000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['B.Com', 'Government College of Commerce, Karachi']],
                'zubair'  => ['name' => 'Zubair Ahmed', 'title' => 'Driver Coordinator', 'dept' => 'Fleet', 'roles' => ['employee'], 'parent' => 'naveed', 'joined' => '2022-06-06', 'gender' => 'male', 'born' => '1994-07-04', 'salary' => 78000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['Intermediate (Commerce)', 'Adamjee Government Science College']],
                'waqas'   => ['name' => 'Waqas Hussain', 'title' => 'Fleet Maintenance Officer', 'dept' => 'Fleet', 'roles' => ['employee'], 'parent' => 'naveed', 'joined' => '2023-09-11', 'gender' => 'male', 'born' => '1992-04-12', 'salary' => 92000, 'grade' => 'E2', 'city' => 'karachi', 'marital' => 'single', 'study' => ['DAE Auto & Diesel', 'Pakistan Swedish Institute of Technology']],
                'hamza'   => ['name' => 'Hamza Rafiq', 'title' => 'Dispatcher', 'dept' => 'Dispatch', 'roles' => ['employee'], 'parent' => 'adnan', 'joined' => '2024-02-19', 'gender' => 'male', 'born' => '1998-01-08', 'salary' => 82000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BBA', 'Iqra University']],
                'sadia'   => ['name' => 'Sadia Nawaz', 'title' => 'Dispatcher', 'dept' => 'Dispatch', 'roles' => ['employee'], 'parent' => 'adnan', 'joined' => '2026-08-03', 'gender' => 'female', 'born' => '1999-11-25', 'salary' => 75000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BS Business Administration', 'Jinnah University for Women']],
                'asma'    => ['name' => 'Asma Farooq', 'title' => 'Accounts Officer', 'dept' => 'Finance', 'roles' => ['employee'], 'parent' => 'imran', 'joined' => '2024-07-01', 'gender' => 'female', 'born' => '1996-03-03', 'salary' => 125000, 'grade' => 'E2', 'city' => 'karachi', 'marital' => 'married', 'study' => ['B.Com (Hons)', 'Hamdard University']],
                'hira'    => ['name' => 'Hira Saleem', 'title' => 'Business Development Executive', 'dept' => 'Sales', 'roles' => ['employee'], 'parent' => 'faisal', 'joined' => '2025-03-10', 'gender' => 'female', 'born' => '1998-06-17', 'salary' => 105000, 'grade' => 'E2', 'city' => 'islamabad', 'marital' => 'single', 'study' => ['BBA', 'NUST Business School']],
                'umair'   => ['name' => 'Umair Aslam', 'title' => 'Customer Service Officer', 'dept' => 'Customer Service', 'roles' => ['employee'], 'parent' => 'nida', 'joined' => '2025-11-03', 'gender' => 'male', 'born' => '2000-02-29', 'salary' => 72000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BS Computer Science', 'FAST-NUCES Karachi']],
                'rizwan'  => ['name' => 'Rizwan Ali', 'title' => 'Store Keeper', 'dept' => 'Warehouse', 'roles' => ['employee'], 'parent' => 'shahid', 'joined' => '2023-01-23', 'gender' => 'male', 'born' => '1990-09-14', 'salary' => 62000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['Intermediate', 'Board of Intermediate Education Karachi']],
                'maham'   => ['name' => 'Maham Javaid', 'title' => 'HR Assistant', 'dept' => 'Human Resources', 'roles' => ['employee'], 'parent' => 'hina', 'joined' => '2026-09-21', 'gender' => 'female', 'born' => '2001-05-06', 'salary' => 70000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BBA (HRM)', 'Institute of Business Management']],
            ],
            'department_managers' => [
                'Human Resources'  => 'hina',
                'Finance'          => 'imran',
                'Sales'            => 'faisal',
                'Fleet'            => 'naveed',
                'Dispatch'         => 'adnan',
                'Customer Service' => 'nida',
                'Warehouse'        => 'shahid',
            ],
            'jobs' => [
                [
                    'key'        => 'fleet-dispatcher', 'name' => 'Fleet Dispatcher', 'public_title' => 'Fleet Dispatcher (Karachi Port Operations)', 'dept' => 'Dispatch',
                    'recruiter'  => 'saad', 'manager' => 'adnan', 'positions' => 2, 'location' => 'on_site', 'salary' => [70000, 95000], 'salary_visible' => true,
                    'experience' => 2, 'keywords' => ['dispatch', 'fleet', 'GPS tracking', 'route planning', 'Excel'], 'status' => 'published', 'published_days_ago' => 19,
                    'summary'    => 'Plan and track daily container and cargo movements between Karachi port, Port Qasim and customer warehouses.',
                    'duties'     => ['Assign trucks and drivers to daily bookings', 'Track vehicles on the GPS tracking portal and update customers', 'Plan routes around port congestion and city timings', 'Keep trip sheets and fuel slips in order'],
                    'needs'      => ['2+ years of dispatch or fleet operations experience', 'Comfortable with Excel and GPS tracking tools', 'Fluent Urdu; working English', 'Willing to work rotating shifts'],
                ],
                [
                    'key'        => 'customs-clearance-officer', 'name' => 'Customs Clearance Officer', 'public_title' => 'Customs Clearance Officer', 'dept' => 'Operations',
                    'recruiter'  => 'hina', 'manager' => 'kamran', 'positions' => 1, 'location' => 'on_site', 'salary' => [110000, 150000], 'salary_visible' => false,
                    'experience' => 3, 'keywords' => ['customs', 'WeBOC', 'GD filing', 'HS codes', 'port'], 'status' => 'published', 'published_days_ago' => 26,
                    'summary'    => 'Handle import and export clearance for our shipping clients at KICT, SAPT and Port Qasim.',
                    'duties'     => ['File goods declarations on WeBOC', 'Classify cargo under the correct HS codes', 'Coordinate examinations with customs and terminal staff', 'Follow up on delivery orders and release'],
                    'needs'      => ['3+ years in customs clearance', 'Hands-on WeBOC experience', 'Good knowledge of HS codes and duty structures', 'B.Com or equivalent'],
                ],
                [
                    'key'        => 'accounts-officer', 'name' => 'Accounts Officer', 'public_title' => 'Accounts Officer (Payables)', 'dept' => 'Finance',
                    'recruiter'  => 'saad', 'manager' => 'imran', 'positions' => 1, 'location' => 'hybrid', 'salary' => [120000, 160000], 'salary_visible' => true,
                    'experience' => 3, 'keywords' => ['accounts payable', 'reconciliation', 'withholding tax', 'Excel', 'ACCA'], 'status' => 'published', 'published_days_ago' => 11,
                    'summary'    => 'Own vendor payables for our fleet and terminal suppliers and keep the books reconciled every month.',
                    'duties'     => ['Process vendor invoices and payments', 'Prepare bank and vendor reconciliations', 'Compute and deposit withholding tax', 'Support the month-end close'],
                    'needs'      => ['3+ years in accounts payable', 'ACCA part-qualified or B.Com', 'Strong Excel skills', 'Knowledge of withholding tax rules'],
                ],
                [
                    'key'        => 'warehouse-supervisor', 'name' => 'Warehouse Supervisor', 'public_title' => 'Warehouse Supervisor (Night Shift)', 'dept' => 'Warehouse',
                    'recruiter'  => 'hina', 'manager' => 'shahid', 'positions' => 1, 'location' => 'on_site', 'salary' => [90000, 120000], 'salary_visible' => true,
                    'experience' => 4, 'keywords' => ['warehouse', 'inventory', 'forklift', 'stock count'], 'status' => 'pending', 'published_days_ago' => 2,
                    'summary'    => 'Run the night shift of our bonded warehouse in Kemari.',
                    'duties'     => ['Supervise loading and unloading crews', 'Keep stock records accurate', 'Run weekly cycle counts', 'Enforce safety rules on the floor'],
                    'needs'      => ['4+ years in warehouse operations', 'Experience with stock counts and inventory systems', 'Able to lead a team of 10+'],
                ],
            ],
            'candidates' => [
                ['name' => 'Ali Hassan', 'job' => 'fleet-dispatcher', 'flow' => 'second_interview_upcoming', 'applied_days_ago' => 16, 'source' => 'LinkedIn', 'years' => 4, 'expected' => 90000, 'priority' => '2', 'background' => 'Dispatcher at a Karachi logistics company: daily fleet allocation for 60 trucks, GPS tracking and route planning around port timings. Advanced Excel.'],
                ['name' => 'Kashif Mehmood', 'job' => 'fleet-dispatcher', 'flow' => 'first_interview_upcoming', 'applied_days_ago' => 9, 'source' => 'Indeed', 'years' => 2, 'expected' => 80000, 'priority' => '1', 'background' => 'Two years of dispatch coordination for an e-commerce delivery fleet in Karachi. Uses GPS tracking dashboards and Excel daily.'],
                ['name' => 'Sobia Akhtar', 'job' => 'fleet-dispatcher', 'flow' => 'qualified', 'applied_days_ago' => 7, 'source' => 'Careers page', 'years' => 3, 'expected' => 85000, 'priority' => '1', 'background' => 'Operations assistant at a freight forwarder: trip sheets, driver rosters and route planning. Excel.'],
                ['name' => 'Waleed Ahmed', 'job' => 'fleet-dispatcher', 'flow' => 'new', 'applied_days_ago' => 2, 'source' => 'WhatsApp', 'years' => 1, 'expected' => 70000, 'priority' => '0', 'background' => 'One year as a fleet coordinator trainee. Basic Excel.'],
                ['name' => 'Junaid Iqbal', 'job' => 'fleet-dispatcher', 'flow' => 'refused', 'refuse' => 'Does not fit the job requirements', 'applied_days_ago' => 14, 'source' => 'Indeed', 'years' => 0, 'expected' => 65000, 'priority' => '0', 'background' => 'Fresh graduate (BBA) looking for a first role in sales.'],
                ['name' => 'Rashid Mahmood', 'job' => 'customs-clearance-officer', 'flow' => 'offer', 'applied_days_ago' => 24, 'source' => 'LinkedIn', 'years' => 6, 'expected' => 145000, 'priority' => '3', 'background' => 'Six years of customs clearance at KICT and Port Qasim. Files goods declarations on WeBOC daily; strong on HS codes and duty structures.'],
                ['name' => 'Farah Naz', 'job' => 'customs-clearance-officer', 'flow' => 'second_interview_upcoming', 'applied_days_ago' => 18, 'source' => 'Careers page', 'years' => 3, 'expected' => 125000, 'priority' => '2', 'background' => 'Customs documentation officer for three years: WeBOC GD filing, HS codes, examination follow-up.'],
                ['name' => 'Usman Ghani', 'job' => 'customs-clearance-officer', 'flow' => 'new', 'applied_days_ago' => 3, 'source' => 'LinkedIn', 'years' => 2, 'expected' => 110000, 'priority' => '0', 'background' => 'Clearing agent assistant, two years. Some WeBOC exposure.'],
                ['name' => 'Mahnoor Ali', 'job' => 'accounts-officer', 'flow' => 'first_interview_upcoming', 'applied_days_ago' => 8, 'source' => 'LinkedIn', 'years' => 4, 'expected' => 150000, 'priority' => '2', 'background' => 'ACCA affiliate with four years in accounts payable and reconciliation at a shipping agency. Withholding tax returns, advanced Excel.'],
                ['name' => 'Talha Rehman', 'job' => 'accounts-officer', 'flow' => 'new', 'applied_days_ago' => 4, 'source' => 'Indeed', 'years' => 2, 'expected' => 120000, 'priority' => '1', 'background' => 'Two years as an accounts assistant: vendor invoices, bank reconciliation, Excel.'],
                ['name' => 'Ammar Siddiqui', 'job' => 'accounts-officer', 'flow' => 'refused', 'refuse' => 'Refused by applicant: salary', 'applied_days_ago' => 10, 'source' => 'Careers page', 'years' => 5, 'expected' => 210000, 'priority' => '1', 'background' => 'Five years in accounts payable and treasury; ACCA. Withholding tax and reconciliation.'],
                ['name' => 'Kiran Shahzad', 'job' => 'accounts-officer', 'flow' => 'phone_screen_today', 'applied_days_ago' => 6, 'source' => 'LinkedIn', 'years' => 3, 'expected' => 135000, 'priority' => '1', 'background' => 'Three years in accounts at a textile exporter: payables, reconciliation and withholding tax. Excel.'],
            ],
        ],
        'OpenPort' => [
            'domain'       => 'openport.demo',
            'base_manager' => 'nadia',
            'office'       => 'OpenPort Terminal Office, Berth 6, Port Qasim, Karachi',
            'existing'     => [
                'mariam' => ['joined' => '2019-11-18', 'gender' => 'female', 'born' => '1985-10-09', 'salary' => 350000, 'grade' => 'M2', 'city' => 'karachi', 'parent' => 'khalid', 'marital' => 'married', 'study' => ['MBA (HRM)', 'Karachi University Business School']],
                'omer'   => ['joined' => '2022-04-04', 'gender' => 'male', 'born' => '1994-08-28', 'salary' => 160000, 'grade' => 'E3', 'city' => 'karachi', 'parent' => 'mariam', 'marital' => 'single', 'study' => ['BBA (HR)', 'Bahria University Karachi']],
                'nadia'  => ['joined' => '2020-01-06', 'gender' => 'female', 'born' => '1984-01-31', 'salary' => 470000, 'grade' => 'M3', 'city' => 'karachi', 'parent' => 'khalid', 'marital' => 'married', 'study' => ['MS Supply Chain Management', 'IBA Karachi']],
                'usama'  => ['joined' => '2021-06-21', 'gender' => 'male', 'born' => '1988-12-12', 'salary' => 175000, 'grade' => 'M1', 'city' => 'karachi', 'parent' => 'nadia', 'marital' => 'married', 'study' => ['BE Mechanical', 'Mehran UET Jamshoro']],
                'ayesha' => ['joined' => '2023-02-13', 'gender' => 'female', 'born' => '1997-05-19', 'salary' => 105000, 'grade' => 'E2', 'city' => 'karachi', 'parent' => 'nadia', 'marital' => 'single', 'study' => ['BS Logistics', 'NED University']],
                'junaid' => ['joined' => '2022-09-05', 'gender' => 'male', 'born' => '1993-03-08', 'salary' => 118000, 'grade' => 'E2', 'city' => 'karachi', 'parent' => 'sohail', 'marital' => 'married', 'study' => ['B.Com', 'Government College of Commerce, Karachi']],
                'komal'  => ['joined' => '2024-01-08', 'gender' => 'female', 'born' => '1998-10-01', 'salary' => 85000, 'grade' => 'E1', 'city' => 'karachi', 'parent' => 'saima', 'marital' => 'single', 'study' => ['BS Media Studies', 'Jinnah University for Women']],
            ],
            'staff' => [
                'khalid'  => ['name' => 'Khalid Mahmood', 'title' => 'General Manager', 'dept' => 'Operations', 'roles' => ['manager'], 'parent' => null, 'joined' => '2019-01-14', 'gender' => 'male', 'born' => '1974-07-22', 'salary' => 880000, 'grade' => 'M4', 'city' => 'karachi', 'marital' => 'married', 'study' => ['MBA', 'LUMS']],
                'sohail'  => ['name' => 'Sohail Anjum', 'title' => 'Finance Manager', 'dept' => 'Finance', 'roles' => ['manager', 'accounting_manager'], 'parent' => 'khalid', 'joined' => '2020-03-02', 'gender' => 'male', 'born' => '1982-09-03', 'salary' => 410000, 'grade' => 'M3', 'city' => 'karachi', 'marital' => 'married', 'study' => ['ACMA', 'ICMA Pakistan']],
                'saima'   => ['name' => 'Saima Rehman', 'title' => 'Sales Manager', 'dept' => 'Sales', 'roles' => ['manager'], 'parent' => 'khalid', 'joined' => '2021-07-12', 'gender' => 'female', 'born' => '1987-04-16', 'salary' => 340000, 'grade' => 'M2', 'city' => 'islamabad', 'marital' => 'married', 'study' => ['MBA (Marketing)', 'NUST Business School']],
                'arshad'  => ['name' => 'Arshad Iqbal', 'title' => 'Fleet Supervisor', 'dept' => 'Fleet', 'roles' => ['manager'], 'parent' => 'nadia', 'joined' => '2020-09-14', 'gender' => 'male', 'born' => '1983-06-25', 'salary' => 205000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['DAE Mechanical', 'Government College of Technology, Karachi']],
                'farhan'  => ['name' => 'Farhan Saeed', 'title' => 'Dispatch Lead', 'dept' => 'Dispatch', 'roles' => ['manager'], 'parent' => 'nadia', 'joined' => '2021-10-04', 'gender' => 'male', 'born' => '1990-02-07', 'salary' => 180000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['BBA (Supply Chain)', 'Iqra University']],
                'amna'    => ['name' => 'Amna Bashir', 'title' => 'Customer Service Lead', 'dept' => 'Customer Service', 'roles' => ['manager'], 'parent' => 'nadia', 'joined' => '2022-05-16', 'gender' => 'female', 'born' => '1990-11-11', 'salary' => 155000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['BS Business Administration', 'University of Karachi']],
                'irfan'   => ['name' => 'Irfan Khan', 'title' => 'Warehouse Supervisor', 'dept' => 'Warehouse', 'roles' => ['manager'], 'parent' => 'nadia', 'joined' => '2020-12-07', 'gender' => 'male', 'born' => '1981-03-19', 'salary' => 148000, 'grade' => 'M1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['B.Com', 'University of Karachi']],
                'kashif'  => ['name' => 'Kashif Raza', 'title' => 'Driver Coordinator', 'dept' => 'Fleet', 'roles' => ['employee'], 'parent' => 'arshad', 'joined' => '2023-04-10', 'gender' => 'male', 'born' => '1993-12-20', 'salary' => 76000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['Intermediate (Commerce)', 'Government Degree College Malir']],
                'yasir'   => ['name' => 'Yasir Abbas', 'title' => 'Fleet Maintenance Officer', 'dept' => 'Fleet', 'roles' => ['employee'], 'parent' => 'arshad', 'joined' => '2024-05-20', 'gender' => 'male', 'born' => '1991-08-08', 'salary' => 90000, 'grade' => 'E2', 'city' => 'karachi', 'marital' => 'married', 'study' => ['DAE Auto & Diesel', 'Pakistan Swedish Institute of Technology']],
                'danish'  => ['name' => 'Danish Ali', 'title' => 'Dispatcher', 'dept' => 'Dispatch', 'roles' => ['employee'], 'parent' => 'farhan', 'joined' => '2023-11-06', 'gender' => 'male', 'born' => '1997-03-14', 'salary' => 80000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BBA', 'Hamdard University']],
                'mehreen' => ['name' => 'Mehreen Akram', 'title' => 'Dispatcher', 'dept' => 'Dispatch', 'roles' => ['employee'], 'parent' => 'farhan', 'joined' => '2026-07-13', 'gender' => 'female', 'born' => '2000-09-02', 'salary' => 74000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BS Business Administration', 'Sir Syed University']],
                'tahir'   => ['name' => 'Tahir Hussain', 'title' => 'Accounts Officer', 'dept' => 'Finance', 'roles' => ['employee'], 'parent' => 'sohail', 'joined' => '2022-02-14', 'gender' => 'male', 'born' => '1992-01-26', 'salary' => 128000, 'grade' => 'E2', 'city' => 'karachi', 'marital' => 'married', 'study' => ['B.Com (Hons)', 'University of Karachi']],
                'ahsan'   => ['name' => 'Ahsan Javed', 'title' => 'Business Development Executive', 'dept' => 'Sales', 'roles' => ['employee'], 'parent' => 'saima', 'joined' => '2025-01-20', 'gender' => 'male', 'born' => '1996-07-30', 'salary' => 110000, 'grade' => 'E2', 'city' => 'islamabad', 'marital' => 'single', 'study' => ['BBA', 'COMSATS University Islamabad']],
                'bushra'  => ['name' => 'Bushra Naz', 'title' => 'Customer Service Officer', 'dept' => 'Customer Service', 'roles' => ['employee'], 'parent' => 'amna', 'joined' => '2025-08-18', 'gender' => 'female', 'born' => '1999-04-04', 'salary' => 70000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BS English', 'University of Karachi']],
                'noman'   => ['name' => 'Noman Shah', 'title' => 'Warehouse Assistant', 'dept' => 'Warehouse', 'roles' => ['employee'], 'parent' => 'irfan', 'joined' => '2024-10-07', 'gender' => 'male', 'born' => '1995-10-10', 'salary' => 58000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'married', 'study' => ['Intermediate', 'Board of Intermediate Education Karachi']],
                'zara'    => ['name' => 'Zara Ahmed', 'title' => 'HR Assistant', 'dept' => 'Human Resources', 'roles' => ['employee'], 'parent' => 'mariam', 'joined' => '2026-09-01', 'gender' => 'female', 'born' => '2001-12-18', 'salary' => 68000, 'grade' => 'E1', 'city' => 'karachi', 'marital' => 'single', 'study' => ['BBA (HRM)', 'Greenwich University Karachi']],
            ],
            'department_managers' => [
                'Human Resources'  => 'mariam',
                'Finance'          => 'sohail',
                'Sales'            => 'saima',
                'Fleet'            => 'arshad',
                'Dispatch'         => 'farhan',
                'Customer Service' => 'amna',
                'Warehouse'        => 'irfan',
            ],
            'jobs' => [
                [
                    'key'        => 'yard-operations-supervisor', 'name' => 'Yard Operations Supervisor', 'public_title' => 'Yard Operations Supervisor (Port Qasim)', 'dept' => 'Operations',
                    'recruiter'  => 'mariam', 'manager' => 'nadia', 'positions' => 1, 'location' => 'on_site', 'salary' => [150000, 190000], 'salary_visible' => false,
                    'experience' => 5, 'keywords' => ['yard planning', 'container terminal', 'TOS', 'reach stacker', 'safety'], 'status' => 'published', 'published_days_ago' => 23,
                    'summary'    => 'Lead the yard shift at our Port Qasim container terminal.',
                    'duties'     => ['Plan container stacking and yard moves', 'Coordinate reach stacker and RTG operators', 'Keep the terminal operating system up to date', 'Run shift safety briefings'],
                    'needs'      => ['5+ years in a container terminal or ICD', 'Experience with a terminal operating system (TOS)', 'Strong safety mindset'],
                ],
                [
                    'key'        => 'truck-dispatcher', 'name' => 'Truck Dispatcher', 'public_title' => 'Truck Dispatcher', 'dept' => 'Dispatch',
                    'recruiter'  => 'omer', 'manager' => 'farhan', 'positions' => 2, 'location' => 'on_site', 'salary' => [65000, 90000], 'salary_visible' => true,
                    'experience' => 1, 'keywords' => ['dispatch', 'trucking', 'GPS tracking', 'Excel'], 'status' => 'published', 'published_days_ago' => 15,
                    'summary'    => 'Book and dispatch trucks for containers leaving Port Qasim for up-country destinations.',
                    'duties'     => ['Book trucks with our vendor transporters', 'Dispatch and track trucks to Lahore, Faisalabad and Multan', 'Update customers on arrival times', 'Close trip records'],
                    'needs'      => ['1+ year in trucking or dispatch', 'GPS tracking and Excel', 'Good phone manner in Urdu and English'],
                ],
                [
                    'key'        => 'billing-executive', 'name' => 'Billing Executive', 'public_title' => 'Billing Executive', 'dept' => 'Finance',
                    'recruiter'  => 'omer', 'manager' => 'sohail', 'positions' => 1, 'location' => 'hybrid', 'salary' => [95000, 130000], 'salary_visible' => true,
                    'experience' => 2, 'keywords' => ['billing', 'invoicing', 'receivables', 'sales tax', 'Excel'], 'status' => 'published', 'published_days_ago' => 8,
                    'summary'    => 'Raise terminal handling and storage invoices and chase receivables.',
                    'duties'     => ['Prepare daily customer invoices', 'Apply sales tax correctly', 'Follow up overdue receivables', 'Reconcile customer statements'],
                    'needs'      => ['2+ years in billing or receivables', 'Working knowledge of sales tax invoicing', 'Excel'],
                ],
                [
                    'key'        => 'customer-service-representative', 'name' => 'Customer Service Representative', 'public_title' => 'Customer Service Representative', 'dept' => 'Customer Service',
                    'recruiter'  => 'mariam', 'manager' => 'amna', 'positions' => 2, 'location' => 'on_site', 'salary' => [60000, 80000], 'salary_visible' => true,
                    'experience' => 1, 'keywords' => ['customer service', 'call centre', 'shipping', 'CRM'], 'status' => 'pending', 'published_days_ago' => 1,
                    'summary'    => 'Answer customer queries about container status, delivery orders and charges.',
                    'duties'     => ['Answer calls, emails and WhatsApp messages', 'Track containers and share status', 'Log complaints and follow up'],
                    'needs'      => ['1+ year in customer service', 'Clear spoken English and Urdu', 'Comfortable with a CRM'],
                ],
            ],
            'candidates' => [
                ['name' => 'Sajid Mehmood', 'job' => 'yard-operations-supervisor', 'flow' => 'offer', 'applied_days_ago' => 21, 'source' => 'LinkedIn', 'years' => 7, 'expected' => 185000, 'priority' => '3', 'background' => 'Seven years at a container terminal in Karachi: yard planning, reach stacker and RTG coordination on a TOS, shift safety briefings.'],
                ['name' => 'Naeem Akhtar', 'job' => 'yard-operations-supervisor', 'flow' => 'second_interview_upcoming', 'applied_days_ago' => 17, 'source' => 'Careers page', 'years' => 5, 'expected' => 170000, 'priority' => '2', 'background' => 'Five years as a yard planner at an ICD: container terminal operations, TOS, safety.'],
                ['name' => 'Ghulam Abbas', 'job' => 'yard-operations-supervisor', 'flow' => 'refused', 'refuse' => 'Does not fit the job requirements', 'applied_days_ago' => 12, 'source' => 'Indeed', 'years' => 2, 'expected' => 120000, 'priority' => '0', 'background' => 'Two years as a warehouse supervisor at a retail chain.'],
                ['name' => 'Fawad Hussain', 'job' => 'truck-dispatcher', 'flow' => 'first_interview_upcoming', 'applied_days_ago' => 8, 'source' => 'WhatsApp', 'years' => 3, 'expected' => 85000, 'priority' => '2', 'background' => 'Three years dispatching trucks up-country for a goods transport company. GPS tracking, Excel trip logs.'],
                ['name' => 'Aqsa Riaz', 'job' => 'truck-dispatcher', 'flow' => 'qualified', 'applied_days_ago' => 6, 'source' => 'Indeed', 'years' => 1, 'expected' => 70000, 'priority' => '1', 'background' => 'One year in trucking operations support: bookings, dispatch sheets, Excel.'],
                ['name' => 'Bilawal Khan', 'job' => 'truck-dispatcher', 'flow' => 'new', 'applied_days_ago' => 2, 'source' => 'WhatsApp', 'years' => 2, 'expected' => 75000, 'priority' => '0', 'background' => 'Two years as a dispatcher at a courier company.'],
                ['name' => 'Shazia Parveen', 'job' => 'truck-dispatcher', 'flow' => 'phone_screen_today', 'applied_days_ago' => 5, 'source' => 'Careers page', 'years' => 2, 'expected' => 78000, 'priority' => '1', 'background' => 'Two years of trucking dispatch with GPS tracking for a cement company.'],
                ['name' => 'Hassan Raza', 'job' => 'billing-executive', 'flow' => 'first_interview_upcoming', 'applied_days_ago' => 7, 'source' => 'LinkedIn', 'years' => 3, 'expected' => 120000, 'priority' => '2', 'background' => 'Three years in billing and receivables at a shipping line agency: invoicing, sales tax, Excel reconciliations.'],
                ['name' => 'Iqra Saleem', 'job' => 'billing-executive', 'flow' => 'new', 'applied_days_ago' => 3, 'source' => 'LinkedIn', 'years' => 2, 'expected' => 100000, 'priority' => '1', 'background' => 'Two years of invoicing and receivables follow-up. Excel.'],
                ['name' => 'Moiz Ahmed', 'job' => 'billing-executive', 'flow' => 'refused', 'refuse' => 'Refused by applicant: job fit', 'applied_days_ago' => 9, 'source' => 'Indeed', 'years' => 4, 'expected' => 140000, 'priority' => '1', 'background' => 'Four years in billing, sales tax returns and receivables.'],
            ],
        ],
    ];

    /** @var array<string, array{prefixes: array<int, string>, areas: array<int, array{0: string, 1: string}>, city: string, landline: string}> */
    public const CITIES = [
        'karachi' => [
            'city'     => 'Karachi',
            'landline' => '21',
            'prefixes' => ['42101', '42201', '42301', '42401', '42501'],
            'areas'    => [
                ['House B-117, Block N, North Nazimabad', '74700'],
                ['Flat 304, Saima Towers, Block 5, Clifton', '75600'],
                ['House 42, Street 9, Block 13-D, Gulshan-e-Iqbal', '75300'],
                ['House 18, Khayaban-e-Shamsheer, DHA Phase 6', '75500'],
                ['House 7, Block 2, PECHS', '75400'],
                ['Flat A-12, Gulistan-e-Jauhar Block 15', '75290'],
                ['House 210, Sector 11-B, North Karachi', '75850'],
                ['House 63, Block 7, Federal B Area', '75950'],
                ['House C-45, Sector 5, Korangi', '74900'],
                ['Flat 9, Mehran Heights, Malir Cantt', '75070'],
            ],
        ],
        'lahore' => [
            'city'     => 'Lahore',
            'landline' => '42',
            'prefixes' => ['35202', '35201'],
            'areas'    => [
                ['House 45, Block J, Johar Town', '54782'],
                ['House 12, Street 3, Model Town Extension', '54700'],
                ['House 88, Sector C, DHA Phase 5', '54792'],
            ],
        ],
        'islamabad' => [
            'city'     => 'Islamabad',
            'landline' => '51',
            'prefixes' => ['61101'],
            'areas'    => [
                ['House 21, Street 7, F-8/3', '44000'],
                ['House 315, Street 41, G-11/2', '44000'],
                ['House 9, Street 12, E-11/4', '44000'],
            ],
        ],
    ];

    /** @var array<int, string> */
    public const MALE_RELATIVES = ['Muhammad Aslam', 'Abdul Rasheed', 'Ghulam Mustafa', 'Shaukat Ali', 'Nasir Mehmood', 'Javed Iqbal', 'Rafiq Ahmed', 'Zahid Hussain'];

    /** @var array<int, string> */
    public const FEMALE_RELATIVES = ['Shahnaz Begum', 'Rukhsana Bibi', 'Nasreen Akhtar', 'Farzana Kausar', 'Samina Yasmin', 'Tahira Parveen'];

    /** @var array<int, string> */
    public const BLOOD_GROUPS = ['A+', 'B+', 'O+', 'AB+', 'A-', 'B-', 'O-'];
}
