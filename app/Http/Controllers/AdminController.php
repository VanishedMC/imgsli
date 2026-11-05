<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\User;
use Illuminate\View\View;

class AdminController extends Controller
{
    /**
     * Admin dashboard: every registered user and every album, including
     * private ones. Admins only (see the `admin` middleware on the route).
     */
    public function index(): View
    {
        return view('admin', [
            'users' => User::orderBy('id')->get(),
            'albums' => Album::with('user')->latest()->get(),
        ]);
    }
}
