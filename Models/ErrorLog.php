<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
	protected $table = 'errors_log';
	protected $primaryKey = 'id';
	protected $fillable = ['path', 'code', 'file', 'line', 'message', 'body'];

}
