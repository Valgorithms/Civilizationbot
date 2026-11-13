<?php

declare(strict_types=1);

namespace Civ13;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

/** A failed request must not shut down the HTTP listener or the Discord client. */
final class HttpErrorBoundary
{
    /** @param list<string> $secrets Known credentials that must never enter diagnostics. */
    public function __construct(private LoggerInterface $logger, private array $secrets = []) {}

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface|PromiseInterface
    {
        try {
            $response = $next($request);
            if ($response instanceof PromiseInterface) {
                return $response->then(
                    fn ($value) => $this->response($value, $request),
                    fn (\Throwable $error) => $this->failure($error, $request)
                );
            }
            return $this->response($response, $request);
        } catch (\Throwable $error) {
            return $this->failure($error, $request);
        }
    }

    private function response(mixed $response, ServerRequestInterface $request): ResponseInterface
    {
        if (!$response instanceof ResponseInterface) {
            return $this->failure(new \UnexpectedValueException('HTTP handler returned '.get_debug_type($response).' instead of ResponseInterface'), $request);
        }
        return $response;
    }

    private function failure(\Throwable $error, ServerRequestInterface $request): ResponseInterface
    {
        $this->report($error, $request);
        return new Response(Response::STATUS_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain'], 'Internal Server Error');
    }

    /** React's error event supplies only the exception, not a request. Never restart here. */
    public function report(\Throwable $error, ?ServerRequestInterface $request = null): void
    {
        if (str_starts_with($error->getMessage(), 'Received request with invalid protocol version')) {
            return;
        }
        // No query, headers, body, or trace arguments: any of these may contain credentials or chat.
        $message = '[WEBAPI]';
        if ($request !== null) {
            $message .= ' '.$this->redact($request->getMethod().' '.$request->getUri()->getPath(), 512);
        }
        for ($depth = 0; $error !== null && $depth < 8; $error = $error->getPrevious(), ++$depth) {
            $message .= "\n".($depth === 0 ? '' : 'Caused by: ').get_class($error).': '.$this->redact($error->getMessage(), 2048);
            $message .= ' ['.$this->redact($error->getFile(), 512).':'.$error->getLine().']';
            // getTraceAsString() can include function arguments depending on PHP configuration.
            foreach (array_slice($error->getTrace(), 0, 20) as $index => $frame) {
                $message .= "\n#".$index.' '.$this->redact((string) ($frame['file'] ?? '[internal]'), 512).':'.($frame['line'] ?? 0);
                $message .= ' '.$this->redact(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''), 256).'()';
            }
        }
        try {
            $this->logger->error($message);
        } catch (\Throwable) {
            // A broken log sink must not turn an otherwise contained request into another rejection.
        }
    }

    private function redact(string $value, int $limit): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $value = str_replace(array_unique([$secret, rawurlencode($secret), urlencode($secret)]), '[redacted]', $value);
            }
        }
        // Drop entire URL/request-target queries, including nested JSON and encoded query keys.
        $value = preg_replace('/\?[^\s]*/', '?[redacted]', $value) ?? '[redacted]';
        $value = preg_replace('/\b(Bearer|Bot)\s+\S+/i', '$1 [redacted]', $value) ?? '[redacted]';
        $credentials = <<<'REGEX'
/\b((?:[a-z0-9_-]*[_-])?(?:key|token|password|secret)|authorization)["']?\s*[:=]\s*(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\s,;]+)/i
REGEX;
        $value = preg_replace($credentials, '$1=[redacted]', $value) ?? '[redacted]';
        $value = preg_replace('~(https?://)[^/\s@]+@~i', '$1[redacted]@', $value) ?? '[redacted]';
        $value = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '[redacted]';
        return strlen($value) > $limit ? substr($value, 0, $limit).' [truncated]' : $value;
    }
}
