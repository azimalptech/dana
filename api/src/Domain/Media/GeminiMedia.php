<?php

declare(strict_types=1);

namespace Dana\Domain\Media;

use Dana\Http\ApiException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Renders a question's OWN text as speech or as a picture (FR-15.18).
 *
 * This is deliberately not content generation, and the distinction is
 * the whole reason it is allowed to exist next to CLAUDE.md invariant 6.
 * Nothing here invents an exercise, a question, a distractor or an
 * answer. Every call is handed a string the superadmin already authored
 * in the workbook — a v2 payload's `audio_note` ("seven") or
 * `image_note` ("italy") — and returns a file of that same string spoken
 * or drawn. Remove this service and the content is unchanged; only the
 * recording studio is gone.
 *
 * Two consequences of that framing are enforced here rather than left to
 * callers:
 *
 *  - There is no free-text prompt parameter. A caller passes the note;
 *    the wording around it is a fixed, configurable directive, so two
 *    clips authored months apart are asked for in the same words. The
 *    API gives no accent parameter and no determinism guarantee, so the
 *    directive is the only consistency Dana gets.
 *
 *  - It is unreachable without `GEMINI_API_KEY` in `api/.env`. Absent —
 *    which is the default, and how the product ships — every entry point
 *    reports "not configured" and the panel hides its buttons. The key
 *    is the operator's, in their own environment file; none ships in the
 *    repository, the panel bundle or the phone app.
 */
