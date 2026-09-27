<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Services\ResultsPdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ResultsPdfController extends Controller
{
    public function publicDownload(string $token, ResultsPdfService $pdf): Response
    {
        $race = Race::where('public_results_token', $token)
            ->whereNotNull('results_published_at')->firstOrFail();

        return $pdf->download($race);
    }

    public function download(Race $race, ResultsPdfService $pdf): Response
    {
        Gate::authorize('manage-race', $race);

        return $pdf->download($race);
    }
}
