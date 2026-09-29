<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user override for RestrictWeekendAccess — disabled by
            // default; RD/admin already bypass the restriction via role
            // regardless of this flag (see the middleware).
            $table->boolean('can_login_weekends')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_login_weekends');
        });
    }
};
