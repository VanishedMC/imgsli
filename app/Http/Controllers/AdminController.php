<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\User;
use Illuminate\View\View;

class AdminController extends Controller
{
    /** Rows per page on the dashboard tables. */
    private const PER_PAGE = 50;

    /**
     * Admin dashboard: every registered user and every album, including
     * private ones. Admins only (see the `admin` middleware on the route).
     * Paginated: an install can have thousands of either.
     */
    public function index(): View
    {
        return view('admin', [
            'users' => User::orderBy('id')->paginate(self::PER_PAGE, ['*'], 'users_page')->onEachSide(1)->withQueryString(),
            'albums' => Album::with('user')->latest()->paginate(self::PER_PAGE, ['*'], 'albums_page')->onEachSide(1)->withQueryString(),
        ]);
    }
}
