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
        /** The second voice in a two-person scene. */
        public readonly string $ttsVoiceB = 'Puck',
        public readonly string $imageModel = 'gemini-3.1-flash-image',
        /**
         * Landscape, because the app is: a stem image is drawn 160px
         * tall across the full width and an option tile 96px tall,
         * both with BoxFit.cover. A square source would have its top
         * and bottom cropped away in both places.
         */
        public readonly string $imageAspect = '16:9',
        public readonly string $imageSize = '1K',
        /**
         * JPEG, not PNG. The docs example shows image/png, but the
         * live API answers 400: "The value 'image/png' is not
         * supported for 'response_format.mime_type'. Supported
         * values: 'image/jpeg'." (checked 2026-09-13). Configurable
         * so the next model that does support PNG needs no release.
         */
        public readonly string $imageMime = 'image/jpeg',
        public readonly int $timeout = 120,
        public readonly ?string $ttsDirective = null,
        public readonly ?string $dialogueDirective = null,
        public readonly ?string $imageDirective = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            apiKey: $config->get('GEMINI_API_KEY'),
            ttsModel: $config->get('GEMINI_TTS_MODEL', 'gemini-3.1-flash-tts-preview') ?? '',
            ttsVoice: $config->get('GEMINI_TTS_VOICE', 'Kore') ?? '',
            ttsVoiceB: $config->get('GEMINI_TTS_VOICE_B', 'Puck') ?? '',
            imageModel: $config->get('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image') ?? '',
            imageAspect: $config->get('GEMINI_IMAGE_ASPECT', '16:9') ?? '',
            imageSize: $config->get('GEMINI_IMAGE_SIZE', '1K') ?? '',
            imageMime: $config->get('GEMINI_IMAGE_MIME', 'image/jpeg') ?? '',
            timeout: $config->int('GEMINI_TIMEOUT', 120),
            ttsDirective: $config->get('GEMINI_TTS_DIRECTIVE'),
            dialogueDirective: $config->get('GEMINI_DIALOGUE_DIRECTIVE'),
            imageDirective: $config->get('GEMINI_IMAGE_DIRECTIVE'),
        );
    }
}
