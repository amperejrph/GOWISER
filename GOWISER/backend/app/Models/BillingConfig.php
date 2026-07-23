<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingConfig extends Model
{
    use HasFactory;

    protected $table = 'billing_config';

    public $timestamps = true;

    protected $fillable = [
        'advance_generation_day',
        'due_date_day',
        'disconnection_day',
        'overdue_day',
        'disconnection_notice',
        'disconnection_fee',
        'pullout_offset',
        'pullout_day',
        // Stored as a fraction (0.1200 = 12%), matching the unit every calculation uses.
        // The admin UI converts to and from a percentage for display.
        'vat_rate',
        'updated_by',
        'created_by'
    ];

    protected $casts = [
        'advance_generation_day' => 'integer',
        'due_date_day' => 'integer',
        'disconnection_day' => 'integer',
        'overdue_day' => 'integer',
        'disconnection_notice' => 'integer',
        'disconnection_fee' => 'decimal:2',
        'pullout_offset' => 'integer',
        'pullout_day' => 'integer',
        'vat_rate' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

