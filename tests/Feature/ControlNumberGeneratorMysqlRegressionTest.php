<?php

namespace Tests\Feature;

use App\Services\ControlNumberGenerator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test for a real production bug: ControlNumberGenerator::next()'s
 * atomic-counter SQL only produced the correct value once a scope's counter
 * row already existed. The very FIRST call for a brand-new scope is a plain
 * INSERT (no conflicting row to trigger ON DUPLICATE KEY UPDATE), so MySQL's
 * LAST_INSERT_ID(expr) form inside the UPDATE clause never ran, and
 * PDO::lastInsertId() fell back to returning the counter table's own
 * auto-increment `id` instead of the actual last_number value — producing a
 * wrong series number for that document, which later collided with a
 * different document that legitimately reached that same number through
 * normal counting (SQLSTATE 23000 duplicate key on
 * documents.documents_control_number_unique in production:
 * OUTGOING-AD-2026-07-051, while backfilling 525 historical documents).
 *
 * This class of bug is invisible to the rest of this app's test suite,
 * which runs on in-memory SQLite (phpunit.xml) — next() takes a completely
 * different, unaffected code path there (transaction + lockForUpdate()).
 * This test deliberately targets the real `mysql` connection (the local dev
 * database) to exercise the actual SQL. Everything runs inside a
 * transaction that is always rolled back in tearDown(), so nothing here
 * ever persists against the shared dev database.
 */
class ControlNumberGeneratorMysqlRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension not available in this environment.');
        }

        // phpunit.xml overrides DB_DATABASE to ":memory:" globally (for the
        // sqlite connection the rest of the suite runs against) — that same
        // env var also feeds the 'mysql' connection's own config array, so
        // it has to be pointed back at the real dev database explicitly.
        config(['database.connections.mysql.database' => 'drsdb']);
        DB::purge('mysql');

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL dev database not reachable: '.$e->getMessage());
        }

        DB::connection('mysql')->beginTransaction();
        config(['database.default' => 'mysql']);
    }

    protected function tearDown(): void
    {
        config(['database.default' => 'sqlite']);
        DB::connection('mysql')->rollBack();

        parent::tearDown();
    }

    public function test_first_call_for_a_brand_new_scope_returns_one_even_when_other_counter_rows_already_exist(): void
    {
        // Simulate the production scenario: several OTHER scopes already
        // have counter rows (pushing the counters table's own auto-increment
        // id well past 1) before this brand-new scope is ever touched.
        for ($i = 0; $i < 5; $i++) {
            ControlNumberGenerator::next("outgoing:PRIOR{$i}:2026");
        }

        $series = ControlNumberGenerator::next('outgoing:REGRESSION1:2026');

        $this->assertSame(1, $series, "the first call for a brand-new scope must return 1, not the counters table's own auto-increment id");
    }

    public function test_subsequent_calls_for_the_same_scope_increment_correctly(): void
    {
        $first = ControlNumberGenerator::next('outgoing:REGRESSION2:2026');
        $second = ControlNumberGenerator::next('outgoing:REGRESSION2:2026');
        $third = ControlNumberGenerator::next('outgoing:REGRESSION2:2026');

        $this->assertSame([1, 2, 3], [$first, $second, $third]);
    }

    public function test_many_sequential_calls_across_multiple_scopes_never_produce_a_duplicate_within_a_scope(): void
    {
        $seenA = [];
        $seenB = [];

        for ($i = 0; $i < 60; $i++) {
            $a = ControlNumberGenerator::next('outgoing:REGRESSION3A:2026');
            $this->assertArrayNotHasKey($a, $seenA, "scope A produced series {$a} twice");
            $seenA[$a] = true;

            $b = ControlNumberGenerator::next('outgoing:REGRESSION3B:2026');
            $this->assertArrayNotHasKey($b, $seenB, "scope B produced series {$b} twice");
            $seenB[$b] = true;
        }

        $this->assertCount(60, $seenA);
        $this->assertCount(60, $seenB);
        $this->assertSame(range(1, 60), array_keys($seenA));
        $this->assertSame(range(1, 60), array_keys($seenB));
    }
}
