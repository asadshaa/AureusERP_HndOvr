<?php

/*
|--------------------------------------------------------------------------
| Appraisal forms (HR Parity 3.2)
|--------------------------------------------------------------------------
|
| hr:appraisal-reminders (daily) reminds whoever has an open self review or
| manager review: once `reminder_days_before` days before the deadline, on
| the first day after it, and again every `overdue_repeat_days` days while
| it stays overdue (0 = only once). The deadlines themselves are set per
| cycle by HR.
|
*/

return [
    'reminder_days_before' => 3,

    'overdue_repeat_days' => 7,
];
