<?php

namespace ErnestDefoe\SpoilerShield\Http;

use ErnestDefoe\SpoilerShield\Redactor;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every API response with a discussion or a post in it passes through the
 * Redactor on its way out. Flarum's internal API client runs this stack too,
 * so the forum's own page (its title, the preloaded data, the no-JS content)
 * and realtime pushes are covered by the same check.
 *
 * Other responses pass untouched: a cheap look at the body first.
 */
class RedactApi implements MiddlewareInterface
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly LoggerInterface $log,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (! str_contains($response->getHeaderLine('Content-Type'), 'json')) {
            return $response;
        }

        $body = (string) $response->getBody();

        // Anthology's series page lists its chapters (discussions) in plain JSON.
        $series = $request->getAttribute('routeName') === 'anthology.series.show';

        if (! $series && ! preg_match('/"type"\s*:\s*"(discussions|posts)"/', $body)) {
            return $response;
        }

        $doc = json_decode($body, true);

        if (! is_array($doc) || ! array_key_exists('data', $doc)) {
            return $response;
        }

        try {
            $actor = RequestUtil::getActor($request);
            $session = $request->getAttribute('session');
            $doc = $series
                ? $this->redactor->redactChapters($doc, $actor, $session)
                : $this->redactor->redact($doc, $actor, $session, $this->searching($request));
        } catch (Throwable $e) {
            // Fail closed: a broken check hides every title and post rather than showing one.
            $this->log->error('[spoiler-shield] '.$e->getMessage(), ['exception' => $e]);
            $doc = $this->redactor->redactAll($doc);
        }

        return $response
            ->withBody((new StreamFactory())->createStream((string) json_encode($doc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)))
            ->withoutHeader('Content-Length');
    }

    /** A search by words: the API's own `filter[q]`, which every search driver answers. */
    private function searching(ServerRequestInterface $request): bool
    {
        $filter = $request->getQueryParams()['filter'] ?? null;

        return is_array($filter) && trim((string) ($filter['q'] ?? '')) !== '';
    }
}
