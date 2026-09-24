<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'hotel_id',
    'provider',
    'environment',
    'endpoint_origin',
    'external_property_id',
    'credentials',
    'inventory_mode',
    'enabled',
    'created_by',
    'capabilities',
    'freshness',
])]
#[Hidden(['credentials'])]
class PmsConnection extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'freshness' => 'array',
            'enabled' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
