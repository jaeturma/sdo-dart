<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_release_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stamped_by')->constrained('users')->restrictOnDelete();
            $table->string('stamped_file_path', 500);
            $table->string('stamped_file_hash', 64);
            $table->string('certificate_subject', 500)->nullable();
            $table->string('certificate_serial', 255)->nullable();
            $table->unsignedSmallInteger('stamp_page')->default(1);
            $table->decimal('stamp_x_percent', 6, 3);
            $table->decimal('stamp_y_percent', 6, 3);
            $table->decimal('stamp_width_percent', 6, 3);
            $table->timestamp('stamped_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_release_stamps');
    }
};
