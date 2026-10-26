<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Careers page and tracked apply links
    |--------------------------------------------------------------------------
    |
    | Every job ad (posting) gets a tracked apply link so the CV it brings in
    | is attributed to that ad. The public careers page is built in Stage 3:
    | until its route ("recruitments.careers.apply") exists and Aureus is
    | reachable over public HTTPS, links are generated but never put into ad
    | text. base_url falls back to app.url.
    |
    */

    'careers' => [
        'base_url'    => env('RECRUITMENT_CAREERS_URL'),
        'apply_path'  => '/careers/apply/{token}',
        'apply_email' => env('RECRUITMENT_APPLY_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job ad take-down reminders
    |--------------------------------------------------------------------------
    |
    | When a job closes, manual ads (LinkedIn, Rozee.pk, WhatsApp...) must be
    | taken down by hand. recruitments:remind-posting-removals reminds the
    | recruiter after "after_days", then every "every_days", up to "max"
    | reminders, after which HR is told once.
    |
    */

    'postings' => [
        'removal_reminder_after_days' => (int) env('RECRUITMENT_POSTING_REMINDER_AFTER_DAYS', 2),
        'removal_reminder_every_days' => (int) env('RECRUITMENT_POSTING_REMINDER_EVERY_DAYS', 2),
        'removal_reminder_max'        => (int) env('RECRUITMENT_POSTING_REMINDER_MAX', 5),
    ],

];
