<?php

declare(strict_types=1);

namespace Dana\Domain\Media;

use Dana\Support\Config;
use SensitiveParameter;

/**
 * Everything GeminiMedia needs from the environment, in one value.
 *
 * It reads Config once at construction rather than letting the service
 * hold the whole configuration: the settings are then a plain value a
 * test can build directly, which is the only way to exercise the
 * shipped default — no key at all — on a developer machine whose own
 * `.env` might have one.
 */
final class GeminiSettings
{
    public function __construct(
        #[SensitiveParameter] public readonly ?string $apiKey,
        public readonly string $ttsModel = 'gemini-3.1-flash-tts-preview',
        public readonly string $ttsVoice = 'Kore',
        public readonly string $imageModel = 'gemini-3.1-flash-image',
        public readonly string $imageAspect = '1:1',
        public readonly string $imageSize = '1K',
        public readonly int $timeout = 120,
        public readonly ?string $ttsDirective = null,
        public readonly ?string $imageDirective = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            apiKey: $config->get('GEMINI_API_KEY'),
            ttsModel: $config->get('GEMINI_TTS_MODEL', 'gemini-3.1-flash-tts-preview') ?? '',
            ttsVoice: $config->get('GEMINI_TTS_VOICE', 'Kore') ?? '',
            imageModel: $config->get('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image') ?? '',
            imageAspect: $config->get('GEMINI_IMAGE_ASPECT', '1:1') ?? '',
            imageSize: $config->get('GEMINI_IMAGE_SIZE', '1K') ?? '',
            timeout: $config->int('GEMINI_TIMEOUT', 120),
            ttsDirective: $config->get('GEMINI_TTS_DIRECTIVE'),
            imageDirective: $config->get('GEMINI_IMAGE_DIRECTIVE'),
        );
    }
}
