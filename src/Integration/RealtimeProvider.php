<?php

namespace ErnestDefoe\SpoilerShield\Integration;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Realtime\Push\Payload\Generator;

/** Registered only while flarum/realtime is enabled: its public channel gets RealtimePublicPayload. */
class RealtimeProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(Generator::class, RealtimePublicPayload::class);
    }
}
