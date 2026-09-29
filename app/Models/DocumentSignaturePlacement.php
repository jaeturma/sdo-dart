<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignaturePlacement extends Model
{
    protected $fillable = [
        'document_signature_id',
        'page',
        'x_percent',
        'y_percent',
        'width_percent',
    ];

    public function signature(): BelongsTo
    {
        return $this->belongsTo(DocumentSignature::class, 'document_signature_id');
    }
}
