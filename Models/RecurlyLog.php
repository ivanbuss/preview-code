<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class RecurlyLog extends Model
{

    protected $table = 'recurly_log';
    protected $primaryKey = 'id';

    /**
     * @var array
     */
    protected $fillable = [
        'type',
        'account_code',
        'subscription_id',
        'xml',
    ];
}
