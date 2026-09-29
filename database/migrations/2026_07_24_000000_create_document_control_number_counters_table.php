<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_control_number_counters', function (Blueprint $table) {
            $table->id();
            // e.g. "outgoing:CLMD:2026", "incoming:2026" — see
            // App\Services\ControlNumberGenerator. Scoped by year only, not
            // month, even though the displayed control number embeds the
            // month too — the month is descriptive, not a reset boundary.
            $table->string('scope', 40)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_control_number_counters');
    }
};
