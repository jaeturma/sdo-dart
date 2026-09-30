<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRouting extends Model
{
    protected $fillable = [
        'document_id',
        'routed_by',
        'is_rush',
        'remarks',
        'routed_at',
        'is_sds_routing',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'is_rush'       => 'boolean',
            'routed_at'     => 'datetime',
            'is_sds_routing' => 'boolean',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function routedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'routed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DocumentRoutingRecipient::class, 'routing_id');
    }
}
