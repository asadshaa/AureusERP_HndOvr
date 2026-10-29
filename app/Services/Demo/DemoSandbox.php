<?php

namespace App\Services\Demo;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Keeps the demo seeder from reaching anybody: queued work runs in-process,
 * mail is captured (never sent), outgoing HTTP is refused, and notifications
 * are delivered only to the bell (database channel) of demo users
 * (@shipline.demo / @openport.demo). Real users -- the CEO, Truck It In's HR
 * people who also belong to ShipLine and OpenPort -- receive nothing.
 */
class DemoSandbox
{
    /** @var array<int, string> */
    public const DEMO_DOMAINS = ['shipline.demo', 'openport.demo'];

    private bool $isolated = false;

    public static function isDemoEmail(?string $email): bool
    {
        return $email !== null && in_array(Str::lower(Str::after($email, '@')), self::DEMO_DOMAINS, true);
    }

    public function isolate(): void
    {
        if ($this->isolated) {
            return;
        }

        config([
            'queue.default'                   => 'sync',
            'mail.default'                    => 'array',
            'broadcasting.default'            => 'null',
            'recruitment.cv_analysis.auto'    => false,
        ]);

        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();
        Mail::fake();

        Http::preventStrayRequests();

        Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            $email = $event->notifiable->email ?? null;

            return $event->channel === 'database' && self::isDemoEmail(is_string($email) ? $email : null) ? null : false;
        });

        $this->isolated = true;
    }
}
