<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Enums\RoutingAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRoutingRecipient extends Model
{
    protected $fillable = [
        'routing_id',
        'user_id',
        'division_id',
        'action',
        'action_other',
        'status',
        'response_remarks',
        'acknowledged_at',
        'acknowledged_by',
        'completed_at',
        'completed_by',
        'auto_completed_by_routing_id',
    ];

    protected function casts(): array
    {
        return [
            'action'          => RoutingAction::class,
            'status'          => RecipientStatus::class,
            'acknowledged_at' => 'datetime',
            'completed_at'    => 'datetime',
        ];
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(DocumentRouting::class, 'routing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
