<?php

/**
 * Pakistan federal public holidays 2026, read by
 * Webkul\TimeOff\Services\PublicHolidayImportService
 * (php artisan hr:import-public-holidays 2026, and the "Import Pakistan
 * holidays" action on Time Off > Configurations > Public Holidays).
 *
 * Base list: Cabinet Division circular No. 10-1/2025-Min-II of 19 January
 * 2026 (public and optional holidays for 2026). The moon-dependent dates
 * are the ones the Cabinet Division later notified, which replace the
 * circular's expected dates:
 *  - Eid ul Fitr: notification of 15 March 2026, 20-21 March (the circular
 *    expected 21-23 March; Eid fell on Saturday 21 March);
 *  - Eid ul Adha: notification of 20 May 2026, 26-28 May (the circular
 *    expected 27-29 May; Eid fell on Wednesday 27 May);
 *  - Ashura (9th and 10th Muharram 1448): notification of 22 June 2026,
 *    25-26 June (the circular expected 24-25 June);
 *  - Eid Milad un Nabi (12th Rabi ul Awwal 1448): August 2026 notification,
 *    Wednesday 26 August (the circular expected 25 August).
 * Youm-e-Takbeer (28 May) fell inside the Eid ul Adha holiday, so the import
 * skips it as an existing date.
 *
 * Not included: the "day after Christmas" (26 December, for Christians
 * only), the optional holidays of the circular and the bank-only holidays
 * (1 January, 1 July): none applies to every employee.
 *
 * "tentative" marks a moon-dependent date that was not notified yet; the
 * importer adds "(tentative)" to its name (calendar_leaves has no
 * description). None of the 2026 dates below is tentative any more.
 *
 * Official PDF: https://cabinet.gov.pk/SiteImage/Misc/files/Holidays/2026/Public-Holidays-2026.pdf
 */
return [
    'country' => 'PK',
    'year'    => 2026,
    'source'  => 'Cabinet Division, Government of Pakistan: circular No. 10-1/2025-Min-II of 19 January 2026 and the Eid ul Fitr, Eid ul Adha, Ashura and Eid Milad un Nabi notifications of 2026',

    'holidays' => [
        ['name' => 'Kashmir Day', 'date_from' => '2026-02-05', 'date_to' => '2026-02-05', 'tentative' => false],
        ['name' => 'Eid ul Fitr', 'date_from' => '2026-03-20', 'date_to' => '2026-03-21', 'tentative' => false],
        ['name' => 'Pakistan Day', 'date_from' => '2026-03-23', 'date_to' => '2026-03-23', 'tentative' => false],
        ['name' => 'Labour Day', 'date_from' => '2026-05-01', 'date_to' => '2026-05-01', 'tentative' => false],
        ['name' => 'Eid ul Adha', 'date_from' => '2026-05-26', 'date_to' => '2026-05-28', 'tentative' => false],
        ['name' => 'Youm-e-Takbeer', 'date_from' => '2026-05-28', 'date_to' => '2026-05-28', 'tentative' => false],
        ['name' => 'Ashura (9th and 10th Muharram)', 'date_from' => '2026-06-25', 'date_to' => '2026-06-26', 'tentative' => false],
        ['name' => 'Independence Day', 'date_from' => '2026-08-14', 'date_to' => '2026-08-14', 'tentative' => false],
        ['name' => 'Eid Milad un Nabi', 'date_from' => '2026-08-26', 'date_to' => '2026-08-26', 'tentative' => false],
        ['name' => 'Allama Iqbal Day', 'date_from' => '2026-11-09', 'date_to' => '2026-11-09', 'tentative' => false],
        ['name' => 'Quaid-e-Azam Day / Christmas', 'date_from' => '2026-12-25', 'date_to' => '2026-12-25', 'tentative' => false],
    ],
];
