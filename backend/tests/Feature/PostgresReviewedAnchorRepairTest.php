<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;

/** Existing PostgreSQL CI filter includes real repair/preservation/rollback cases. */
class PostgresReviewedAnchorRepairTest extends ReviewedAnchorRepairTest
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real PostgreSQL repair locks require isolated CI PostgreSQL.');
        }
    }

    public function test_repair_preserved_table_lock_wait_is_bounded_on_real_postgres(): void
    {
        $repair = $this->prepareExistingImport();
        $before = $repair->fingerprint();
        $config = DB::connection()->getConfig();
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec("SET lock_timeout='5000ms'");
        $other->exec("SET statement_timeout='10000ms'");
        $other->beginTransaction();
        try {
            $other->exec('LOCK TABLE vehicle_photos IN ROW EXCLUSIVE MODE');
            $started = microtime(true);
            try {
                $repair->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
                $this->fail('Preserved table writer must prevent repair.');
            } catch (QueryException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
                $this->assertGreaterThanOrEqual(4.0, microtime(true) - $started);
                $this->assertLessThan(12.0, microtime(true) - $started);
            }
        } finally {
            $other->rollBack();
        }
        $this->assertSame($before, $repair->fingerprint());
    }
}
