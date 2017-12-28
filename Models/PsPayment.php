<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PsPayment extends Model
{

    protected $table = 'payments';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public $dates = [
        'create_time', 'update_time'
    ];


    /**
     * @var array
     */
    protected $fillable = [
        'user_id',
        'method',
        'payment_id',
        'state',
        'amount',
        'currency',
        'create_time',
        'update_time'
    ];
}
