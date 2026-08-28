<?php

use App\Providers\AppServiceProvider;
use App\Providers\BackendViewProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    RateLimitServiceProvider::class,
    BackendViewProvider::class,
];
