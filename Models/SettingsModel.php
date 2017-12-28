<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Settings
 * @package App\Models
 * @property string name
 * @property text value
 */
class SettingsModel extends Model {

    protected $table = 'settings';
    protected $primaryKey='id';
    protected $fillable = ['name', 'value'];

}
