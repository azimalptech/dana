<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Media\Audio;
use Dana\Domain\Media\GeminiMedia;
use Dana\Domain\Media\Note;
use Dana\Domain\Models\Question;
use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Dana\Support\Media\MediaStorage;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Question media (docs/06-CONTENT-V2.md §2/§3).
 *
 *  - GET /media/{name} streams a stored file to any signed-in user (a
 *    student needs the audio/image to answer). The store is outside the
 *    web root, so this route — and MediaStorage::resolve's traversal
 *    guard — is the only door.
 *  - POST/DELETE /manage/media/{questionId}/{part} let the superadmin
 *    attach or clear the file for one payload part. Uploading the last
 *    pending part makes the question servable (§3); clearing a part hides
 *    it again.
 */
final class MediaController extends Controller
{
    /** The addressable payload parts (§2): the stem and up to four options. */
    private const PARTS = ['stem', 'opt0', 'opt1', 'opt2', 'opt3'];

    /** Extensions accepted per media kind. */
    private const EXTENSIONS = [
        'audio' => ['mp3', 'm4a', 'aac', 'ogg', 'wav'],
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ];

    private const MIME = [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly GeminiMedia $gemini,
    ) {
    }

    /** GET /media/{name} — authenticated streaming of a stored file. */
    public function serve(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // Any signed-in role may read media; the traversal guard is in
        // MediaStorage::resolve.
        $this->scope($request);

        $path = $this->storage->resolve((string) ($args['name'] ?? ''));

        if (!is_file($path)) {
            throw ApiException::notFound();
        }

        $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $response->getBody()->write((string) file_get_contents($path));

        return $response
            ->withHeader('Content-Type', self::MIME[$ext] ?? 'application/octet-stream')
            ->withHeader('Content-Length', (string) (filesize($path) ?: 0));
    }

    /** POST /manage/media/{questionId}/{part} — attach a file to one part. */
    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        [$question, $payload, $part, $kind] = $this->locate($args);

