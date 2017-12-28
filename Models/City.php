<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
	protected $table = 'cities';
	protected $primaryKey = 'id';
	protected $fillable = ['cid', 'name', 'active'];


    public static function activeList() {
        $cities =  static::where('active',1)->orderBy('cid', 'asc')->lists('name', 'cid');
        return $cities;
    }
}