final class GeminiMedia
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    /** Gemini TTS returns raw signed 16-bit little-endian mono PCM. */
    private const PCM_RATE = 24000;
    private const PCM_BITS = 16;
    private const PCM_CHANNELS = 1;

    public function __construct(
        private readonly GeminiSettings $settings,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * False unless a key is present, which is the shipped default. Both
     * the routes and the panel read this — an unconfigured install shows
     * no generate buttons at all rather than buttons that always fail.
     */
    public function configured(): bool
    {
        return $this->settings->apiKey !== null;
    }

    /**
     * The note spoken aloud.
     *
     * @return array{bytes: string, ext: string}
     */
    public function speech(string $note): array
    {
        $text = self::cleanNote($note);

        $body = $this->call([
            'model'             => $this->settings->ttsModel,
            'input'             => self::directive($this->settings->ttsDirective, self::DEFAULT_TTS_DIRECTIVE, $text),
            'response_format'   => ['type' => 'audio'],
            'generation_config' => [
                'speech_config' => [
                    ['voice' => $this->settings->ttsVoice],
                ],
            ],
        ]);

        $pcm = self::decodeInline($body, 'output_audio');

        // Google documents an occasional response that carries text
        // tokens instead of audio. A near-empty body is that failure, and
        // writing it would leave a silent clip that FR-14.2 cannot see is
        // broken — the question would serve and simply play nothing.
        if (strlen($pcm) < 2000) {
            throw self::failed('Модель вернула пустой звук. Повторите попытку.');
        }

        return Audio::encode($pcm, self::PCM_RATE, self::PCM_BITS, self::PCM_CHANNELS);
    }

    /**
     * The note drawn.
     *
     * @return array{bytes: string, ext: string}
     */
    public function image(string $note): array
    {
        $subject = self::cleanNote($note);

        $body = $this->call([
            'model'           => $this->settings->imageModel,
            'input'           => [
                [
                    'type' => 'text',
                    'text' => self::directive($this->settings->imageDirective, self::DEFAULT_IMAGE_DIRECTIVE, $subject),
                ],
            ],
            'response_format' => [
                'type'         => 'image',
                'mime_type'    => 'image/png',
                'aspect_ratio' => $this->settings->imageAspect,
                // 1K, not the 2K/4K the API also offers: the response is
                // inline base64 inside a JSON body that json_decode holds
                // whole in memory, and a question illustration on a phone
                // gains nothing from four times the pixels.
                'image_size'   => $this->settings->imageSize,
            ],
        ]);

        $png = self::decodeInline($body, 'output_image');

        if (strlen($png) < 1000) {
            throw self::failed('Модель вернула пустое изображение. Повторите попытку.');
        }

        return ['bytes' => $png, 'ext' => 'png'];
    }

    /**
     * A word is spoken as a word. The directive carries the accent and
     * the register, because the API exposes no parameter for either —
     * which is also why it is one stored constant rather than something
     * composed per question.
     */
    private const DEFAULT_TTS_DIRECTIVE =
        'Read this aloud exactly as written, once, in a clear neutral English accent '
        . 'at a calm pace, as the audio prompt of a listening exercise for beginner '
        . 'learners of English. Say nothing else: {text}';

    /**
     * "no text, no letters, no numbers" is not decoration. These pictures
     * ARE the question — a student sees four of them and picks one — so a
     * label rendered inside the image would hand over the answer.
     */
    private const DEFAULT_IMAGE_DIRECTIVE =
        'A simple, friendly flat illustration of: {text}. Centred on a plain white '
        . 'background, one clear subject, bright flat colours, no text, no letters, '
        . 'no numbers, no watermark, no border. Suitable for a beginner English '
        . 'textbook for all ages.';

    /** The configured directive with {text} filled in. */
    private static function directive(?string $configured, string $fallback, string $text): string
    {
        $template = ($configured === null || trim($configured) === '') ? $fallback : $configured;

        return str_replace('{text}', $text, $template);
    }

    /**
     * A v2 note is the author's own word — "seven", "italy" — but the
     * option-level ones arrive as identifiers like "IMG_PEN" from the
     * client's own files. Speaking or drawing "IMG_PEN" literally is
     * exactly the kind of nonsense that would reach a student, so the
     * prefix and the underscores come off first.
     */
    public static function cleanNote(string $note): string
    {
        $text = trim($note);
        $text = (string) preg_replace('/^(IMG|IMAGE|AUD|AUDIO)[_\-\s]+/i', '', $text);
        $text = str_replace(['_', '-'], ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        // An ALL-CAPS remainder is an identifier, not prose: "IMG_PEN"
        // leaves "PEN", which a TTS voice may spell out letter by letter
        // and an illustrator may render as a sign. Anything with any
        // lower case in it is the author's own writing and is left
        // exactly as typed — otherwise "Italy" and "New York" would be
        // quietly downcased.
        if ($text !== '' && mb_strtoupper($text, 'UTF-8') === $text) {
            $text = mb_strtolower($text, 'UTF-8');
        }

        if ($text === '') {
            throw self::failed('У этой части нет текста для озвучки или картинки.');
        }

        return $text;
    }

    /** @param array<string, mixed> $payload */
    private function call(array $payload): array
    {
        $key = $this->settings->apiKey;

        if ($key === null) {
            throw new ApiException(
                'generation_unconfigured',
                'Media döretmek düzülmedik.',
                'Генерация медиа не настроена: в api/.env нет GEMINI_API_KEY.',
                400
            );
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            // Image generation is routinely slow; the panel shows a
            // pending state rather than the operator watching a spinner
            // die at the default 30 seconds.
            CURLOPT_TIMEOUT        => $this->settings->timeout,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log->warning('gemini transport failure', ['error' => $error]);

            throw self::failed('Не удалось связаться с Google. Проверьте соединение сервера.');
        }

        $body = json_decode((string) $raw, true);

        if ($status !== 200 || !is_array($body)) {
            // The key is in the request, never in the log.
            $this->log->warning('gemini rejected the request', [
                'status' => $status,
                'body'   => mb_substr((string) $raw, 0, 400),
            ]);

            throw self::failed(match (true) {
                $status === 429 => 'Google временно ограничил запросы. Повторите через минуту.',
                $status === 403 => 'Google отклонил ключ. Проверьте GEMINI_API_KEY и биллинг.',
                default         => "Google вернул ошибку ({$status}).",
            });
        }

        return $body;
    }

    /**
     * The convenience property the docs describe — `output_audio` /
     * `output_image`, each `{data: base64}` — with a fallback walk for
     * responses that nest the block instead.
     *
     * @param array<string, mixed> $body
     */
    private static function decodeInline(array $body, string $property): string
    {
        $data = $body[$property]['data'] ?? null;

        if (!is_string($data)) {
            $data = self::findData($body);
        }

        if (!is_string($data) || $data === '') {
            throw self::failed('Ответ Google не содержит файла.');
        }

        $bytes = base64_decode($data, true);

        if ($bytes === false) {
            throw self::failed('Ответ Google повреждён.');
        }

        return $bytes;
    }

    /** First base64 `data` anywhere in the response. */
    private static function findData(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        if (isset($node['data']) && is_string($node['data']) && strlen($node['data']) > 100) {
            return $node['data'];
        }

        foreach ($node as $child) {
            $found = self::findData($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function failed(string $ru): ApiException
    {
        return new ApiException('generation_failed', 'Media döredilmedi.', $ru, 502);
    }
}
