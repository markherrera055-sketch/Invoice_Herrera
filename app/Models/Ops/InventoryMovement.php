<?php

namespace App\Models\Ops;

use App\Models\Product;
use App\Models\User;

class InventoryMovement extends OpsModel
{
    public const TYPES = [
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        'adjustment' => 'Adjustment',
        'repair_usage' => 'Used in Repair',
        'repair_return' => 'Returned from Repair',
        'purchase_receipt' => 'Purchase Receipt',
    ];

    protected $table = 'inventory_movements';

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
