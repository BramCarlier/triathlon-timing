<?php
namespace App\Http\Controllers;
use App\Services\OperationalHealth;
use Inertia\Inertia;
class HealthController extends Controller {
    public function __invoke(OperationalHealth $health){return Inertia::render('Admin/Health',['checks'=>$health->checks(),'checkedAt'=>now()->toISOString()]);}
}
