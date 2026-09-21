<?php

namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Models\Race;
use App\Services\ResultsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultController extends Controller
{
    public function index(Request $request, Race $race, ResultsService $results): Response
    {
        $this->authorizeView($request, $race);

        return Inertia::render('Results/Index', [
            'race' => $race->load('checkpoints'),
            'results' => $results->rows($race),
        ]);
    }

    public function csv(Request $request, Race $race, ResultsService $results): StreamedResponse
    {
        Gate::authorize('manage-race', $race);
        $rows = $results->rows($race);
        $checkpoints = $race->checkpoints()
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->orderBy('sequence')
            ->get();

        $headers = ['Bib', 'Type', 'Name', 'Category'];
        foreach ($checkpoints as $checkpoint) {
            $headers[] = $checkpoint->name.' elapsed ms';
            $headers[] = $checkpoint->name.' split ms';
        }
        $headers = [...$headers, 'Finished', 'Total ms'];

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                $values = [$row['bib_number'], $row['type'], $row['name'], $row['category']];
                foreach ($row['splits'] as $split) {
                    $values[] = $split['elapsed_ms'];
                    $values[] = $split['split_ms'];
                }
                $values[] = $row['finished'] ? 'yes' : 'no';
                $values[] = $row['total_ms'];
                fputcsv($out, $values);
            }

            fclose($out);
        }, $race->slug.'-results.csv', ['Content-Type' => 'text/csv']);
    }

    public function xlsx(Request $request, Race $race, ResultsService $results): BinaryFileResponse
    {
        Gate::authorize('manage-race', $race);
        $rows = $results->rows($race);
        $checkpoints = $race->checkpoints()
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->orderBy('sequence')
            ->get();

        $headers = ['Bib', 'Type', 'Name', 'Category'];
        foreach ($checkpoints as $checkpoint) {
            $headers[] = $checkpoint->name.' elapsed ms';
            $headers[] = $checkpoint->name.' split ms';
        }
        $headers = [...$headers, 'Finished', 'Total ms'];

        $spreadsheet = new Spreadsheet();
        $active = $spreadsheet->getActiveSheet();
        $active->setTitle('Results');
        $active->fromArray($headers, null, 'A1');
        $rowNumber = 2;

        foreach ($rows as $result) {
            $values = [$result['bib_number'], $result['type'], $result['name'], $result['category']];
            foreach ($result['splits'] as $split) {
                $values[] = $split['elapsed_ms'];
                $values[] = $split['split_ms'];
            }
            $values[] = $result['finished'] ? 'yes' : 'no';
            $values[] = $result['total_ms'];
            $active->fromArray($values, null, 'A'.$rowNumber++);
        }

        $active->freezePane('A2');
        $active->setAutoFilter($active->calculateWorksheetDimension());
        foreach ($active->getColumnIterator() as $column) {
            $active->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }

        $path = storage_path('app/private/'.$race->slug.'-results-'.uniqid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $race->slug.'-results.xlsx')->deleteFileAfterSend(true);
    }

    private function authorizeView(Request $request, Race $race): void
    {
        if ($request->user()->role->value === 'athlete') {
            abort_unless(
                $request->user()->athlete?->memberships()
                    ->whereHas('entry', fn ($query) => $query->where('race_id', $race->id))
                    ->exists(),
                403
            );

            return;
        }

        Gate::authorize('manage-race', $race);
    }
}
