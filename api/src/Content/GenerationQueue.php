<?php

declare(strict_types=1);

namespace Dana\Content;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Work queue for content generation.
 *
 * The panel enqueues and polls; a CLI worker does the slow part. Claiming
 * is a conditional UPDATE rather than select-then-update, so two workers
 * can never take the same job — the second one's UPDATE simply matches
 * zero rows.
 */
final class GenerationQueue
{
    public function enqueue(int $sectionId, string $target, int $requestedBy, array $params = []): int
    {
        // Don't stack duplicates: if the same work is already waiting or
        // running, hand back the existing job.
        $existing = Capsule::table('generation_runs')
            ->where('unit_section_id', $sectionId)
            ->where('target', $target)
            ->whereIn('status', ['queued', 'running'])
            ->when($params !== [], fn ($q) => $q->where('params', json_encode($params)))
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) Capsule::table('generation_runs')->insertGetId([
            'unit_section_id' => $sectionId,
            'target'          => $target,
            'params'          => $params === [] ? null : json_encode($params),
            'status'          => 'queued',
            'requested_by'    => $requestedBy,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A job older than this in 'running' state is presumed orphaned by a
     * crashed worker. The longest legitimate job — OCR plus five model
     * calls with retries — finishes well inside it.
     */
    private const STALE_MINUTES = 45;

    /** @return object|null the claimed job, or null when the queue is empty */
    public function claim(): ?object
    {
        $now = date('Y-m-d H:i:s');

        // Reclaim orphans first. Without this, a worker crash leaves the
        // job 'running' forever, and enqueue()'s dedupe then refuses to
        // ever queue that section again — a permanent, invisible wedge.
        Capsule::table('generation_runs')
            ->where('status', 'running')
            ->where('claimed_at', '<', date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60))
            ->update([
                'status'        => 'queued',
                'claimed_at'    => null,
                'error_message' => 'Reclaimed from a stalled worker.',
            ]);

        while (true) {
            $candidate = Capsule::table('generation_runs')
                ->where('status', 'queued')
                ->orderBy('id')
                ->first();

            if ($candidate === null) {
                return null;
            }

            // Only succeeds if the row is still 'queued' — whoever wins
            // this UPDATE owns the job.
            $claimed = Capsule::table('generation_runs')
                ->where('id', $candidate->id)
                ->where('status', 'queued')
                ->update(['status' => 'running', 'claimed_at' => $now, 'started_at' => $now]);

            if ($claimed === 1) {
                return Capsule::table('generation_runs')->find($candidate->id);
            }
            // Lost the race — try the next one.
        }
    }

    public function progress(int $runId, string $line): void
    {
        $existing = (string) Capsule::table('generation_runs')->where('id', $runId)->value('progress');

        Capsule::table('generation_runs')->where('id', $runId)->update([
            'progress' => mb_substr($existing . $line . "\n", -4000),
        ]);
    }

    public function finish(int $runId, string $status, ?string $error = null, array $meta = []): void
    {
        Capsule::table('generation_runs')->where('id', $runId)->update([
            'status'        => $status,
            'error_message' => $error,
            'input_tokens'  => $meta['input_tokens'] ?? null,
            'output_tokens' => $meta['output_tokens'] ?? null,
            'provider'      => $meta['provider'] ?? 'gemini',
            'model'         => $meta['model'] ?? null,
            'finished_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Puts a rate-limited job back so the worker can retry it later. */
    public function requeue(int $runId, string $reason): void
    {
        Capsule::table('generation_runs')->where('id', $runId)->update([
            'status'        => 'queued',
            'claimed_at'    => null,
            'error_message' => $reason,
        ]);
    }

    /** Queue state for the panel, newest first. */
    public function status(?int $unitId = null): array
    {
        $rows = Capsule::table('generation_runs as r')
            ->join('unit_sections as s', 's.id', '=', 'r.unit_section_id')
            ->join('units as u', 'u.id', '=', 's.unit_id')
            ->when($unitId !== null, fn ($q) => $q->where('u.id', $unitId))
            ->orderByDesc('r.id')
            ->limit(40)
            ->select([
                'r.id', 'r.status', 'r.target', 'r.params', 'r.error_message',
                'r.progress', 'r.created_at', 'r.finished_at',
                's.code', 'u.number as unit_number', 'u.id as unit_id',
            ])
            ->get();

        return $rows->map(fn ($row): array => [
            'id'       => (int) $row->id,
            'unit_id'  => (int) $row->unit_id,
            'section'  => $row->unit_number . $row->code,
            'target'   => $row->target,
            'params'   => json_decode((string) $row->params, true),
            'status'   => $row->status,
            'error'    => $row->error_message,
            'progress' => $row->progress,
            'created_at'  => $row->created_at,
            'finished_at' => $row->finished_at,
        ])->all();
    }

    public function pendingCount(): int
    {
        return Capsule::table('generation_runs')->whereIn('status', ['queued', 'running'])->count();
    }
}
