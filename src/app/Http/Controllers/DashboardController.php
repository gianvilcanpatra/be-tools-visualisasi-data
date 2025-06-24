<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function generateChart()
    {
        return view('generateChart');
    }

    public function dashboardDinamis()
    {
        return view('index');
    }
}
