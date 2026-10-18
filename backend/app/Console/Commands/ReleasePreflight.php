<?php

namespace App\Console\Commands;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Operations\Services\ReleasePreflightService;
use Illuminate\Console\Command;

class ReleasePreflight extends Command
{
    /** @var list<string> */
    private const ACCEPTABLE_RISKS = [
        'mail.transport',
        'admin.two_factor',
    ];

    protected $signature = 'operations:release-preflight
                            {--strict : Treat all production requirements as mandatory (the default)}
                            {--accept-risk=* : Explicitly accept a supported release risk for this run}
                            {--risk-reason= : Required reason recorded when a release risk is accepted}
                            {--json : Render machine-readable JSON}';

    protected $description = 'Validate configuration, dependencies and business data before a release';

    public function handle(ReleasePreflightService $preflight): int
    {
        $strict = true;
        $acceptedRisks = $this->acceptedRisks();
        $riskReason = $this->riskReason();

        $unknownRisks = array_values(array_diff($acceptedRisks, self::ACCEPTABLE_RISKS));
        if ($unknownRisks !== []) {
            return $this->invalidRiskOptions('Unsupported release risk IDs: '.implode(', ', $unknownRisks).'.');
        }

        if ($acceptedRisks !== [] && $riskReason === null) {
            return $this->invalidRiskOptions('--risk-reason must be non-empty when --accept-risk is used.');
        }

        if ($acceptedRisks === [] && $riskReason !== null) {
            return $this->invalidRiskOptions('--risk-reason requires at least one --accept-risk option.');
        }

        $checks = array_map(
            fn (ReleaseCheck $check): ReleaseCheck => $this->acceptRisk($check, $acceptedRisks, $riskReason),
            $preflight->inspect($strict),
        );
        $passed = collect($checks)->every(
            fn (ReleaseCheck $check): bool => $check->status !== ReleaseCheck::FAILURE,
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'strict' => $strict,
                'accepted_risks' => $acceptedRisks,
                'risk_reason' => $riskReason,
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

    /** @return list<string> */
    private function acceptedRisks(): array
    {
        $option = $this->option('accept-risk');
        $values = is_array($option) ? $option : [];
        $risks = array_map(
            static fn (mixed $risk): string => trim(is_scalar($risk) ? (string) $risk : ''),
            $values,
        );

        return array_values(array_unique($risks));
    }

    private function riskReason(): ?string
    {
        $option = $this->option('risk-reason');
        $reason = is_scalar($option) ? trim((string) $option) : '';

        return $reason !== '' ? $reason : null;
    }

    /** @param list<string> $acceptedRisks */
    private function acceptRisk(ReleaseCheck $check, array $acceptedRisks, ?string $riskReason): ReleaseCheck
    {
        if ($check->status !== ReleaseCheck::FAILURE || ! in_array($check->id, $acceptedRisks, true)) {
            return $check;
        }

        return new ReleaseCheck(
            $check->id,
            ReleaseCheck::WARNING,
            $check->message.' Accepted release risk: '.$riskReason,
        );
    }

    private function invalidRiskOptions(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'passed' => false,
                'strict' => true,
                'accepted_risks' => [],
                'risk_reason' => null,
                'error' => $message,
                'checks' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $this->components->error($message);
        }

        return self::INVALID;
    }
}
