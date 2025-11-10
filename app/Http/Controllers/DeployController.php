<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DeployController extends Controller
{
    /**
     * Display the deploy page (developer only).
     */
    public function index()
    {
        return Inertia::render('Deploy');
    }
}
