<?php
namespace App\Services;

use App\Support\RaceBroadcast;

use App\Enums\CheckpointKind;
use App\Enums\TimingSource;
use App\Enums\TimingStatus;
use App\Events\TimingRecorded;
use App\Events\TimingVoided;
use App\Exceptions\TimingConflictException;
use App\Exceptions\TimingWarningException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TimingService
{
    public function record(Race $race, Entry $entry, Checkpoint $checkpoint, User $operator, array $data): TimingRecord
    {
        if ($entry->race_id !== $race->id || $checkpoint->race_id !== $race->id) abort(404);
        if (!$race->started_at) throw new TimingConflictException('The race clock has not started.');
        if (!$checkpoint->is_active || $checkpoint->kind === CheckpointKind::Start) throw new TimingConflictException('This checkpoint cannot record timings.');

        $source = TimingSource::tryFrom((string) ($data['source'] ?? 'online')) ?? TimingSource::Online;
        $uuid = (string) $data['client_uuid'];


        $timing = DB::transaction(function () use ($race, $entry, $checkpoint, $operator, $data, $uuid, $source) {
            Entry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($existing = TimingRecord::where('client_uuid', $uuid)->first()) {
                if ($existing->race_id !== $race->id || $existing->entry_id !== $entry->id || $existing->checkpoint_id !== $checkpoint->id || $existing->operator_id !== $operator->id) {
                    throw new TimingConflictException('This timing request identifier is already in use.');
                }
                return $existing;
            }
            $active = TimingRecord::query()
                ->where('entry_id', $entry->id)
                ->where('checkpoint_id', $checkpoint->id)
                ->where('status', TimingStatus::Recorded->value)
                ->first();
            if ($active) throw new TimingConflictException('This participant is already recorded at this checkpoint.');

            $priorRequired = Checkpoint::query()
                ->where('race_id', $race->id)
                ->where('sequence', '<', $checkpoint->sequence)
                ->where('is_required', true)
                ->where('is_active', true)
                ->where('kind', '!=', CheckpointKind::Start->value)
                ->pluck('id');
            $recordedPrior = TimingRecord::query()
                ->where('entry_id', $entry->id)
                ->whereIn('checkpoint_id', $priorRequired)
                ->where('status', TimingStatus::Recorded->value)
                ->pluck('checkpoint_id');
            $missingIds = $priorRequired->diff($recordedPrior)->values();
            if ($missingIds->isNotEmpty() && empty($data['override_warning'])) {
                $missing = Checkpoint::whereIn('id', $missingIds)->orderBy('sequence')->pluck('name')->all();
                throw new TimingWarningException('One or more earlier required checkpoints are missing.', ['missing_checkpoints' => $missing]);
            }

            $serverNow = CarbonImmutable::now('UTC');
            $observed = !empty($data['observed_at']) ? CarbonImmutable::parse($data['observed_at'])->utc() : $serverNow;
            if ($observed->greaterThan($serverNow->addSeconds(10)) && $source !== TimingSource::Manual) throw new TimingConflictException('The device clock is too far ahead of the server clock.');
            $started = CarbonImmutable::instance($race->started_at)->utc();
            if ($observed->lessThan($started)) throw new TimingConflictException('The recorded time is before the race start.');
            if ($race->finished_at && $source !== TimingSource::Manual && $observed->greaterThan(CarbonImmutable::instance($race->finished_at)->utc()->addSeconds(5))) throw new TimingConflictException('The race has already been finished.');
            if ($source === TimingSource::Online && abs($observed->diffInMilliseconds($serverNow, false)) > 10000) $observed = $serverNow;

            $member = $checkpoint->discipline
                ? $entry->members()->where('discipline', $checkpoint->discipline->value)->first()
                : null;
            $elapsed = max(0, $observed->getTimestampMs() - $started->getTimestampMs());

            return TimingRecord::create([
                'client_uuid' => $uuid,
                'race_id' => $race->id,
                'entry_id' => $entry->id,
                'checkpoint_id' => $checkpoint->id,
                'athlete_id' => $member?->athlete_id,
                'operator_id' => $operator->id,
                'recorded_at' => $observed,
                'elapsed_ms' => $elapsed,
                'source' => $source,
                'status' => TimingStatus::Recorded,
                'warning_acknowledged' => !empty($data['override_warning']),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        RaceBroadcast::dispatch(new TimingRecorded($timing));
        return $timing;
    }

    public function correct(Race $race, Entry $entry, Checkpoint $checkpoint, User $operator, int $elapsedMs, ?string $notes = null): TimingRecord
    {
        if ($entry->race_id !== $race->id || $checkpoint->race_id !== $race->id) abort(404);
        if (!$race->started_at || $checkpoint->kind === CheckpointKind::Start) throw new TimingConflictException('This checkpoint cannot be corrected.');

        $voided = null;
        $timing = DB::transaction(function () use ($race, $entry, $checkpoint, $operator, $elapsedMs, $notes, &$voided) {
            Entry::query()->lockForUpdate()->findOrFail($entry->id);
            $voided = TimingRecord::query()
                ->where('entry_id', $entry->id)
                ->where('checkpoint_id', $checkpoint->id)
                ->where('status', TimingStatus::Recorded->value)
                ->first();
            if ($voided) {
                $voided->forceFill([
                    'status' => TimingStatus::Voided,
                    'voided_at' => now('UTC'),
                    'voided_by' => $operator->id,
                    'notes' => trim(($voided->notes ? $voided->notes."\n" : '').'Replaced by manual correction.'),
                ])->save();
            }

            $started = CarbonImmutable::instance($race->started_at)->utc();
            $recordedAt = $started->addMilliseconds($elapsedMs);
            $member = $checkpoint->discipline
                ? $entry->members()->where('discipline', $checkpoint->discipline->value)->first()
                : null;

            return TimingRecord::create([
                'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'race_id' => $race->id,
                'entry_id' => $entry->id,
                'checkpoint_id' => $checkpoint->id,
                'athlete_id' => $member?->athlete_id,
                'operator_id' => $operator->id,
                'recorded_at' => $recordedAt,
                'elapsed_ms' => $elapsedMs,
                'source' => TimingSource::Manual,
                'status' => TimingStatus::Recorded,
                'warning_acknowledged' => true,
                'notes' => $notes ?: 'Manual correction',
            ]);
        });

        if ($voided) RaceBroadcast::dispatch(new TimingVoided($voided));
        RaceBroadcast::dispatch(new TimingRecorded($timing));
        return $timing;
    }

    public function void(TimingRecord $timing, User $user, ?string $reason = null): TimingRecord
    {
        if ($timing->status === TimingStatus::Voided) return $timing;
        $timing->forceFill(['status' => TimingStatus::Voided, 'voided_at' => now('UTC'), 'voided_by' => $user->id, 'notes' => trim(($timing->notes ? $timing->notes."\n" : '').($reason ?? 'Voided by operator'))])->save();
        RaceBroadcast::dispatch(new TimingVoided($timing));
        return $timing->fresh();
    }
}
