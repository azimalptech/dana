<?php

declare(strict_types=1);

namespace Dana\Content\Llm;

/**
 * The provider refused because of quota, not because the request was
 * wrong. The worker should back off and retry rather than mark the
 * generation run failed — this is expected on a free-tier key.
 */
final class LlmRateLimitException extends LlmException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
