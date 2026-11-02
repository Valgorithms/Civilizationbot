<?php
declare(strict_types=1);
namespace Civ13;

/** Hosted projection and transport-minted HTTPS receipt; no remote writes. */
final class HostedVerificationSource
{
    public const URL = 'http://civ13.com:55555/verified';
    public const MAX_BYTES = 4194304;

    /** Only the dedicated strict HTTPS path below can mint this receipt. Never deserialize one. */
    private function __construct(private readonly string $source, private readonly string $recordsHash) {}

    public function matches(string $source, array $records): bool
    {
        return PortableProfile::hostedVerificationUrl($source, true) && $this->source === $source
            && hash_equals($this->recordsHash, hash('sha256', json_encode($records, JSON_THROW_ON_ERROR)));
    }

    /** Dedicated transport ignores injected shared Browser/connector/proxy/TLS options. No request until called. */
    public static function fetchAuthenticated(\React\EventLoop\LoopInterface $loop, string $url): \React\Promise\PromiseInterface
    {
        if (!PortableProfile::hostedVerificationUrl($url, true)) { throw new \InvalidArgumentException('Explicit hosted HTTPS endpoint required'); }
        return self::read(self::authenticatedBrowser($loop), $url, true);
    }

    private static function authenticatedBrowser(\React\EventLoop\LoopInterface $loop): \React\Http\Browser
    {
        $connector = new \React\Socket\Connector(self::authenticatedConnectorOptions(), $loop);
        return new \React\Http\Browser($connector, $loop);
    }

    /** Pure policy seam: tests must not trigger Windows system-DNS discovery subprocesses. */
    private static function authenticatedConnectorOptions(): array
    {
        return ['timeout' => 10, 'unix' => false,
            'tls' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
    }

    public static function validate(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) { throw new \RuntimeException('Hosted verification response too large'); }
        $records = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($records) || count($records) > 50000 || !in_array(ltrim($json)[0] ?? '', ['[', '{'], true)) {
            throw new \RuntimeException('Hosted verification response is not a record collection');
        }
        $normalized = []; $accounts = []; $users = [];
        foreach ($records as $record) {
            if (!is_array($record) || !is_string($record['ss13'] ?? null) || !is_string($record['discord'] ?? null)
                || $record['ss13'] === '' || strlen($record['ss13']) > 256
                || preg_match('/[\x00-\x1F\x7F]/', $record['ss13']) === 1
                || preg_match('/^[0-9]{17,20}$/D', $record['discord']) !== 1) {
                throw new \RuntimeException('Hosted verification record contract failed');
            }
            // Match Civ13::sanitizeInput account lookups, including legacy display spelling.
            $account = str_replace(['.', '_', '-', '+', ' '], '', strtolower($record['ss13']));
            if ($account === '' || isset($accounts[$account]) || isset($users[$record['discord']])) {
                throw new \RuntimeException('Hosted verification account is empty or ambiguous');
            }
            $accounts[$account] = true; $users[$record['discord']] = true;
            $normalized[] = ['ss13' => $account, 'discord' => $record['discord']];
        }
        return $normalized;
    }

    /** Asynchronous GET only; no credentials, redirects, cache or raw response logging. */
    public static function fetch(\React\Http\Browser $browser): \React\Promise\PromiseInterface
    {
        return self::read($browser, self::URL, false);
    }

    private static function read(\React\Http\Browser $browser, string $url, bool $authenticated): \React\Promise\PromiseInterface
    {
        if ($authenticated && !PortableProfile::hostedVerificationUrl($url, true)) { throw new \InvalidArgumentException('Authenticated read cannot downgrade'); }
        $client = $browser->withTimeout(10)->withFollowRedirects(false)->withRejectErrorResponse(false)->withResponseBuffer(self::MAX_BYTES);
        $read = static function (int $attempt) use ($client, $url, $authenticated, &$read): \React\Promise\PromiseInterface {
            return $client->get($url, ['Accept' => 'application/json'])->then(
                static function (\Psr\Http\Message\ResponseInterface $response) use ($url, $authenticated): array {
                    if ($response->getStatusCode() !== 200) { return ['json' => '', 'http_status' => 0]; }
                    try {
                        $records = self::validate((string)$response->getBody());
                        return ['json' => json_encode($records, JSON_THROW_ON_ERROR), 'http_status' => 200,
                            'authority' => $authenticated ? new self($url, hash('sha256', json_encode($records, JSON_THROW_ON_ERROR))) : null];
                    } catch (\Throwable) { return ['json' => '', 'http_status' => 0]; }
                },
                static function (\Throwable $error) use ($attempt, &$read): mixed {
                    if ($attempt === 0 && !$error instanceof \OverflowException) { return $read(1); }
                    return ['json' => '', 'http_status' => 0];
                }
            );
        };
        return $read(0);
    }
}
