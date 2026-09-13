<?php

declare(strict_types=1);

/**
 * Rendering a question's own note as media — FR-15.18.
 *
 *   C:/xampp/php/php.exe tests/media_generation_test.php
 *
 * Everything here runs WITHOUT a Gemini key and without touching the
 * network. That is deliberate: the shipped default is no key, and the
 * behaviour that matters most is what happens in that state — the
 * feature must be completely inert, not merely broken.
 *
 * What is covered: the note-to-prompt cleanup (the client's own files
 * carry option notes as "IMG_PEN", and speaking or drawing that string
 * literally is exactly the nonsense that would reach a student), the WAV
 * container Gemini's raw PCM has to be wrapped in, and the endpoint's
 * refusals. The one thing not covered is the HTTP call itself, which
 * needs a real key.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Media\Audio;
use Dana\Domain\Media\GeminiMedia;
use Dana\Domain\Media\GeminiSettings;
use Dana\Domain\Models\Section;
use Dana\Http\ApiException;
use Dana\Http\Controllers\MediaController;
use Dana\Support\Config;
use Dana\Support\Media\MediaStorage;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const TEST_SLUG = '__media_generation_test__';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

function cleanup(): void
{
    $levelIds = Capsule::table('levels')->where('slug', TEST_SLUG)->pluck('id')->all();

    if ($levelIds === []) {
        return;
    }

    $unitIds = Capsule::table('units')->whereIn('level_id', $levelIds)->pluck('id')->all();
    $childIds = $unitIds === [] ? [] : Capsule::table('unit_sections')->whereIn('unit_id', $unitIds)->pluck('id')->all();
    $sectionIds = $childIds === [] ? [] : Capsule::table('sections')->whereIn('unit_section_id', $childIds)->pluck('id')->all();
    $setIds = $sectionIds === [] ? [] : Capsule::table('exercise_sets')->whereIn('section_id', $sectionIds)->pluck('id')->all();

    if ($setIds !== []) {
        Capsule::table('questions')->whereIn('exercise_set_id', $setIds)->delete();
        Capsule::table('exercise_sets')->whereIn('id', $setIds)->delete();
    }

    if ($sectionIds !== []) {
        Capsule::table('sections')->whereIn('id', $sectionIds)->delete();
    }

    if ($childIds !== []) {
        Capsule::table('unit_sections')->whereIn('id', $childIds)->delete();
    }

    if ($unitIds !== []) {
        Capsule::table('units')->whereIn('id', $unitIds)->delete();
    }

    Capsule::table('levels')->whereIn('id', $levelIds)->delete();
}

cleanup();

echo PHP_EOL . 'The note becomes the prompt' . PHP_EOL;

// The stem notes are plain words; the OPTION notes in the client's own
// files are identifiers. Both have to end up as something worth saying.
foreach ([
    'seven'        => 'seven',
    'italy'        => 'italy',
    'IMG_PEN'      => 'pen',
    'IMG_COFFEE_CUP' => 'coffee cup',
    'image_window' => 'window',
    'AUDIO_seven'  => 'seven',
    '  spaced  '   => 'spaced',
    "two\tspaces"  => 'two spaces',
] as $note => $expected) {
    $got = GeminiMedia::cleanNote((string) $note);
    check("«{$note}» → «{$expected}»", $got === $expected, $got);
}

$thrown = null;
try {
    GeminiMedia::cleanNote('   ');
} catch (ApiException $e) {
    $thrown = $e;
}
check('an empty note is refused rather than sent', $thrown !== null);

// "IMG" on its own is a prefix with nothing after it — there is no
// subject, and drawing the literal string would be worse than refusing.
$thrown = null;
try {
    GeminiMedia::cleanNote('IMG_');
} catch (ApiException $e) {
    $thrown = $e;
}
check('a bare prefix is refused too', $thrown !== null);

echo PHP_EOL . 'Raw PCM becomes a playable file' . PHP_EOL;

$pcm = str_repeat("\x01\x02", 12000);   // 24000 bytes = 0.5s at 24kHz/16-bit
$wav = Audio::wav($pcm, 24000, 16, 1);

check('starts with a RIFF/WAVE header', str_starts_with($wav, 'RIFF') && substr($wav, 8, 4) === 'WAVE');
check('header is 44 bytes', strlen($wav) === strlen($pcm) + 44, (string) (strlen($wav) - strlen($pcm)));
check('declares the sample rate', unpack('V', substr($wav, 24, 4))[1] === 24000);
check('declares mono', unpack('v', substr($wav, 22, 2))[1] === 1);
check('declares 16 bits', unpack('v', substr($wav, 34, 2))[1] === 16);
check('byte rate matches 24000 x 2', unpack('V', substr($wav, 28, 4))[1] === 48000);
check('RIFF size counts everything after the first 8 bytes',
    unpack('V', substr($wav, 4, 4))[1] === strlen($wav) - 8);
check('data chunk length matches the samples',
    unpack('V', substr($wav, 40, 4))[1] === strlen($pcm));
check('the samples survive intact', substr($wav, 44) === $pcm);

echo PHP_EOL . 'mp3 when the host can, wav when it cannot' . PHP_EOL;

$encoded = Audio::encode($pcm, 24000, 16, 1);
$expectedExt = Audio::canEncodeMp3() ? 'mp3' : 'wav';
check("this host produces .{$expectedExt}", $encoded['ext'] === $expectedExt, $encoded['ext']);
check('and the bytes are not empty', strlen($encoded['bytes']) > 100);

echo PHP_EOL . 'Without a key the feature is inert' . PHP_EOL;

// No key, whatever the developer's own .env happens to hold — this is
// the state the product ships in and the one worth pinning.
$unconfigured = new GeminiMedia(new GeminiSettings(apiKey: null), new NullLogger());

check('configured() is false', $unconfigured->configured() === false);

foreach (['speech', 'image'] as $method) {
    $thrown = null;
    try {
        $unconfigured->{$method}('seven');
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check(
        "{$method}() reports it is not configured, and says where to fix it",
        $thrown !== null
        && $thrown->errorCode === 'generation_unconfigured'
        && str_contains($thrown->messageRu, 'api/.env')
    );
}

echo PHP_EOL . 'The endpoint refuses what it should' . PHP_EOL;

$now = date('Y-m-d H:i:s');
$levelId = (int) Capsule::table('levels')->insertGetId([
    'name' => 'Media gen test', 'slug' => TEST_SLUG, 'sort_order' => 997,
    'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
]);
$unitId = (int) Capsule::table('units')->insertGetId([
    'level_id' => $levelId, 'number' => 1, 'sort_order' => 1,
]);
$childId = (int) Capsule::table('unit_sections')->insertGetId([
    'unit_id' => $unitId, 'code' => 'A', 'sort_order' => 1, 'level_position' => 1,
]);
$sectionId = (int) Capsule::table('sections')->insertGetId([
    'unit_section_id' => $childId, 'type' => Section::TYPE_LISTENING,
    'status' => Section::STATUS_DRAFT, 'sort_order' => 1,
    'created_at' => $now, 'updated_at' => $now,
]);
$setId = (int) Capsule::table('exercise_sets')->insertGetId([
    'section_id' => $sectionId, 'type' => 'multiple_choice',
    'title_tk' => 'T', 'title_ru' => 'T', 'status' => 'published',
    'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
]);

/** A question shaped exactly like the client's imported listening rows. */
function makeQuestion(int $setId, array $payload, string $type): int
{
    $now = date('Y-m-d H:i:s');

    return (int) Capsule::table('questions')->insertGetId([
        'exercise_set_id' => $setId,
        'question_type'   => $type,
        'prompt_tk'       => '',
        'prompt_ru'       => '',
        'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'is_active'       => 1,
        'sort_order'      => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

$audioQ = makeQuestion($setId, [
    'stem'    => ['audio_note' => 'seven', 'media_path' => null],
    'options' => [['text' => '7'], ['text' => '9']],
    'answer'  => 0,
], 'audio');

$textQ = makeQuestion($setId, [
    'stem'    => ['text' => 'I ___ a student'],
    'options' => [['text' => 'am'], ['text' => 'is']],
    'answer'  => 0,
], 'text');

$controller = new MediaController(
    MediaStorage::fromConfig($config, dirname(__DIR__)),
    $unconfigured
);

function callGenerate(MediaController $c, int $questionId, string $part): ?ApiException
{
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', '/generate')
        ->withAttribute(
            Dana\Domain\Scope::ATTRIBUTE,
            new Dana\Domain\Scope(userId: 1, role: 'superadmin', centerId: null, classroomId: null)
        );

    try {
        $c->generate($request, new Slim\Psr7\Response(), [
            'questionId' => (string) $questionId,
            'part'       => $part,
        ]);
    } catch (ApiException $e) {
        return $e;
    }

    return null;
}

try {
    $e = callGenerate($controller, $audioQ, 'stem');
    check('an audio part with no key stops at "not configured"',
        $e !== null && $e->errorCode === 'generation_unconfigured', $e?->errorCode ?? 'no error');

    $e = callGenerate($controller, $textQ, 'stem');
    check('a TEXT part is refused before any call is attempted',
        $e !== null && $e->errorCode !== 'generation_unconfigured', $e?->errorCode ?? 'no error');

    $e = callGenerate($controller, $audioQ, 'opt9');
    check('an out-of-range part is refused', $e !== null);

    $e = callGenerate($controller, 99999999, 'stem');
    check('an unknown question is 404', $e !== null && $e->status === 404);

    // A teacher's own recording must not be silently replaced by a
    // machine voice, so nothing was written on any of the paths above.
    $row = Capsule::table('questions')->where('id', $audioQ)->value('payload');
    check('and no payload was touched by a refused call',
        json_decode((string) $row, true)['stem']['media_path'] === null);

    echo PHP_EOL . 'The panel is told whether to offer the buttons' . PHP_EOL;

    check('an unconfigured install reports false', $unconfigured->configured() === false);
} finally {
    cleanup();
}

echo PHP_EOL . "  {$pass} passed, {$fail} failed" . PHP_EOL;

exit($fail === 0 ? 0 : 1);
