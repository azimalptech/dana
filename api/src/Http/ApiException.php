<?php

declare(strict_types=1);

namespace Dana\Http;

use RuntimeException;

/**
 * A failure the client is meant to show to a user.
 *
 * Messages are bilingual and come from the server, so the app never has
 * to translate a backend failure — it renders whichever language matches
 * the interface setting (FR-2.6).
 *
 * TRANSLATION NOTE: the Turkmen and Russian strings below are a first
 * pass and should be reviewed by a native speaker before release.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $messageTk,
        public readonly string $messageRu,
        public readonly int $status = 400,
    ) {
        parent::__construct($errorCode);
    }

    public function toArray(): array
    {
        return [
            'error' => [
                'code'       => $this->errorCode,
                'message_tk' => $this->messageTk,
                'message_ru' => $this->messageRu,
            ],
        ];
    }

    public static function invalidCredentials(): self
    {
        // Deliberately does not distinguish "no such login" from "wrong
        // password" — that difference tells an attacker which logins exist.
        return new self(
            'invalid_credentials',
            'Login ýa-da parol nädogry.',
            'Неверный логин или пароль.',
            401
        );
    }

    public static function accountDisabled(): self
    {
        return new self(
            'account_disabled',
            'Hasabyňyz işjeň däl. Mugallymyňyza ýüz tutuň.',
            'Ваш аккаунт отключён. Обратитесь к преподавателю.',
            403
        );
    }

    public static function unauthenticated(): self
    {
        return new self(
            'unauthenticated',
            'Ulgama giriň.',
            'Необходимо войти в систему.',
            401
        );
    }

    public static function sessionExpired(): self
    {
        return new self(
            'session_expired',
            'Sessiýanyň möhleti gutardy. Täzeden giriň.',
            'Сессия истекла. Войдите снова.',
            401
        );
    }

    public static function forbidden(): self
    {
        return new self(
            'forbidden',
            'Bu amal üçin rugsadyňyz ýok.',
            'У вас нет доступа к этому действию.',
            403
        );
    }

    public static function notFound(): self
    {
        return new self(
            'not_found',
            'Tapylmady.',
            'Не найдено.',
            404
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'too_many_attempts',
            'Örän köp synanyşyk. Birazdan täzeden synanyşyň.',
            'Слишком много попыток. Повторите через несколько минут.',
            429
        );
    }

    public static function validation(string $detailTk, string $detailRu): self
    {
        return new self('validation_failed', $detailTk, $detailRu, 422);
    }
}
