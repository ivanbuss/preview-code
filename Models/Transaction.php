<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Transaction
 * @package App\Models
 * @property integer customer_purchase_plan_id
 * @property string uuid
 * @property string state
 * @property integer unit_amount_in_cents
 * @property integer quantity
 * @property string activated_at
 */
class Transaction extends Model
{

    /**
     * @var array
     */
    protected $fillable = [
        'customer_purchase_plan_id',
        'uuid',
        'state',
        'unit_amount_in_cents',
        'quantity',
        'activated_at'
    ];
}
