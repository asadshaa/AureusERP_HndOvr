<?php

return [
    'greeting' => 'Hello :name,',

    'candidate' => [
        'invitation' => [
            'subject' => 'Interview invitation: :job at :company',
            'intro'   => 'Thank you for applying for the :job position at :company. We would like to invite you to an interview.',
        ],
        'rescheduled' => [
            'subject' => 'Interview rescheduled: :job at :company',
            'intro'   => 'Your interview for the :job position at :company has changed. The new details are below.',
        ],
        'cancelled' => [
            'subject' => 'Interview cancelled: :job at :company',
            'intro'   => 'Your interview for the :job position on :when has been cancelled. We will be in touch about the next steps.',
        ],
        'reminder' => [
            'subject' => 'Reminder: your interview on :date',
            'intro'   => 'This is a reminder of your interview for the :job position at :company.',
        ],
    ],

    'interviewer' => [
        'invitation' => [
            'subject' => 'Interview: :candidate for :job, :date',
            'intro'   => 'You are an interviewer for :candidate, who applied for :job.',
        ],
        'rescheduled' => [
            'subject' => 'Interview changed: :candidate for :job, :date',
            'intro'   => 'The interview with :candidate (:job) has changed. The new details are below.',
        ],
        'cancelled' => [
            'subject' => 'Interview cancelled: :candidate for :job',
            'intro'   => 'The interview with :candidate (:job) on :when has been cancelled.',
        ],
        'removed' => [
            'subject' => 'You are no longer interviewing :candidate',
            'intro'   => 'You have been taken off the interview with :candidate (:job) on :when.',
        ],
        'reminder' => [
            'subject' => 'Reminder: interview with :candidate, :date',
            'intro'   => 'A reminder that you are interviewing :candidate for :job.',
        ],
        'feedback_request' => [
            'subject' => 'Feedback needed: interview with :candidate',
            'intro'   => 'How did the interview with :candidate (:job) on :when go? Please give your rating and recommendation while it is fresh.',
        ],
    ],

    'details' => [
        'when'         => 'When',
        'duration'     => 'Duration',
        'minutes'      => ':count minutes',
        'mode'         => 'Format',
        'interviewers' => 'Interviewers',
        'organizer'    => 'Organised by',
        'reason'       => 'Reason',
    ],

    'phone_call_you'  => 'We will call you on the phone number in your application.',
    'calendar_add'    => 'The attached invite.ics adds the interview to your calendar.',
    'calendar_update' => 'Open the attached invite.ics to update your calendar.',
    'calendar_cancel' => 'Open the attached cancel.ics to remove the interview from your calendar.',
    'candidate_reply' => 'If the time does not suit you, simply reply to this email.',
    'internal_note'   => 'Internal: do not forward this email to the candidate.',
    'view_applicant'  => 'Open the application',
    'give_feedback'   => 'Give feedback',
];
