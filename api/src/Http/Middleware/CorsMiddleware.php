<?php

declare(strict_types=1);

namespace Dana\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-origin access for the admin panel (FR-15.24).
 *
 * Until the client asked for split subdomains the panel was served by
 * the same host as the API, so its fetches were same-origin and this
 * file did not need to exist — `deploy/apache-dana.conf.example` said as
 * much, and said a split layout "would need backend changes this guide
 * deliberately avoids". This is those changes.
 *
 * **An allowlist, never `*`.** The wildcard is the usual shortcut and is
 * wrong here twice over: these replies carry one student's progress and,
 * on the FR-1.10 reveal path, a decrypted credential. `*` would let any
 * page on the internet read both from a browser that happens to hold a
 * session. Origins come from `CORS_ALLOWED_ORIGINS` in `api/.env`,
 * comma-separated, compared EXACTLY — no suffix matching, because
 * "ends with mydana.app" also accepts `evil-mydana.app`.
 *
 * **No `Access-Control-Allow-Credentials`.** Dana authenticates with a
 * bearer token the panel puts in a header, not with a cookie, so the
 * browser never needs to attach ambient credentials and this API never
 * needs to invite them. That also keeps the allowlist from becoming a
 * CSRF surface.
 *
 * **Unset means off.** With no `CORS_ALLOWED_ORIGINS` the middleware
 * adds no headers at all, so a single-origin deployment behaves exactly
 * as it did before this file existed.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * Methods the API actually routes. Sent on the preflight so a
     * browser can cache one answer for every endpoint.
     */
    private const METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';

    /**
     * `Authorization` carries the bearer token and `Content-Type` the
     * JSON body — both are non-simple headers, so a preflight fires and
     * both must be named here or the browser blocks the real request.
     */
    private const HEADERS = 'Authorization, Content-Type, Accept, X-Requested-With';

    /** @var list<string> */
    private array $allowed;

    /**
     * @param string $origins comma-separated allowlist, as configured
     */
    public function __construct(
        string $origins,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
        $this->allowed = array_values(array_filter(array_map(
            static fn (string $o): string => rtrim(trim($o), '/'),
            explode(',', $origins),
        ), static fn (string $o): bool => $o !== ''));
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $origin = $this->originOf($request);

        // A preflight is answered here and never reaches routing: there
        // is no OPTIONS route on any endpoint, so letting it through
        // would raise 405 and the browser would refuse the real request
        // that follows.
        if (strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method')
        ) {
            $response = $this->responseFactory->createResponse(204);

            return $origin === null
                ? $this->vary($response)
                : $this->vary($this->allow($response, $origin))
                    ->withHeader('Access-Control-Allow-Methods', self::METHODS)
                    ->withHeader('Access-Control-Allow-Headers', self::HEADERS)
                    // A day. The allowlist changes with a deploy, not
                    // between requests, so re-asking per call is waste.
                    ->withHeader('Access-Control-Max-Age', '86400');
        }

        $response = $handler->handle($request);

        return $origin === null
            ? $this->vary($response)
            : $this->vary($this->allow($response, $origin));
    }

    /**
     * The request's `Origin`, if it is one we allow. Null otherwise —
     * including for same-origin requests, which send no Origin at all
     * and need no headers.
     */
    private function originOf(ServerRequestInterface $request): ?string
    {
        $origin = rtrim(trim($request->getHeaderLine('Origin')), '/');

        if ($origin === '' || $this->allowed === []) {
            return null;
        }

        return in_array($origin, $this->allowed, true) ? $origin : null;
    }

    private function allow(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Origin', $origin);
    }

    /**
     * `Vary: Origin` on EVERY reply, including the ones we do not allow.
     *
     * The response differs by request origin, so without it a shared
     * cache that stored the answer given to the panel could replay it,
     * Allow-Origin header and all, to a request from somewhere else —
     * and a cache that stored a header-less answer could hide the
     * headers from the panel. `Cache-Control: no-store` already covers
     * the first case for this API, but the correctness of this header
     * should not depend on another one staying set.
     */
    private function vary(ResponseInterface $response): ResponseInterface
    {
        $existing = $response->getHeaderLine('Vary');

        if ($existing === '') {
            return $response->withHeader('Vary', 'Origin');
        }

        return str_contains(strtolower($existing), 'origin')
            ? $response
            : $response->withHeader('Vary', $existing . ', Origin');
    }
}
