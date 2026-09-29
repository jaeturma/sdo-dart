<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->string('signed_file_path', 500);
            $table->string('signed_file_hash', 64);
            $table->string('certificate_subject', 500)->nullable();
            $table->string('certificate_serial', 255)->nullable();
            $table->unsignedSmallInteger('signature_page')->default(1);
            $table->decimal('sig_x_percent', 6, 3);
            $table->decimal('sig_y_percent', 6, 3);
            $table->decimal('sig_width_percent', 6, 3);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
