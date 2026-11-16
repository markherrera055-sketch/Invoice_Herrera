<?php

namespace App\Models\Ops;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestItem extends Model
{
    protected $table = 'purchase_request_items';

    protected $fillable = ['product_id', 'description', 'quantity', 'unit_cost'];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class);
    }
}
