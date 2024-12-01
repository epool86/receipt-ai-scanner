<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_name',
        'total_amount',
        'receipt_date',
        'image_path',
        'extracted_data',
        'notes'
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'extracted_data' => 'array',
        'total_amount' => 'decimal:2'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}