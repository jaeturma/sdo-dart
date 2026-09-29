<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentControlNumberCounter extends Model
{
    // scope is the actual primary key (string, non-incrementing) — see
    // 2026_07_24_000002_fix_document_control_number_counters_primary_key.php
    protected $primaryKey = 'scope';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scope',
        'last_number',
    ];
}
