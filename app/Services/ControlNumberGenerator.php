<?php

namespace App\Services;

use App\Models\Division;
use App\Models\DocumentControlNumberCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates the internal "control number" stamped on every document (both
 * workflows) once, at creation time — it is never regenerated afterwards.
 * In particular, OutgoingDocumentController::updateResubmit() reuses the
 * same document row/id on resubmission and must never call anything here.
 *
 * Formats:
 *   Outgoing: OUTGOING-{DIVISION_CODE}-{YEAR}-{MONTH}-{SERIES}
 *   Incoming: INCOMING-{YEAR}-{MONTH}-{SERIES}
 *
 * The SERIES resets only when the YEAR changes for a given scope — the
 * month embedded in the string is purely descriptive of when the document
 * was created, NOT a counter boundary (confirmed product decision). The
 * counter's scope key is therefore (type[, division code], year) only.
 *
 * Division code resolution: a division's own `code` if set, else its
 * *parent's* `code` — several real sub-units (Legal Unit/Public Affairs
 * Unit/ICT Unit under OSDS; Procurement Unit/Personnel Section/Cash Section
 * etc. under Administrative Division) were seeded with no `code` of their
 * own (see CLAUDE.md "Divisions", DivisionSeeder). Falls back to a literal
 * 'GEN' if neither has one — no currently-seeded division should hit that
 * branch, it exists purely as a defensive floor.
 *
 * Atomicity: on MySQL (this app's real deployment target) a single upsert
 * using LAST_INSERT_ID(expr) both increments and reads back the new value
 * in one round trip, race-safe under concurrent submissions with no
 * explicit transaction/lockForUpdate() needed. The test suite runs on an
 * in-memory SQLite connection (phpunit.xml), which has no equivalent
 * expression, so a transaction + lockForUpdate() fallback is used there.
 * Both paths mutate the same document_control_number_counters row per
 * scope, so live document creation and the backfill command (which calls
 * next() the same way) can never collide or produce duplicate numbers.
 */
class ControlNumberGenerator
{
    public static function forOutgoing(?Division $division, ?Carbon $at = null): string
    {
        $at ??= now();
        $code = self::resolveDivisionCode($division);
        $series = self::next(self::scopeForOutgoing($code, (int) $at->format('Y')));

        return self::formatOutgoing($code, (int) $at->format('Y'), (int) $at->format('m'), $series);
    }

    public static function forIncoming(?Carbon $at = null): string
    {
        $at ??= now();
        $series = self::next(self::scopeForIncoming((int) $at->format('Y')));

        return self::formatIncoming((int) $at->format('Y'), (int) $at->format('m'), $series);
    }

    /**
     * Public so the backfill command can resolve the exact same code a live
     * document of that division would get, for grouping purposes.
     */
    public static function resolveDivisionCode(?Division $division): string
    {
        return $division?->code ?? $division?->parent?->code ?? 'GEN';
    }

    public static function scopeForOutgoing(string $divisionCode, int $year): string
    {
        return "outgoing:{$divisionCode}:{$year}";
    }

    public static function scopeForIncoming(int $year): string
    {
        return "incoming:{$year}";
    }

    public static function formatOutgoing(string $divisionCode, int $year, int $month, int $series): string
    {
        return sprintf('OUTGOING-%s-%d-%02d-%03d', $divisionCode, $year, $month, $series);
    }

    public static function formatIncoming(int $year, int $month, int $series): string
    {
        return sprintf('INCOMING-%d-%02d-%03d', $year, $month, $series);
    }

    /**
     * Current last-assigned series number for a scope, WITHOUT incrementing
     * it. Read-only — used only by the backfill command's dry-run preview so
     * running a dry-run never itself consumes series numbers.
     */
    public static function peek(string $scope): int
    {
        return (int) (DocumentControlNumberCounter::where('scope', $scope)->value('last_number') ?? 0);
    }

    /**
     * Atomically increments and returns the next series number for a scope.
     * Used identically by live document creation and by the backfill
     * command's --apply pass.
     */
    public static function next(string $scope): int
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // The VALUES clause's initial number is ALSO wrapped in
            // LAST_INSERT_ID(...) — not just the UPDATE clause's expression.
            // Without this, a brand-new scope's very first call is a plain
            // INSERT (no conflicting row to trigger ON DUPLICATE KEY UPDATE),
            // so LAST_INSERT_ID(last_number + 1) in the UPDATE clause never
            // runs, and PDO::lastInsertId() falls back to returning the
            // counter table's own auto-increment `id` instead of the actual
            // last_number value (1) — a real bug that shipped once already:
            // whichever document happened to be the first one processed for
            // a given scope got assigned that unrelated id as its series
            // number, and later collided with a document that legitimately
            // reached that same number through normal counting.
            DB::statement(
                'INSERT INTO document_control_number_counters (scope, last_number, created_at, updated_at)
                 VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW()',
                [$scope]
            );

            return (int) DB::getPdo()->lastInsertId();
        }

        // No LAST_INSERT_ID(expr)-as-update available outside MySQL (only
        // exercised by the sqlite test suite) — explicit lock+increment instead.
        return DB::transaction(function () use ($scope) {
            DocumentControlNumberCounter::firstOrCreate(['scope' => $scope], ['last_number' => 0]);

            $counter = DocumentControlNumberCounter::where('scope', $scope)->lockForUpdate()->first();
            $counter->increment('last_number');

            return $counter->last_number;
        });
    }
}
