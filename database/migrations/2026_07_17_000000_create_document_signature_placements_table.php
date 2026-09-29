<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One `document_signatures` row can now have one or more placements —
        // the secretary may stamp the RD's signature more than once on a
        // page, and/or across multiple pages, all backed by the single real
        // PKCS#7 signature already recorded on the parent row (see
        // App\Services\DocumentSigner — one crypto signature, N visual
        // appearances via TCPDF's addEmptySignatureAppearance()).
        Schema::create('document_signature_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_signature_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->decimal('x_percent', 6, 3);
            $table->decimal('y_percent', 6, 3);
            $table->decimal('width_percent', 6, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signature_placements');
    }
};
