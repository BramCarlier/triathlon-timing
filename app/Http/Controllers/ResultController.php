<?php

namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Models\Race;
use App\Services\ResultsService;
use App\Support\SpreadsheetText;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
            'results' => $results->filtered($race,$this->filters($request)),
            'filters' => $this->filters($request),
            'categories' => $race->entries()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function publish(Request $request, Race $race): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('manage-race',$race);
        abort_unless($request->user()->isAdmin(), 403);
        $data=$request->validate(['published'=>['required','boolean']]);
        $race->update(['results_published_at'=>$data['published']?now():null,'public_results_token'=>$data['published']?($race->public_results_token??(string)\Illuminate\Support\Str::uuid()):null]);
        return back()->with('success',$data['published']?__('Public leaderboard published. Anyone with the link can view participant names and results.'):__('Public leaderboard unpublished. The old link no longer works.'));
    }

    public function publicIndex(Request $request,string $token, ResultsService $results): \Symfony\Component\HttpFoundation\Response
    {
        $race=Race::where('public_results_token',$token)->whereNotNull('results_published_at')->firstOrFail();
        $race->load('checkpoints');
        return Inertia::render('Results/Index',[
            'publicMode'=>true,
            'race'=>array_merge($race->only(['id','name','event_date','timezone','status','started_at','finished_at']),['settings'=>collect($race->settings??[])->only(['swim_km','bike_km','run_km'])->all(),'checkpoints'=>$race->checkpoints->map(fn($cp)=>$cp->only(['id','name','sequence','kind','discipline','distance_km']))]),
            'results'=>$results->filtered($race,$this->filters($request)),
            'filters'=>$this->filters($request),
            'categories'=>$race->entries()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ])->toResponse($request)->withHeaders(['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }

    public function csv(Request $request, Race $race, ResultsService $results): StreamedResponse
    {
        Gate::authorize('manage-race', $race);
        $rows = $results->filtered($race,$this->filters($request));
        $checkpoints = $race->checkpoints()
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->orderBy('sequence')
            ->get();

        $headers = array_map('__', ['Place', 'Bib', 'Type', 'Name', 'Category', 'Status']);
        foreach ($checkpoints as $checkpoint) {
            $headers[] = __(':checkpoint elapsed ms', ['checkpoint'=>__($checkpoint->name)]);
            $headers[] = __(':checkpoint split ms', ['checkpoint'=>__($checkpoint->name)]);
        }
        $headers = [...$headers, __('Finished'), __('Total ms')];

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_map([SpreadsheetText::class, 'csv'], $headers));

            foreach ($rows as $row) {
                $values = [$row['place'], $row['bib_number'], __($row['type']), $row['name'], $row['category'], __($row['result_status'])];
                foreach ($row['splits'] as $split) {
                    $values[] = $split['elapsed_ms'];
                    $values[] = $split['split_ms'];
                }
                $values[] = $row['finished'] ? __('yes') : __('no');
                $values[] = $row['total_ms'];
                fputcsv($out, array_map([SpreadsheetText::class, 'csv'], $values));
            }

            fclose($out);
        }, $race->slug.'-results.csv', ['Content-Type' => 'text/csv']);
    }

    public function xlsx(Request $request, Race $race, ResultsService $results): BinaryFileResponse
    {
        Gate::authorize('manage-race', $race);
        $rows = $results->filtered($race,$this->filters($request));
        $checkpoints = $race->checkpoints()
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->orderBy('sequence')
            ->get();

        $headers = array_map('__', ['Place', 'Bib', 'Type', 'Name', 'Category', 'Status']);
        foreach ($checkpoints as $checkpoint) {
            $headers[] = __(':checkpoint elapsed ms', ['checkpoint'=>__($checkpoint->name)]);
            $headers[] = __(':checkpoint split ms', ['checkpoint'=>__($checkpoint->name)]);
        }
        $headers = [...$headers, __('Finished'), __('Total ms')];

        $spreadsheet = new Spreadsheet();
        $active = $spreadsheet->getActiveSheet();
        $active->setTitle(__('Results'));
        foreach ($headers as $index => $header) $active->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        $rowNumber = 2;

        foreach ($rows as $result) {
            $values = [$result['place'], $result['bib_number'], __($result['type']), $result['name'], $result['category'], __($result['result_status'])];
            foreach ($result['splits'] as $split) {
                $values[] = $split['elapsed_ms'];
                $values[] = $split['split_ms'];
            }
            $values[] = $result['finished'] ? __('yes') : __('no');
            $values[] = $result['total_ms'];
            foreach ($values as $index => $value) {
                if (is_string($value)) $active->setCellValueExplicit([$index + 1, $rowNumber], $value, DataType::TYPE_STRING);
                else $active->setCellValue([$index + 1, $rowNumber], $value);
            }
            $rowNumber++;
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

    private function filters(Request $request): array
    {
        return $request->validate(['type'=>['nullable','in:solo,relay'],'category'=>['nullable','string','max:100'],'status'=>['nullable','in:FINISHED,IN PROGRESS,DNS,DNF,DSQ']]);
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
