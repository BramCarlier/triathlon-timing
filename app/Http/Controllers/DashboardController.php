<?php
namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Race;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if ($request->user()->role === UserRole::Athlete) return redirect()->route('athlete.dashboard');
        return redirect()->route('races.index');
    }
}
