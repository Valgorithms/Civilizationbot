<?php
declare(strict_types=1);
namespace Civ13;

/** The hosted bot's existing public read projection; never an authority writer. */
final class HostedVerificationSource
{
    public const URL = 'http://civ13.com:55555/verified';
    public const MAX_BYTES = 4194304;

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
        $client = $browser->withTimeout(10)->withFollowRedirects(false)->withRejectErrorResponse(false)->withResponseBuffer(self::MAX_BYTES);
        $read = static function (int $attempt) use ($client, &$read): \React\Promise\PromiseInterface {
            return $client->get(self::URL, ['Accept' => 'application/json'])->then(
                static function (\Psr\Http\Message\ResponseInterface $response): array {
                    if ($response->getStatusCode() !== 200) { return ['json' => '', 'http_status' => 0]; }
                    try { return ['json' => json_encode(self::validate((string)$response->getBody()), JSON_THROW_ON_ERROR), 'http_status' => 200]; }
                    catch (\Throwable) { return ['json' => '', 'http_status' => 0]; }
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
