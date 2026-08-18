<?php

declare(strict_types=1);

namespace Dana\Content\Llm;

use SensitiveParameter;

/**
 * Google Generative Language API.
 *
 * Note on free-tier keys: quota is tight and 429s are routine, not
 * exceptional. They surface as LlmRateLimitException so the worker backs
 * off and resumes rather than marking a generation run failed and losing
 * the work already done on it.
 */
final class GeminiProvider implements LlmProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $model = 'gemini-3.5-flash',
        private readonly int $timeoutSeconds = 180,
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(
        string $systemPrompt,
        string $userPrompt,
        ?array $jsonSchema = null,
        float $temperature = 0.2,
    ): LlmResult {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $userPrompt]],
            ]],
            'generationConfig' => [
                'temperature' => $temperature,
            ],
        ];

        if ($jsonSchema !== null) {
            // Constrained decoding: the model cannot return prose, so the
            // pipeline never has to salvage JSON out of an explanation.
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema'] = $jsonSchema;
        }

        $body = $this->request($payload);

        $text = '';
        foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        if ($text === '') {
            $reason = $body['candidates'][0]['finishReason'] ?? 'unknown';
            throw new LlmException("Gemini returned no content (finishReason: {$reason}).");
        }

        $json = null;
        if ($jsonSchema !== null) {
            $json = json_decode($text, true);

            if (!is_array($json)) {
                throw new LlmException(
                    'Gemini returned malformed JSON despite a response schema: ' . json_last_error_msg()
                );
            }
        }

        return new LlmResult(
            text: $text,
            json: $json,
            inputTokens: (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            provider: $this->name(),
            model: $this->model,
        );
    }

    /**
     * Vision call — used to transcribe scanned textbook pages, since the
     * supplied PDFs have no text layer.
     */
    public function transcribeImage(string $imagePath, string $instruction, float $temperature = 0.0): LlmResult
    {
        $bytes = @file_get_contents($imagePath);

        if ($bytes === false) {
            throw new LlmException("Cannot read image: {$imagePath}");
        }

        $body = $this->request([
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['text' => $instruction],
                    ['inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data'      => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => ['temperature' => $temperature],
        ]);

        $text = '';
        foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        return new LlmResult(
            text: trim($text),
            json: null,
            inputTokens: (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            provider: $this->name(),
            model: $this->model,
        );
    }

    /**
     * 503 ("high demand") and 429 are both transient on a shared free
     * tier and arrive often enough that failing the whole run on one
     * would waste every page already transcribed. Retried with backoff;
     * a persistent 429 is escalated so the caller can stop cleanly.
     */
    private function request(array $payload, int $attempt = 1): array
    {
        $maxAttempts = 4;
        $url = sprintf(self::ENDPOINT, rawurlencode($this->model));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                // Header rather than a query parameter, so the key cannot
                // end up in an access log or a proxy's URL history.
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // A dropped TLS handshake or timeout is transport-level, so there
        // is no HTTP status to inspect. These are common on a long
        // generation run and must be retried, or one flaky connection
        // throws away everything the job had done.
        if ($raw === false) {
            if ($attempt < $maxAttempts) {
                sleep(2 * (3 ** ($attempt - 1)));

                return $this->request($payload, $attempt + 1);
            }

            throw new LlmException(
                "Gemini request failed after {$maxAttempts} attempts: "
                . ($curlError !== '' ? $curlError : 'connection closed')
            );
        }

        $decoded = json_decode((string) $raw, true);
        $message = $decoded['error']['message'] ?? 'unknown error';

        if (in_array($status, [429, 500, 503], true) && $attempt < $maxAttempts) {
            // 2s, 6s, 18s — long enough for a demand spike to pass.
            sleep(2 * (3 ** ($attempt - 1)));

            return $this->request($payload, $attempt + 1);
        }

        if ($status === 429) {
            throw new LlmRateLimitException("Gemini quota exceeded: {$message}");
        }

        if ($status >= 400 || !is_array($decoded)) {
            throw new LlmException("Gemini HTTP {$status}: {$message}");
        }

        return $decoded;
    }
}
