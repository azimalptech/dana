<?php

declare(strict_types=1);

namespace Dana\Support\Media;

use Dana\Http\ApiException;
use Dana\Support\Config;

/**
 * Where question media lives and how a filename resolves to a path
 * (docs/06-CONTENT-V2.md §2): `STORAGE_PATH/media/q{questionId}-{part}.{ext}`,
 * served through the authenticated `GET /media/{name}` route.
 *
 * The store sits OUTSIDE the web root (STORAGE_PATH), so the ONLY way to
 * read a file is through the route — which means resolve() is the single
 * choke point where a traversal attempt (`../../etc/passwd`,
 * `..\\secret`, an absolute path) must be refused. A served name is a
 * bare basename of a strict character set and nothing else.
 */
final class MediaStorage
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Resolves STORAGE_PATH (relative paths are taken against the api
     * root, matching bin/ingest_book.php) and appends `/media`.
     */
    public static function fromConfig(Config $config, ?string $apiRoot = null): self
    {
        $raw = $config->get('STORAGE_PATH', '../storage') ?? '../storage';
        $root = $apiRoot ?? dirname(__DIR__, 3); // src/Support/Media -> api

        $base = self::isAbsolute($raw)
            ? $raw
            : rtrim($root, '/\\') . '/' . $raw;

        return new self(rtrim($base, '/\\') . '/media');
    }

    /** The absolute media directory (created on demand by writers). */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * The absolute path a served name maps to. A name is accepted only
     * when it is a bare basename of `[A-Za-z0-9._-]` — so it can carry no
     * directory separator, no `..`, no drive letter. Anything else is a
     * 404 (never a hint that the guard fired).
     */
    public function resolve(string $name): string
    {
        $name = trim($name);

        if ($name === ''
            || $name !== basename($name)
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw ApiException::notFound();
        }

        return $this->dir . '/' . $name;
    }

    private static function isAbsolute(string $path): bool
    {
        // A leading slash/backslash, or a Windows drive letter.
        return preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path) === 1;
    }
}
