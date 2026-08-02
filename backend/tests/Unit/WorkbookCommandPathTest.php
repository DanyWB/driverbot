<?php

namespace Tests\Unit;

use App\Console\Commands\ImportPricingWorkbook;
use App\Console\Commands\InspectPricingWorkbook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WorkbookCommandPathTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function commandPaths(): iterable
    {
        foreach ([ImportPricingWorkbook::class, InspectPricingWorkbook::class] as $command) {
            yield $command.' Linux' => [$command, '/srv/drive-phangan/import.xlsx'];
            yield $command.' Windows' => [$command, 'C:\\imports\\drive-phangan.xlsx'];
            yield $command.' UNC' => [$command, '\\\\server\\imports\\drive-phangan.xlsx'];
        }
    }

    #[DataProvider('commandPaths')]
    public function test_absolute_workbook_paths_are_not_rebased(string $commandClass, string $path): void
    {
        $method = new ReflectionMethod($commandClass, 'absolutePath');

        self::assertSame($path, $method->invoke(new $commandClass, $path));
    }
}