        $file = $this->firstUpload($request);

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw ApiException::validation('Faýl ýüklenmedi.', 'Файл не загрузился.');
        }

        $ext = mb_strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));

        if (!in_array($ext, self::EXTENSIONS[$kind], true)) {
            $allowed = implode(', ', self::EXTENSIONS[$kind]);
            throw ApiException::validation(
                "«{$kind}» üçin rugsat berilýän görnüşler: {$allowed}.",
                "Для «{$kind}» допустимы форматы: {$allowed}."
            );
        }

        $name = 'q' . (int) $question->id . '-' . $part . '.' . $ext;
        $dir = $this->storage->dir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw ApiException::validation('Media bukjasy döredilmedi.', 'Не удалось создать папку media.');
        }

        // Drop any previous file for this part with a different extension,
        // so a re-upload never leaves an orphan.
        foreach (self::EXTENSIONS[$kind] as $other) {
            $stale = $dir . '/q' . (int) $question->id . '-' . $part . '.' . $other;

            if ($other !== $ext && is_file($stale)) {
                @unlink($stale);
            }
        }

        $file->moveTo($dir . '/' . $name);

        return $this->json($response, $this->attach($question, $payload, $part, $name));
    }

    /**
     * POST /manage/media/{questionId}/{part}/generate — the part's own
     * note, spoken or drawn (FR-15.18).
     *
     * A sibling of upload() rather than a separate controller, because
     * everything after "obtain the bytes" is identical: the same naming,
     * the same stale-extension sweep, the same payload write, the same
     * servability answer. The only difference is where the bytes come
     * from — an operator's file, or the note the operator already typed.
     */
    public function generate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        [$question, $payload, $part, $kind] = $this->locate($args);

        $node = $this->partNode($payload, $part);
        $note = (string) ($kind === 'audio' ? ($node['audio_note'] ?? '') : ($node['image_note'] ?? ''));
        $source = $this->sourceText($payload, $note);

        $made = $kind === 'audio'
            ? $this->gemini->speech($source)
            : $this->gemini->image($source);

        $name = $this->write($question, $part, $kind, $made['bytes'], $made['ext']);

        return $this->json($response, $this->attach($question, $payload, $part, $name) + [
            // What was actually asked for, so the panel can show it:
            // "FLAG_TURKEY" reaches Gemini as "the national flag of
            // Turkey", and a two-line script as a two-voice scene.
            // The SOURCE, not the raw note: when the note names a file
            // the two differ, and the panel must echo what was actually
            // spoken or drawn rather than what was written down.
            'note'   => $kind === 'audio' ? self::spokenSummary($source) : Note::imageSubject($source),
            'source' => 'gemini',
        ]);
    }

    /**
     * What Gemini is actually given for this part.
     *
     * FR-15.18 renders the note itself, and that is right when the note
     * IS the words — `seven`, `FLAG_TURKEY`, a two-line script. The
     * workbook's listening content does something else: it puts the
     * source recording's FILE NAME in the note
     * (`A2_U09-10_RC910_LIST_001.mp3`) because that is what the note
     * stands for in the printed book. Rendering that speaks the filename
     * aloud, letter by letter.
     *
     * So a note that names a file is not the text — the question's own
     * correct answer is (client, 2026-09-14: «gemini should create an
     * audio that plays "fruit"»). That keeps the invariant that matters:
     * nothing is invented here either way, the words still come from the
     * question the author already wrote, only from its answer rather
     * than from its note.
     *
     * Refused rather than guessed when neither yields anything, because
     * a silent clip or a picture of nothing would pass FR-14.2 as a
     * present file and fail only in front of a student.
     */
    private function sourceText(array $payload, string $note): string
    {
        $note = trim($note);

        if ($note !== '' && !Note::isMediaFilename($note)) {
            return $note;
        }

        $answer = self::answerText($payload);

        if ($answer !== '') {
            return $answer;
        }

        throw ApiException::validation(
            'Bellikde diňe faýl ady bar, dogry jogap bolsa boş.',
            $note === ''
                ? 'Нечего озвучить или нарисовать: заметка пуста и правильный ответ тоже.'
                : 'Заметка «' . $note . '» — это имя файла, а правильный ответ пуст. '
                    . 'Впишите текст в заметку или заполните правильный ответ.',
        );
    }

    /**
     * The text of the option the payload marks correct, or '' when the
     * payload is not a multiple choice or the option carries no text of
     * its own (an options-are-pictures question, say).
     */
    private static function answerText(array $payload): string
    {
        $options = $payload['options'] ?? null;
        $index   = $payload['answer'] ?? null;

        if (!is_array($options) || !is_int($index)) {
            return '';
        }

        $option = $options[$index] ?? null;

        return is_array($option) ? trim((string) ($option['text'] ?? '')) : '';
    }

    /**
     * How an audio note was interpreted, for the panel to echo back:
     * a two-line script reads as a scene between two named people,
     * anything else as one voice.
     */
    private static function spokenSummary(string $note): string
    {
        $parsed = Note::speech($note);

        return $parsed['speakers'] === []
            ? $parsed['text']
            : $parsed['speakers'][0] . ' + ' . $parsed['speakers'][1];
    }

    /**
     * Writes the bytes as this part's file and removes any earlier file
     * for the part under a different extension, so switching between an
     * uploaded mp3 and a generated wav never leaves two.
     */
    private function write(object $question, string $part, string $kind, string $bytes, string $ext): string
    {
        $dir = $this->storage->dir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw ApiException::validation('Media bukjasy döredilmedi.', 'Не удалось создать папку media.');
        }

        foreach (self::EXTENSIONS[$kind] as $other) {
            $stale = $dir . '/q' . (int) $question->id . '-' . $part . '.' . $other;

            if ($other !== $ext && is_file($stale)) {
                @unlink($stale);
            }
        }

        $name = 'q' . (int) $question->id . '-' . $part . '.' . $ext;

        if (file_put_contents($dir . '/' . $name, $bytes) === false) {
            throw ApiException::validation('Faýl ýazylmady.', 'Не удалось сохранить файл.');
        }

        return $name;
    }

    /**
     * Points the payload part at the stored file and reports whether the
     * question has become servable (§3).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attach(object $question, array $payload, string $part, string $name): array
    {
        $relative = 'media/' . $name;
        $payload = $this->setMediaPath($payload, $part, $relative);

        Capsule::table('questions')->where('id', (int) $question->id)->update([
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'media_path' => $relative,
            'url'        => Question::MEDIA_URL_PREFIX . $name,
            'servable'   => Question::payloadServable($payload),
        ];
    }

    /** DELETE /manage/media/{questionId}/{part} — clear a part's file. */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        [$question, $payload, $part] = $this->locate($args);

        $current = $this->mediaPathOf($payload, $part);

        if ($current !== null) {
            $path = $this->storage->dir() . '/' . basename($current);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $payload = $this->setMediaPath($payload, $part, null);

        Capsule::table('questions')->where('id', (int) $question->id)->update([
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['servable' => Question::payloadServable($payload)]);
    }

    // ------------------------------------------------------------ internals

    /**
     * Resolves the {questionId}/{part} route args to the question row, its
     * decoded payload, the part name and (upload/delete both need it) the
     * media kind of that part. 404 for an unknown question or part that
     * carries no media; validation error for a bad part name.
     *
     * @return array{0: object, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function locate(array $args): array
    {
        $part = (string) ($args['part'] ?? '');

        if (!in_array($part, self::PARTS, true)) {
            throw ApiException::validation(
                'Nädogry media bölegi.',
                'Недопустимая часть медиа (stem, opt0…opt3).'
            );
        }

        $question = Capsule::table('questions')
            ->where('id', (int) ($args['questionId'] ?? 0))
            ->where('is_active', 1)
            ->first();

        if ($question === null) {
            throw ApiException::notFound();
        }

        $payload = json_decode((string) $question->payload, true);

        if (!is_array($payload)) {
            throw ApiException::notFound();
        }

        $node = $this->partNode($payload, $part);
        $kind = is_array($node)
            ? (isset($node['audio_note']) ? 'audio' : (isset($node['image_note']) ? 'image' : null))
            : null;

        if ($kind === null) {
            // A text (or absent) part has no file to attach.
            throw ApiException::validation(
                'Bu bölekde media ýok.',
                'У этой части нет медиа для загрузки.'
            );
        }

        return [$question, $payload, $part, $kind];
    }

    /** @param array<string, mixed> $payload */
    private function partNode(array $payload, string $part): mixed
    {
        if ($part === 'stem') {
            return $payload['stem'] ?? null;
        }

        $index = (int) substr($part, 3);

        return $payload['options'][$index] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function setMediaPath(array $payload, string $part, ?string $value): array
    {
        if ($part === 'stem') {
            if (is_array($payload['stem'] ?? null)) {
                $payload['stem']['media_path'] = $value;
            }

            return $payload;
        }

        $index = (int) substr($part, 3);

        if (is_array($payload['options'][$index] ?? null)) {
            $payload['options'][$index]['media_path'] = $value;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function mediaPathOf(array $payload, string $part): ?string
    {
        $node = $this->partNode($payload, $part);

        return is_array($node) && !empty($node['media_path']) ? (string) $node['media_path'] : null;
    }

    private function firstUpload(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $found = null;

        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if ($found !== null) {
                return;
            }

            if ($node instanceof UploadedFileInterface) {
                $found = $node;
            } elseif (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk($request->getUploadedFiles());

        return $found;
    }
}
