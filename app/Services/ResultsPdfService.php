<?php

namespace App\Services;

use App\Enums\CheckpointKind;
use App\Models\Race;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ResultsPdfService
{
    public function __construct(private ResultsService $results) {}

    public function download(Race $race): Response
    {
        $checkpoints = $race->checkpoints()->where('kind', '!=', CheckpointKind::Start->value)->get();
        // Always export the complete standings, independently of screen filters or pagination.
        $rows = $this->results->filtered($race);
        // A4 landscape for standard courses; wider landscape sheets keep custom courses intact.
        $width = max(841.89, 416 + $checkpoints->count() * 82);
        $height = 595.28;
        $pdf = new Dompdf(new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]));
        $pdf->setPaper([0, 0, $width, $height]);
        $pdf->loadHtml(view('results.pdf', [
            'race' => $race,
            'checkpoints' => $checkpoints,
            'rows' => $rows,
            'tableWidth' => $width - 40,
            'duration' => self::formatDuration(...),
            'generatedAt' => now()->timezone($race->timezone ?: 'UTC')->format('d/m/Y H:i T'),
        ])->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(20, $height - 24, __('Page :page of :pages', [
            'page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}',
        ]), $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.3, 0.35, 0.4]);

        $filename = (Str::slug($race->slug) ?: 'race').'-results.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public static function formatDuration(?int $milliseconds): string
    {
        if ($milliseconds === null) return '-';

        $milliseconds = max(0, $milliseconds);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3600000),
            intdiv($milliseconds % 3600000, 60000), intdiv($milliseconds % 60000, 1000), $milliseconds % 1000);
    }
}
