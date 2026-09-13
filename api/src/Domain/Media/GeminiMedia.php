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
        $parsed = Note::speech($note);

        [$input, $speechConfig] = $parsed['speakers'] === []
            ? $this->solo($parsed['text'])
            : $this->conversation($parsed['speakers'], $parsed['lines']);

        $body = $this->call([
            'model'             => $this->settings->ttsModel,
            'input'             => $input,
            'response_format'   => ['type' => 'audio'],
            'generation_config' => ['speech_config' => $speechConfig],
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
     * One voice reading one line.
     *
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private function solo(string $text): array
    {
        return [
            self::directive($this->settings->ttsDirective, self::DEFAULT_TTS_DIRECTIVE, $text),
            [['voice' => $this->settings->ttsVoice]],
        ];
    }

    /**
     * Two voices reading a scene.
     *
     * Gemini takes at most two speakers, and the names in `speech_config`
     * must be the names used in the transcript — so the script goes up
     * verbatim, labels and all, and the model is told to perform it
     * rather than read it out.
     *
     * @param list<string> $speakers                                 already sorted, so a
     *                                                               role keeps one voice
     * @param list<array{speaker: string, text: string}> $lines
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private function conversation(array $speakers, array $lines): array
    {
        $script = implode("\n", array_map(
            static fn (array $line): string => $line['speaker'] . ': ' . $line['text'],
            $lines
        ));

        $directive = $this->settings->dialogueDirective;
        $template = ($directive === null || trim($directive) === '')
            ? self::DEFAULT_DIALOGUE_DIRECTIVE
            : $directive;

        $input = str_replace(
            ['{a}', '{b}', '{script}'],
            [$speakers[0], $speakers[1], $script],
            $template
        );

        return [
            $input,
            [
                ['speaker' => $speakers[0], 'voice' => $this->settings->ttsVoice],
                ['speaker' => $speakers[1], 'voice' => $this->settings->ttsVoiceB],
            ],
        ];
    }

    /**
     * The note drawn.
     *
     * @return array{bytes: string, ext: string}
     */
    public function image(string $note): array
    {
        $subject = Note::imageSubject($note);

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
                'mime_type'    => $this->settings->imageMime,
                'aspect_ratio' => $this->settings->imageAspect,
                // 1K, not the 2K/4K the API also offers: the response is
                // inline base64 inside a JSON body that json_decode holds
                // whole in memory, and a question illustration on a phone
                // gains nothing from four times the pixels.
                'image_size'   => $this->settings->imageSize,
            ],
        ]);

        $bytes = self::decodeInline($body, 'output_image');

        if (strlen($bytes) < 1000) {
            throw self::failed('Модель вернула пустое изображение. Повторите попытку.');
        }

        return ['bytes' => $bytes, 'ext' => self::extensionFor($this->settings->imageMime)];
    }

    /** The file extension the media route knows this mime type by. */
    private static function extensionFor(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    /**
     * A word is spoken as a word. The directive carries the accent and
     * the register, because the API exposes no parameter for either —
     * which is also why it is one stored constant rather than something
     * composed per question.
     */
    private const DEFAULT_TTS_DIRECTIVE =
        'Read the following aloud exactly as written, once, and say nothing else. '
        . 'It is the audio prompt of a listening exercise for beginner learners of '
        . 'English, so speak clearly and unhurriedly with crisp consonants, in a '
        . 'standard British English accent as heard on BBC news. Do not add a '
        . 'greeting, a sign-off or any comment of your own: {text}';

    /**
     * A scene, performed. `{a}` and `{b}` are the two speaker names, and
     * they must match the names in `speech_config` — the doc is explicit
     * that the model pairs them by name.
     *
     * The pace instruction earns its place: two native speakers in a
     * hotel-reception scene will run the lines together at conversational
     * speed, which is unusable for a beginner who has to catch "I have a
     * reservation".
     */
    private const DEFAULT_DIALOGUE_DIRECTIVE =
        'TTS the following conversation between {a} and {b}. It is a listening '
        . 'exercise for beginner learners of English: both speakers use a standard '
        . 'British English accent as heard on BBC news, speak clearly and a little '
        . 'more slowly than natural conversation, and leave a short pause between '
        . 'turns. Read only the words of the script — never say the speaker names '
        . 'aloud — and add nothing of your own:' . "\n{script}";

    /**
     * Cartoon, and explicitly so — the client asked for cartoon-styled
     * pictures, and "illustration" alone drifted towards stock vector
     * art. Every other clause here was written against a picture the
     * first draft actually produced (2026-09-13):
     *
     *  - "no face, no eyes" because "friendly cartoon of a pen" came
     *    back as a smiling pen with eyes and blushing cheeks. Charming,
     *    and no use in a vocabulary exercise about stationery.
     *
     *  - "one single object, no scene" because the note "italy" produced
     *    a collage — a chef, a pizza, the Colosseum, the Leaning Tower,
     *    a gondola, a bunch of grapes — which at the 96px an option tile
     *    gets is unreadable mush.
     *
     *  - The text clause is repeated and made concrete because the first
     *    attempt ignored it outright: "IMG_DICTIONARY" came back as an
     *    open dictionary with DOG, APPLE, SUN and CAT printed legibly
     *    across the page. These pictures ARE the question — a student
     *    sees four and picks one — so a printed English word inside the
     *    picture hands over the answer. Where an object cannot plausibly
     *    be blank, the model is told what to draw INSTEAD of words
     *    rather than simply forbidden them.
     *
     * The framing serves the app: BoxFit.cover into a 160px banner and
     * 96px option tiles, so the subject is centred and away from the
     * edges, and large enough to survive being shrunk that far.
     */
    private const DEFAULT_IMAGE_DIRECTIVE =
        'A children\'s-book cartoon drawing of ONE single object: {text}. '
        . 'Bold clean outlines, bright flat colours, simple rounded shapes, light '
        . 'shading. Not photorealistic, not a 3D render, not clip-art collage. '
        . 'THE SUBJECT IS AN OBJECT, NOT A CHARACTER: no face, no eyes, no mouth, '
        . 'no arms or legs added to it, unless the subject itself is a person or an '
        . 'animal. Draw the subject ALONE — no scene, no background objects, no '
        . 'landmarks, no montage of several things, nothing else in the picture. '
        . 'NO WRITING ANYWHERE: no words, no letters, no numbers, no labels, no '
        . 'captions, no speech bubbles, no watermark, no logo. If the object would '
        . 'normally carry writing, such as a book or a sign, draw the writing as '
        . 'faint wavy grey lines that cannot be read as any language. '
        . 'Composition: the object centred and filling most of the frame, with a '
        . 'small even margin on all four sides, on a plain flat white background, '
        . 'no border and no frame. Suitable for a beginner English textbook.';

    /** The configured directive with {text} filled in. */
    private static function directive(?string $configured, string $fallback, string $text): string
    {
        $template = ($configured === null || trim($configured) === '') ? $fallback : $configured;

        return str_replace('{text}', $text, $template);
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
