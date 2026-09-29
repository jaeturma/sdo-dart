<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recreate with `scope` as the actual primary key, not a separate
        // auto-increment `id` column. This is required for
        // ControlNumberGenerator::next()'s atomic MySQL upsert to work
        // correctly: with a competing auto-increment column present, a
        // fresh INSERT's auto-generated id silently wins over the explicit
        // LAST_INSERT_ID(expr) call in the same statement, so
        // PDO::lastInsertId() returns the wrong value (the counter table's
        // own row id) for a brand-new scope's very first call — a real bug
        // already reproduced in production (documents ended up with
        // colliding control numbers). No auto-increment column at all
        // means there's nothing left to win that race.
        Schema::dropIfExists('document_control_number_counters');

        Schema::create('document_control_number_counters', function (Blueprint $table) {
            $table->string('scope', 40)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_control_number_counters');

        Schema::create('document_control_number_counters', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }
};
