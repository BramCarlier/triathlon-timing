<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $race->name }} - {{ __('Complete results') }}</title>
    <style>
        @page { margin: 22pt 20pt 38pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; line-height: 1.2; color: #162b3a; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        p { margin: 0 0 5pt; }
        .meta, .detail { color: #52616e; }
        .detail { font-size: 7pt; margin-top: 2pt; }
        .intro { margin-bottom: 12pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 6pt 4pt; vertical-align: top; text-align: left; word-wrap: break-word; }
        th { background: #e9eff4; border-bottom: 1pt solid #9aabba; font-size: 7pt; }
        td { border-bottom: 0.5pt solid #dce3e9; }
        tbody tr:nth-child(even) { background: #f6f8fa; }
        .time { white-space: nowrap; font-size: 7.5pt; }
        .place { font-weight: bold; }
    </style>
</head>
<body>
<div class="intro">
    <h1>{{ $race->name }}</h1>
    <p>{{ __('Complete results') }} | {{ $race->event_date->format('d/m/Y') }} | {{ __($race->finished_at ? 'Race closed' : ($race->started_at ? 'Live results' : 'Awaiting start')) }}</p>
    <p class="meta">{{ __('Generated :time', ['time' => $generatedAt]) }} | {{ __('Participants: :count', ['count' => count($rows)]) }}</p>
    <p class="detail">{{ __('All participants and checkpoints. Times include milliseconds; checkpoint places use the complete field.') }}</p>
    <p class="detail">{{ __('Corrections may still change results.') }}</p>
</div>
<table>
    <thead><tr>
        <th style="width: {{ 42 / $tableWidth * 100 }}%">{{ __('Place') }}</th>
        <th style="width: {{ 68 / $tableWidth * 100 }}%">{{ __('Bib') }}</th>
        <th style="width: {{ ($tableWidth - 262 - $checkpoints->count() * 82) / $tableWidth * 100 }}%">{{ __('Name') }} / {{ __('Category') }} / {{ __('Status') }}</th>
        <th style="width: {{ 74 / $tableWidth * 100 }}%">{{ __('Total time') }}</th>
        <th style="width: {{ 78 / $tableWidth * 100 }}%">{{ __('Gap to leader') }}</th>
        @foreach ($checkpoints as $checkpoint)
            <th style="width: {{ 82 / $tableWidth * 100 }}%">{{ __($checkpoint->name) }}</th>
        @endforeach
    </tr></thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td class="place">{{ $row['place'] ?? '-' }}</td>
            <td>{{ $row['bib_number'] ?? '-' }}</td>
            <td>
                <strong>{{ $row['name'] }}</strong>
                <div class="detail">{{ __($row['type']) }}@if ($row['category']) | {{ $row['category'] }}@endif | {{ __($row['result_status']) }}</div>
                @if ($row['type'] === 'relay')
                    @foreach ($row['members'] as $member)
                        <div class="detail">{{ __($member['discipline']) }}: {{ $member['name'] }}</div>
                    @endforeach
                @endif
            </td>
            <td class="time"><strong>{{ $duration($row['total_ms']) }}</strong></td>
            <td class="time">{{ $row['gap_ms'] === 0 ? __('Leader') : ($row['gap_ms'] === null ? '-' : '+'.$duration($row['gap_ms'])) }}</td>
            @foreach ($row['splits'] as $split)
                <td>
                    @if ($split['elapsed_ms'] !== null)
                        <div class="time">{{ $duration($split['elapsed_ms']) }}</div>
                        @if ($split['place'] !== null)<div class="detail">{{ __('Place :place', ['place' => $split['place']]) }}</div>@endif
                        <div class="detail">{{ __('split') }}<br><span class="time">{{ $duration($split['split_ms']) }}</span></div>
                    @endif
                </td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ 5 + $checkpoints->count() }}">{{ __('No participants yet. Results will appear here when participants are added.') }}</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
