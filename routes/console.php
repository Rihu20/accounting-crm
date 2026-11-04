<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('crm:daily-checks')->dailyAt('09:00')->timezone('Asia/Kolkata')->withoutOverlapping();
Schedule::command('crm:backup')->dailyAt('02:00')->timezone('Asia/Kolkata')->withoutOverlapping();
