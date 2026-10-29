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
        // Free job-board feeds served by the careers site ({board} = jooble, talent, recruitnet, expertini, tiptopjob, generic).
        'feed_path'           => '/careers/feed/{board}.xml',
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

    /*
    |--------------------------------------------------------------------------
    | Recruitment emails (queued)
    |--------------------------------------------------------------------------
    |
    | Candidate and interviewer emails are queued (a queue worker must run)
    | and sent from the company, not from the recruiter's personal address:
    | "sender" = company (the company's email, else the app's MAIL_FROM),
    | app (always MAIL_FROM) or user (the recruiter, as before). Replies go to
    | the recruiter. "from_address" overrides the address for every company.
    | "confirm_sources": intake sources that send the "application received"
    | email automatically (bulk imports from job boards do not).
    |
    */

    'mail' => [
        'sender'          => env('RECRUITMENT_MAIL_SENDER', 'company'),
        'from_address'    => env('RECRUITMENT_MAIL_FROM'),
        'confirm_sources' => ['company_website'],
        'queue'           => env('RECRUITMENT_MAIL_QUEUE'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Interviews (Stage 2.3)
    |--------------------------------------------------------------------------
    |
    | Interview times are stored in UTC and entered/shown in "timezone"
    | (default Asia/Karachi; all three companies are in Pakistan). recruitments:interview-reminders
    | (hourly) emails the candidate and interviewers "reminder_hours" before
    | an interview, once, and asks each interviewer for feedback once the
    | interview has ended.
    |
    */

    'interviews' => [
        'timezone'       => env('RECRUITMENT_INTERVIEW_TIMEZONE', 'Asia/Karachi'),
        'reminder_hours' => (int) env('RECRUITMENT_INTERVIEW_REMINDER_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job offers (HR parity 3.6)
    |--------------------------------------------------------------------------
    |
    | An offer is approved (request type "job_offer": hiring manager, then HR,
    | then the CEO only when the monthly gross salary EXCEEDS the company's
    | threshold) before HR sends the letter. "ceo_approval_threshold" is the
    | default threshold; each company can set its own on Recruitment >
    | Configurations > Offer letter templates. "ceo_approver_email" names the
    | CEO approver put on new workflows (else the group owner, else the
    | employee titled Chief Executive Officer, else the Admin role).
    | Dates (joining, expiry: the accept/decline link works until the end of
    | the expiry date) are in "timezone". recruitments:expire-offers (daily)
    | expires offers past their expiry date.
    |
    */

    'offers' => [
        'ceo_approval_threshold' => (float) env('RECRUITMENT_OFFER_CEO_THRESHOLD', 300000),
        'ceo_approver_email'     => env('RECRUITMENT_OFFER_CEO_EMAIL'),
        'currency'               => 'PKR',
        'timezone'               => env('RECRUITMENT_OFFER_TIMEZONE', 'Asia/Karachi'),
        'default_valid_days'     => 7,
        'default_probation'      => 3,
        'default_working_hours'  => 'Monday to Friday, 9:00 AM to 6:00 PM (one hour break)',
        'disk'                   => 'local',
    ],

];
