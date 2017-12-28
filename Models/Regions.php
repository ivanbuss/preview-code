<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Regions extends Model
{
	protected $table = 'regions';
	protected $primaryKey='id';
	protected $fillable = ['name','created_at','updated_at'];
	public static function getList()
	{
		$options = static::orderBy('id')->lists('name','id')->all();
		$select = array('' => 'Select Region');
		$output =$select+$options;
		return $output;
	}

	public static function getActivated() 
	{
		$options_obj =  static::where('active',1)->orderBy('rid', 'asc')->select('rid', 'name')->get();

		$options = array(''=>'Select Region');

		foreach ($options_obj as $obj) {
			$options[$obj->rid] = $obj->name;
		}
		
		return $options;
	}
}
