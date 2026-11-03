<?php

return [
    'navigation' => [
        'title' => 'Financial Reports',
        'group' => 'Reports',
    ],

    'actions' => [
        'export-excel' => 'Export Excel',
        'export-pdf'   => 'Export PDF',
    ],

    'filters' => [
        'report'                => 'Report',
        'year'                  => 'Year',
        'companies'             => 'Companies',
        'companies-placeholder' => 'All companies',
    ],

    'content' => [
        'issues'       => ':count configuration issue(s) — values may be incomplete',
        'draft-notice' => 'This template is a draft pending Finance review; unmapped lines render as "-".',
        'empty'        => 'Select a report template to render it.',
        // An entity column/line for a company the viewer does not belong to.
        'not-available'        => 'n/a',
        'not-available-column' => 'Not available',
    ],
];
