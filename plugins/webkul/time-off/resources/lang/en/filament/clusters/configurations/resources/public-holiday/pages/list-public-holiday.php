<?php

return [
    'header-actions' => [
        'create' => [
            'title' => 'New Public Holiday',

            'notification' => [
                'created' => [
                    'title' => 'Public Holiday created',
                    'body'  => 'The public holiday has been created successfully.',
                ],
            ],
        ],

        'import' => [
            'title'             => 'Import Pakistan holidays',
            'modal-heading'     => 'Import the Pakistan federal public holidays',
            'modal-description' => 'Adds the year\'s federal public holidays (Cabinet Division) to the active company. Dates that already are holidays are skipped and nothing is changed or deleted. Moon-dependent dates not yet notified are marked "(tentative)": correct them when the Cabinet Division notifies them.',
            'year'              => 'Year',
            'submit'            => 'Import',

            'notification' => [
                'title'  => 'Pakistan holidays :year imported for :company',
                'body'   => ':created holiday(s) added, :skipped skipped (already holidays).',
                'denied' => 'You cannot add public holidays to this company.',
            ],
        ],
    ],
];
