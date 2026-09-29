<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            // Whether the secretary chose the "Digitally signed by {name} /
            // Date: {timestamp}" text block alongside the e-sig image mark
            // (see DocumentSigner::sign()), instead of the plain image-only
            // stamp. Recorded per-signing-session (not per-placement — it's
            // a single choice applied to every placement in that session)
            // purely for audit/record purposes; it has no effect on the
            // actual PKCS#7 cryptographic signature, only its visual stamp.
            $table->boolean('include_signature_details')->default(false)->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->dropColumn('include_signature_details');
        });
    }
};
