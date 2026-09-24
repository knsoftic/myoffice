<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventListenerServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    EventListenerServiceProvider::class,
    // phase-24-25 6.3.1. Every named limiter in one file, because a limit is a policy and a
    // policy spread across seventeen route files is a policy nobody can read.
    RateLimitServiceProvider::class,
];
