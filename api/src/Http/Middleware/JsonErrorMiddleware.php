<?php

declare(strict_types=1);

namespace Dana\Http\Middleware;

use Dana\Http\ApiException;
use Dana\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Throwable;

/**
 * Turns every failure into the bilingual JSON shape the app expects.
 *
 * An unexpected exception never reaches the client verbatim — the real
 * message goes to the log, and the caller gets a generic error. Stack
 * traces and SQL fragments are exactly the sort of thing that leaks
 * schema details to whoever is poking at the API.
 */
final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $log,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            return $this->json($e->toArray(), $e->status);
        } catch (HttpNotFoundException | HttpMethodNotAllowedException $e) {
            // A wrong URL is the caller's mistake, not a server fault —
            // it must not become a logged 500 with a stack trace. Routes
            // also get REMOVED (AI generation, teacher student-creation),
            // and an old client hitting one deserves an honest 404.
            return $this->json([
                'error' => [
                    'code'       => 'not_found',
                    'message_tk' => 'Beýle salgy ýok.',
                    'message_ru' => 'Такого адреса не существует.',
                ],
            ], $e instanceof HttpNotFoundException ? 404 : 405);
        } catch (Throwable $e) {
            $this->log->error($e->getMessage(), [
                'type'   => $e::class,
                'file'   => $e->getFile() . ':' . $e->getLine(),
                'method' => $request->getMethod(),
                'path'   => $request->getUri()->getPath(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $body = [
                'error' => [
                    'code'       => 'server_error',
                    'message_tk' => 'Serwerde ýalňyşlyk ýüze çykdy. Soňrak synanyşyň.',
                    'message_ru' => 'Ошибка сервера. Попробуйте позже.',
                ],
            ];

            // Debug detail only when explicitly enabled, never in production.
            if ($this->config->bool('APP_DEBUG')) {
                $body['error']['debug'] = [
                    'type'    => $e::class,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ];
            }

            return $this->json($body, 500);
        }
    }

    private function json(array $body, int $status): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
