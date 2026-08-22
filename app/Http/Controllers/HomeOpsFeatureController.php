<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsFeatureAccess;
use Illuminate\Http\Request;

class HomeOpsFeatureController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['features' => HomeOpsFeatureAccess::resolvedFor($request->user())]);
    }
}
