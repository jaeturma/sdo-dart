<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_routings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('routed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('document_routing_recipients', function (Blueprint $table) {
            // Links a recipient row back to the onward routing that closed it
            // out as a side effect (a division chief's own assignment is
            // auto-completed when they route it to their staff — see
            // DocumentRoutingController::store()). Lets cancelling that
            // onward routing precisely revert only the row it closed, instead
            // of guessing from timestamps.
            $table->foreignId('auto_completed_by_routing_id')->nullable()->after('completed_by')
                ->constrained('document_routings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_routing_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_completed_by_routing_id');
        });

        Schema::table('document_routings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
