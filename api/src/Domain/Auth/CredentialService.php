<?php

declare(strict_types=1);

namespace Dana\Domain\Auth;

use RuntimeException;
use SensitiveParameter;

/**
 * Password storage for Dana.
 *
 * Teachers must be able to read a student's password back to them
 * (FR-1.10), so student passwords have to be recoverable. Recoverable
 * storage is weaker than hashing by definition, so this class confines
 * that weakness to one narrow, audited path and keeps it strictly
 * separate from authentication:
 *
 *   Authentication  -> bcrypt hash. Always. Never decrypts.
 *   Teacher reveal  -> AES-256-GCM ciphertext. Students only (FR-1.11).
 *
 * Because login never touches the reversible path, a defect in the
 * reveal endpoint cannot become an authentication bypass.
 *
 * The key lives in APP_CRED_KEY in api/.env — never in the database and
 * never in a backup. That separation is what makes a stolen SQL dump
 * useless on its own.
 */
final class CredentialService
{
    private const CIPHER = 'aes-256-gcm';
    private const BCRYPT_COST = 12;

    /** Raw 32-byte key. */
    private readonly string $key;

    public function __construct(#[SensitiveParameter] string $base64Key)
    {
        $raw = base64_decode($base64Key, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException(
                'APP_CRED_KEY must be exactly 32 bytes, base64-encoded. Generate one with: '
                . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        $this->key = $raw;
    }

    // ---------------------------------------------------------- auth path

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * The ONLY method any login flow may call. Constant-time via
     * password_verify.
     */
    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    // ------------------------------------------------------- reveal path

    /**
     * Encrypt a student's password for later teacher reveal.
     *
     * The user id is bound in as additional authenticated data, so a
     * ciphertext copied from one row into another fails to decrypt.
     * Row-swapping is therefore detectable, not silent.
     *
     * @return array{ct: string, iv: string, tag: string}
     */
    public function encryptFor(int $userId, #[SensitiveParameter] string $password): array
    {
        $iv = random_bytes(12);
        $tag = '';

        $ct = openssl_encrypt(
            $password,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($userId),
            16
        );

        if ($ct === false) {
            throw new RuntimeException('Credential encryption failed.');
        }

        return ['ct' => $ct, 'iv' => $iv, 'tag' => $tag];
    }

    /**
     * Reveal a student's password. Callers MUST have already checked
     * that the actor is the student's own teacher or their centre admin,
     * and MUST write the audit_log entry before returning (FR-1.12).
     */
    public function decryptFor(int $userId, string $ct, string $iv, string $tag): string
    {
        $plain = openssl_decrypt(
            $ct,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($userId)
        );

        if ($plain === false) {
            // Wrong key, tampered ciphertext, or a row-swapped record.
            throw new RuntimeException('Credential could not be decrypted.');
        }

        return $plain;
    }

    private function aad(int $userId): string
    {
        return 'dana:user:' . $userId;
    }
}
