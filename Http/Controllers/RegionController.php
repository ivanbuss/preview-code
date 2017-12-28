<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\User;
use Carbon\Carbon;
use Faker\Provider\cs_CZ\DateTime;
use App\Models\Regions;
use Illuminate\Support\Facades\Redirect;

class RegionController extends Controller
{
    public function displayRegions() {
		$regions = Regions::all();
		return view('recurly.admin.recurly_regions')->with('regions',$regions);
	}

	public function RegionEdit($region_id) {

		$region = Regions::find($region_id);
		return view('recurly.admin.edit-region')->with('region',$region);

	}


	public function RegionUpdate(Request $request) {

		$region_name = $request->input('region-name');
		$region_id = $request->input('region-id');
		$region_rid = $request->input('region-rid');
		$region_active = $request->input('region-active');
		$active = 0;

		if($region_active=="on") {
			$active=1;
		}

		$region = Regions::find($region_id);
		$region->name = $region_name;
		$region->active = $active;
		$region->rid = $region_rid;

		try {
			$region->update();
			return Redirect::back()->with('success', 'Updated Successfully');
		}
		catch (\Exception $e) {
			$errorMessage = $e->errorInfo[2];
			return Redirect::back()->withErrors([
				'error' => $errorMessage,
			]);
		}

	}


	public function deleteRegion(Request $request, $region_id) {

		$region = Regions::find($region_id);

		try {
			$region->delete();
			return Redirect::back()->with('success', 'Deleted Successfully');
		}
		catch (\Exception $e) {
			
			return Redirect::back()->withErrors([
				'error' => 'There was some error in deletion',
			]);
		}
	}


	public function addRegionPage() {

		return view('recurly.admin.add-region');

	}


	public function insertRegion(Request $request) {

		$region_rid = $request->input('region-rid');
		$region_name = $request->input('region-name');
		$region_active = $request->input('region-active');

		$active = 0;

		if($region_active=="on") {
			$active=1;
		}

		$region = new Regions;
		$region->rid = $region_rid;
		$region->name = $region_name;
		$region->active = $active;

		try {
			$region->save();
			return redirect()->route('manage_regions')->with('success', 'Added Successfully');
		}
		catch (\Exception $e) {
			$errorMessage = $e->errorInfo[2];
			
			return Redirect::back()->withErrors([
				'error' => $errorMessage,
			])->withInput();
		}

		

	}
}
