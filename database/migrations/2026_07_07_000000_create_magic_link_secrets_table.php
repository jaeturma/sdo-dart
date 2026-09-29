<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_link_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_secrets');
    }
};
