<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['items' => 'array', 'table_closed_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    public function receipt(): array
    {
        return [
            'id' => $this->id,
            'number' => str_pad((string) $this->id, 4, '0', STR_PAD_LEFT),
            'trackingId' => $this->tracking_id,
            'status' => $this->status,
            'items' => $this->items,
            'subtotalCents' => $this->subtotal_cents,
            'packagingCents' => $this->packaging_cents,
            'totalCents' => $this->total_cents,
            'method' => $this->delivery_method,
            'payment' => $this->payment_method,
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }

    public function staffDetails(): array
    {
        return [...$this->receipt(),
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'address' => $this->address,
            'reference' => $this->reference,
            'tableLabel' => $this->table_label,
            'source' => $this->source ?? 'web',
            'revision' => (int) $this->revision,
            'tableClosedAt' => $this->table_closed_at?->toIso8601String(),
        ];
    }
}
