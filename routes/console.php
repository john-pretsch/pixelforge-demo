<?php

use Illuminate\Support\Facades\Schedule;

// Polling fallback for missed webhooks.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
