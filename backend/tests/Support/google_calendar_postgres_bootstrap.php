<?php

declare(strict_types=1);
use Tests\Support\GoogleCalendarPostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// This runs before PHPUnit discovers or instantiates the RefreshDatabase test class.
GoogleCalendarPostgresTestGuard::assertProcessIsSafe(dirname(__DIR__, 2));
