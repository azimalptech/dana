<?php

declare(strict_types=1);

/**
 * Content generation worker.
 *
 * Leave this running while you use the panel — the Generate buttons
 * enqueue work and this does it:
 *
 *   php bin/worker.php
 *   php bin/worker.php --once     process the queue then exit
 *
 * A free-tier rate limit is not a failure: the job is put back and
 * retried after a pause, so a long run survives quota resets instead of
 * losing everything done so far.
 */

ini_set('memory_limit', '1024M');
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\GenerationQueue;
use Dana\Content\Llm\GeminiProvider;
use Dana\Content\Llm\LlmRateLimitException;
use Dana\Content\SectionContentBuilder;
use Dana\Content\SectionGenerator;
use Dana\Database\Bootstrap;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\UnitSection;
use Dana\Domain\Notifications\FcmSender;
use Dana\Domain\Notifications\NotificationService;
use Dana\Support\Config;
use Dana\Support\LoggerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);
$log = LoggerFactory::get($config, 'worker');

$once = in_array('--once', $argv, true);
$queue = new GenerationQueue();

$apiKey = (string) $config->get('GEMINI_API_KEY');

if ($apiKey === '') {
    fwrite(STDERR, "No GEMINI_API_KEY in api/.env — nothing can be generated.\n");
    exit(1);
}

$provider = new GeminiProvider(
    apiKey: $apiKey,
    model: (string) $config->get('GEMINI_MODEL_GENERATE', 'gemini-3.5-flash'),
);

echo "Worker started ({$provider->model()}). Ctrl+C to stop.\n";

$fcm = new FcmSender($config->get('FCM_SERVICE_ACCOUNT_PATH'), $log);
$idleSince = null;

while (true) {
    // Push audiences too large for an inline send (NFR-1) are delivered
    // here, between generation jobs. The inbox already has the message —
    // this only adds the device notification.
    dispatchPendingPush($fcm, $log);

    $job = $queue->claim();

    if ($job === null) {
        if ($once) {
            echo "Queue empty.\n";
            break;
        }

        if ($idleSince === null) {
            $idleSince = time();
            echo "Waiting for jobs…\n";
        }

        sleep(3);
        continue;
    }

    $idleSince = null;
    $section = UnitSection::query()->with('unit')->find($job->unit_section_id);

    if ($section === null) {
        $queue->finish((int) $job->id, 'failed', 'Section no longer exists.');
        continue;
    }

    $label = $section->label();
    echo "[{$job->id}] {$job->target} — {$label}\n";

    $builder = new SectionContentBuilder(
        $provider,
        new SectionGenerator($provider),
        function (string $line) use ($queue, $job): void {
            echo "    {$line}\n";
            $queue->progress((int) $job->id, $line);
        },
    );

    $params = json_decode((string) $job->params, true) ?? [];

    try {
        match ($job->target) {
            'ocr'          => $builder->ensureOcr($section),
            'exercise_set' => runExerciseSet($builder, $section, (string) ($params['type'] ?? '')),
            default        => runWholeSection($builder, $section, $queue, (int) $job->id),
        };

        $queue->finish((int) $job->id, 'needs_review', null, [
            'provider' => $provider->name(),
            'model'    => $provider->model(),
        ]);

        echo "    done\n";
    } catch (LlmRateLimitException $e) {
        // Expected on a free-tier key. Put it back and breathe.
        $queue->requeue((int) $job->id, 'Rate limited — will retry.');
        $log->warning('generation rate limited', ['run_id' => $job->id, 'section' => $label]);
        echo "    rate limited — requeued, pausing 60s\n";

        if ($once) {
            echo "Stopping (--once) with jobs still queued.\n";
            break;
        }

        sleep(60);
    } catch (Throwable $e) {
        $queue->finish((int) $job->id, 'failed', mb_substr($e->getMessage(), 0, 1000));
        $log->error('generation failed', ['run_id' => $job->id, 'error' => $e->getMessage()]);
        echo "    FAILED: {$e->getMessage()}\n";
    }
}

/** Delivers queued large-audience pushes. Safe to call on every loop. */
function dispatchPendingPush(FcmSender $fcm, \Psr\Log\LoggerInterface $log): void
{
    if (!$fcm->isConfigured()) {
        return;
    }

    $row = Capsule::table('notifications')->where('push_status', 'pending')->orderBy('id')->first();

    if ($row === null) {
        return;
    }

    // Conditional transition: whoever flips pending->sending owns it.
    $claimed = Capsule::table('notifications')
        ->where('id', $row->id)
        ->where('push_status', 'pending')
        ->update(['push_status' => 'sending']);

    if ($claimed !== 1) {
        return;
    }

    echo "[push {$row->id}] {$row->scope}…\n";

    $tokens = NotificationService::deviceTokensFor(
        (string) $row->scope,
        $row->center_id !== null ? (int) $row->center_id : null,
        $row->classroom_id !== null ? (int) $row->classroom_id : null,
    );

    $result = $fcm->send($tokens, (string) $row->title_ru, (string) $row->body_ru, [
        'notification_id' => (string) $row->id,
        'title_tk'        => (string) $row->title_tk,
        'body_tk'         => (string) $row->body_tk,
    ]);

    NotificationService::pruneTokens($result['invalid']);

    Capsule::table('notifications')->where('id', $row->id)->update([
        'push_status' => 'done',
        'push_sent'   => $result['sent'],
        'push_failed' => $result['failed'],
    ]);

    echo "    delivered {$result['sent']}, failed {$result['failed']}\n";
    $log->info('queued push delivered', [
        'notification_id' => $row->id,
        'sent'            => $result['sent'],
        'failed'          => $result['failed'],
    ]);
}

/** Everything a section needs, in dependency order. */
function runWholeSection(
    SectionContentBuilder $builder,
    UnitSection $section,
    GenerationQueue $queue,
    int $runId,
): void {
    $builder->ensureOcr($section);

    $pool = $builder->pool($section);

    if ($pool === []) {
        throw new RuntimeException('No usable sentences — check the page mapping and OCR.');
    }

    $queue->progress($runId, count($pool) . ' source sentences');

    // Vocabulary first: the exercise generator uses it as the ceiling.
    $builder->proposeVocabulary($section, $pool);
    $builder->generateGrammar($section, $pool);

    foreach ([
        ExerciseSet::TYPE_MULTIPLE_CHOICE,
        ExerciseSet::TYPE_FILL_BLANK,
        ExerciseSet::TYPE_REORDER,
        ExerciseSet::TYPE_MATCH_PAIRS,
    ] as $type) {
        $builder->generateExerciseSet($section, $type, $pool);
    }
}

function runExerciseSet(SectionContentBuilder $builder, UnitSection $section, string $type): void
{
    if ($type === '') {
        throw new RuntimeException('No exercise type given.');
    }

    $builder->ensureOcr($section);
    $pool = $builder->pool($section);

    if ($pool === []) {
        throw new RuntimeException('No usable sentences — check the page mapping and OCR.');
    }

    $builder->generateExerciseSet($section, $type, $pool);
}
