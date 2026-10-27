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
    | text. base_url falls back to app.url. Once the company's careers
    | website is connected and the job is live there, an ad's apply link is
    | the website job page with "?ref={token}" instead (see "website" below).
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

    /*
    |--------------------------------------------------------------------------
    | Company careers website (automation plan phases A3 and A4)
    |--------------------------------------------------------------------------
    |
    | Each company can connect one WordPress site running the "Aureus Careers"
    | plugin (Recruitment > Configurations > Careers website). Aureus only
    | makes outbound HTTPS calls to {base_url}{api_path}: it pushes published
    | jobs there and pulls the applications back every 5 minutes
    | (recruitments:pull-website-applications). Calls time out after
    | "timeout" seconds and are retried after each "retry_delays_ms" pause on
    | connection errors and 5xx answers. A failed application is tried again
    | automatically up to "auto_retry_attempts" times, then only from the
    | Website intake page.
    |
    */

    'website' => [
        'api_path'            => '/wp-json/aureus/v1',
        'timeout'             => (int) env('RECRUITMENT_WEBSITE_TIMEOUT', 10),
        'connect_timeout'     => 5,
        'retry_delays_ms'     => [500, 1500],
        'pull_limit'          => 50,
        'auto_retry_attempts' => 3,
        'indeed_feed_path'    => '/careers/feed/indeed.xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | CV analyser (free, keyword based: automation plan phase A5a)
    |--------------------------------------------------------------------------
    |
    | Reads the text of each applicant's CV and scores it 0-100 against the
    | job's skills and CV keywords, minimum experience and work location. It
    | ranks and flags; it never rejects anyone. "auto" analyses new
    | applications and changed CVs on the queue. PDFs are read with the
    | pdftotext tool when it is installed ("pdftotext_path", or on PATH), else
    | with a built-in reader that handles ordinary text PDFs.
    |
    */

    'cv_analysis' => [
        'enabled'             => (bool) env('RECRUITMENT_CV_ANALYSIS', true),
        'auto'                => (bool) env('RECRUITMENT_CV_ANALYSIS_AUTO', true),
        'pdftotext_path'      => env('RECRUITMENT_PDFTOTEXT_PATH'),
        'max_text_chars'      => 100000,
        'weights'             => ['keywords' => 60, 'experience' => 30, 'location' => 10],
        'remote_only_cap'     => 40,
        'remote_only_phrases' => [
            'remote only', 'only remote', 'remote work only', 'remote roles only', 'remote positions only',
            'remote opportunities only', 'work from home only', 'only work from home', 'wfh only',
            'looking for remote', 'seeking remote', 'seeking a remote', 'looking for a remote',
            'not willing to relocate', 'unwilling to relocate', 'not open to relocation', 'unable to relocate',
            'cannot relocate', 'can not relocate', 'no relocation',
        ],
    ],

];
