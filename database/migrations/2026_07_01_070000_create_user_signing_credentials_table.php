<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_signing_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('p12_path', 500);
            $table->string('p12_hash', 64);
            $table->string('esig_path', 500)->nullable();
            $table->string('esig_hash', 64)->nullable();
            $table->string('certificate_subject', 500)->nullable();
            $table->string('certificate_serial', 255)->nullable();
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signing_credentials');
    }
};
