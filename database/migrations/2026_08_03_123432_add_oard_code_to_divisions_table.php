<?php

use App\Models\Division;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * "Office of the Assistant Regional Director" was seeded (see
     * DivisionSeeder) as an ORD sub-unit with no code of its own, same as
     * Legal Unit/Public Affairs Unit/ICT Unit. Unlike those, though, OARD has
     * no unit head of its own — its staff report directly to the ARD — and
     * that needs a stable, code-based identifier (matching this codebase's
     * established convention: ORD/ORDP/RS are all identified this way, not
     * by name) for OutgoingDocumentController::initialStatus() to route
     * their uploads straight to ARD review instead of getting stuck at
     * Submitted with no division chief able to act on it.
     *
     * Scoped tightly (exact name + parent's code + currently-NULL own code)
     * so this only ever touches the one specific row it's meant for, not any
     * other similarly-named division that might exist.
     */
    public function up(): void
    {
        // MySQL rejects an UPDATE whose WHERE clause references the same
        // table via a correlated subquery (whereHas('parent', ...) would do
        // exactly that) — resolve the parent id separately first instead.
        $ordId = Division::where('code', 'ORD')->value('id');

        if ($ordId === null) {
            return;
        }

        Division::where('name', 'Office of the Assistant Regional Director')
            ->whereNull('code')
            ->where('parent_id', $ordId)
            ->update(['code' => 'OARD']);
    }

    public function down(): void
    {
        Division::where('code', 'OARD')->update(['code' => null]);
    }
};
