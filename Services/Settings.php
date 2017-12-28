<?php

namespace App\Services;

use App\Models\SettingsModel;

class Settings {

    public function __construct() {

    }

    protected function create($name, $value) {
        $setting = SettingsModel::create(['name'=>$name, 'value'=>$value]);
        return $setting;
    }

    protected function load($name) {
        return SettingsModel::where('name', $name)->first();
    }

    public function get($name) {
        $setting = $this->load($name);
        if ($setting) return $setting->value;
            else return null;
    }

    public function set($name, $value) {
        $setting = $this->load($name);
        if (!$setting) {
            $setting = $this->create($name, $value);
        } else {
            $setting->value = $value;
            $setting->save();
        }
    }

    public function getAll() {
        return SettingsModel::lists('value', 'name');
    }

}
