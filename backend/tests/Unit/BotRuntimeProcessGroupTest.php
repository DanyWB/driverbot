<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BotRuntimeProcessGroupTest extends TestCase
{
    public function test_timeout_orphan_group_is_killed_without_signalling_unrelated_process(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Own-process-group isolation is verified on real Linux CI.');
        }
        $script = <<<'BASH'
set -Eeuo pipefail
set +m
source "$1"
sleep 30 & unrelated=$!
trap 'kill "$unrelated" 2>/dev/null || true; terminate_installer' EXIT
setsid timeout --foreground --signal=TERM --kill-after=0.2s 1s bash -c 'trap "" TERM; sleep 30 & wait' >/dev/null 2>&1 &
installer_pid=$!
wait "$installer_pid" 2>/dev/null || true
price_group_running || { printf 'fixture did not leave an orphan\n'; exit 1; }
terminate_installer
for attempt in 1 2 3 4 5; do
    if ! price_group_running; then break; fi
    sleep 0.1
done
if price_group_running; then exit 1; fi
kill -0 "$unrelated"
printf 'OWN_GROUP_DEAD_UNRELATED_ALIVE\n'
BASH;
        $process = new Process(['bash', '-c', $script, '_', dirname(__DIR__, 3).'/ops/pricing-20261031/npm-process-group.sh']);
        $process->setTimeout(12);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('OWN_GROUP_DEAD_UNRELATED_ALIVE', $process->getOutput());
    }
}
