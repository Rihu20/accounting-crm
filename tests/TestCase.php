<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A request handler may cap its own run time (set_time_limit); in one long test process that cap must not carry over to later tests.
        set_time_limit(0);
    }
}
