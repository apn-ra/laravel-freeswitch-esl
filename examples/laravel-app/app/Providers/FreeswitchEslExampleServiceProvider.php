<?php

namespace App\Providers;

use ApnTalk\LaravelFreeswitchEsl\Events\MetricsRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class FreeswitchEslExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(MetricsRecorded::class, function (MetricsRecorded $event): void {
            Log::info('Example FreeSwitch ESL metric observed', [
                'metric_name' => $event->name,
                'metric_type' => $event->type,
                'metric_value' => $event->value,
                'metric_tags' => $event->tags,
            ]);
        });
    }
}
