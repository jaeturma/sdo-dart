<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_routing_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('document_routings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 50);
            $table->string('action_other', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('response_remarks')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routing_recipients');
    }
};
