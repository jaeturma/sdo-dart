<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Services\ControlNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ControlNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_format_and_series_matches_expected_shape(): void
    {
        $clmd = Division::create(['name' => 'Curriculum & Learning Management Division', 'code' => 'CLMD']);

        $number = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 7, 15));

        $this->assertSame('OUTGOING-CLMD-2026-07-001', $number);
    }

    public function test_incoming_format_and_series_matches_expected_shape(): void
    {
        $number = ControlNumberGenerator::forIncoming(Carbon::create(2026, 7, 15));

        $this->assertSame('INCOMING-2026-07-001', $number);
    }

    public function test_outgoing_falls_back_to_parent_code_for_ord_sub_units(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $legalUnit = Division::create(['name' => 'Legal Unit', 'code' => null, 'parent_id' => $ord->id]);

        $number = ControlNumberGenerator::forOutgoing($legalUnit, Carbon::create(2026, 7, 1));

        $this->assertSame('OUTGOING-ORD-2026-07-001', $number);
    }

    public function test_outgoing_falls_back_to_parent_code_for_administrative_division_sub_units(): void
    {
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $procurement = Division::create(['name' => 'Procurement Unit', 'code' => null, 'parent_id' => $ad->id]);

        $number = ControlNumberGenerator::forOutgoing($procurement, Carbon::create(2026, 7, 1));

        $this->assertSame('OUTGOING-AD-2026-07-001', $number);
    }

    public function test_outgoing_uses_gen_fallback_when_neither_division_nor_parent_has_a_code(): void
    {
        $orphan = Division::create(['name' => 'Standalone Division', 'code' => null]);

        $number = ControlNumberGenerator::forOutgoing($orphan, Carbon::create(2026, 7, 1));

        $this->assertSame('OUTGOING-GEN-2026-07-001', $number);
    }

    public function test_outgoing_uses_gen_fallback_when_division_is_null(): void
    {
        $number = ControlNumberGenerator::forOutgoing(null, Carbon::create(2026, 7, 1));

        $this->assertSame('OUTGOING-GEN-2026-07-001', $number);
    }

    public function test_outgoing_series_continues_across_months_within_the_same_year(): void
    {
        $clmd = Division::create(['name' => 'CLMD', 'code' => 'CLMD']);

        $jan = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 1, 5));
        $jul = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 7, 20));

        $this->assertSame('OUTGOING-CLMD-2026-01-001', $jan);
        $this->assertSame('OUTGOING-CLMD-2026-07-002', $jul);
    }

    public function test_outgoing_series_resets_when_year_changes(): void
    {
        $clmd = Division::create(['name' => 'CLMD', 'code' => 'CLMD']);

        $y2026 = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 12, 30));
        $y2027 = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2027, 1, 2));

        $this->assertSame('OUTGOING-CLMD-2026-12-001', $y2026);
        $this->assertSame('OUTGOING-CLMD-2027-01-001', $y2027);
    }

    public function test_incoming_series_continues_across_months_and_resets_annually(): void
    {
        $jan = ControlNumberGenerator::forIncoming(Carbon::create(2026, 1, 5));
        $jul = ControlNumberGenerator::forIncoming(Carbon::create(2026, 7, 20));
        $nextYear = ControlNumberGenerator::forIncoming(Carbon::create(2027, 1, 2));

        $this->assertSame('INCOMING-2026-01-001', $jan);
        $this->assertSame('INCOMING-2026-07-002', $jul);
        $this->assertSame('INCOMING-2027-01-001', $nextYear);
    }

    public function test_counters_are_isolated_per_scope(): void
    {
        $clmd = Division::create(['name' => 'CLMD', 'code' => 'CLMD']);
        $essd = Division::create(['name' => 'ESSD', 'code' => 'ESSD']);

        $clmd1 = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 7, 1));
        $essd1 = ControlNumberGenerator::forOutgoing($essd, Carbon::create(2026, 7, 1));
        $incoming1 = ControlNumberGenerator::forIncoming(Carbon::create(2026, 7, 1));
        $clmd2 = ControlNumberGenerator::forOutgoing($clmd, Carbon::create(2026, 7, 2));

        $this->assertSame('OUTGOING-CLMD-2026-07-001', $clmd1);
        $this->assertSame('OUTGOING-ESSD-2026-07-001', $essd1);
        $this->assertSame('INCOMING-2026-07-001', $incoming1);
        $this->assertSame('OUTGOING-CLMD-2026-07-002', $clmd2);
    }
}
