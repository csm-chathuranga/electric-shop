<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_no',
        'user_id',
        'destination',
        'transfer_date',
        'total_items',
        'note',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'total_items'   => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
