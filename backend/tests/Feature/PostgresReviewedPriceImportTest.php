<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use ReviewedPriceImport;

/** Existing CI Postgres filter runs inherited full import/manual-history/no-op cases on real PG. */
class PostgresReviewedPriceImportTest extends ReviewedPriceImportTest
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real PostgreSQL import and table-lock assertions require CI test PostgreSQL.');
        }
    }

    public function test_preserved_photo_table_writer_is_bounded_by_real_postgres_lock_timeout(): void
    {
        $settings = DB::connection()->getConfig();
        $other = new PDO('pgsql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'], $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $runner = new ReviewedPriceImport;
        $before = $runner->inspect($this->payload, $this->mapping, $this->reference)['fingerprint'];
        $other->beginTransaction();
        try {
            $other->exec('LOCK TABLE vehicle_photos IN ROW EXCLUSIVE MODE');
            $started = microtime(true);
            try {
                $runner->apply($this->payload, $this->mapping, $this->reference, $this->admin->id, $before);
                $this->fail('Concurrent preserved-table writer must prevent import.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
                $this->assertGreaterThanOrEqual(4.0, microtime(true) - $started);
                $this->assertLessThan(12.0, microtime(true) - $started);
            }
        } finally {
            $other->rollBack();
        }
        $this->assertSame($before, $runner->fingerprint());
        $this->assertDatabaseCount('pricing_presets', 0);
    }
}
