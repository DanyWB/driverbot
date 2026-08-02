<?php

namespace App\Console\Commands;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Operations\Services\ReleasePreflightService;
use Illuminate\Console\Command;

class ReleasePreflight extends Command
{
    protected $signature = 'operations:release-preflight
                            {--strict : Treat all production requirements as mandatory}
                            {--json : Render machine-readable JSON}';

    protected $description = 'Validate configuration, dependencies and business data before a release';

    public function handle(ReleasePreflightService $preflight): int
    {
        $strict = (bool) $this->option('strict');
        $checks = $preflight->inspect($strict);
        $passed = collect($checks)->every(
            fn (ReleaseCheck $check): bool => $check->status !== ReleaseCheck::FAILURE,
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'strict' => $strict,
                'checks' => array_map(
                    fn (ReleaseCheck $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Check', 'Status', 'Details'], array_map(
            fn (ReleaseCheck $check): array => [$check->id, strtoupper($check->status), $check->message],
            $checks,
        ));

        $passed
            ? $this->components->info('Release preflight passed.')
            : $this->components->error('Release preflight failed.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
