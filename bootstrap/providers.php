<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SandboxClockServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    SandboxClockServiceProvider::class,
];
