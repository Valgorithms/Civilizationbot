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

    /** GET only, no credentials, proxy, redirects, persistent cache or raw response logging. */
    public static function fetch(): array
    {
        $body = ''; $handle = curl_init(self::URL);
        try {
            curl_setopt_array($handle, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            // Retry a transport timeout once; never retry HTTP/schema failures or loop indefinitely.
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $body = '';
                $success = curl_exec($handle);
                $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($success !== false || curl_errno($handle) !== CURLE_OPERATION_TIMEDOUT) { break; }
            }
            if ($success === false || $status !== 200) { return ['json' => '', 'http_status' => 0, 'failure' => 'transport_or_http', 'transport_errno' => curl_errno($handle)]; }
            return ['json' => json_encode(self::validate($body), JSON_THROW_ON_ERROR), 'http_status' => 200];
        } catch (\Throwable $error) {
            return ['json' => '', 'http_status' => 0, 'failure' => $error instanceof \JsonException ? 'invalid_json' : 'invalid_record_contract'];
        } finally { $handle = null; }
    }
}
