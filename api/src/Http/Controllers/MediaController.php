<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

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

    public function __construct(private readonly MediaStorage $storage)
    {
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

        $relative = 'media/' . $name;
        $payload = $this->setMediaPath($payload, $part, $relative);

        Capsule::table('questions')->where('id', (int) $question->id)->update([
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, [
            'media_path' => $relative,
            'url'        => Question::MEDIA_URL_PREFIX . $name,
            'servable'   => Question::payloadServable($payload),
        ]);
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
