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
        $query = Race::query()->withCount(['entries', 'checkpoints'])->latest('event_date');
        if (!$request->user()->isAdmin()) $query->whereHas('organizers', fn ($q) => $q->whereKey($request->user()->id));
        return Inertia::render('Dashboard', ['races' => $query->get(), 'serverNow' => now('UTC')->toISOString()]);
    }
}
