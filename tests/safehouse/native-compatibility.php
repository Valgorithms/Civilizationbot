<?php
declare(strict_types=1);
// Reads a synthetic native fixture only; never starts a bot or game.
foreach (['Transport', 'Client', 'AsyncSpool'] as $class) { require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php'; }
use Civ13\Safehouse\AsyncSpool;
function verify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function rejected(callable $call): void { try { $call(); } catch (Throwable $error) { return; } throw new RuntimeException('Alteration accepted'); }
$source = $argv[1] ?? throw new InvalidArgumentException('Synthetic fixture path required');
$manifest = json_decode(file_get_contents($source.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
verify($manifest['synthetic'] === true && $manifest['protocol'] === AsyncSpool::IPC_PROTOCOL, 'Fixture trust metadata mismatch');
verify($manifest['clock'] === 1000 && $manifest['issued_at'] === 1000 && $manifest['expires_at'] === 1120, 'Fixture time mismatch');
verify(array_keys($manifest['requests']) === ['inspect-1', 'link-1'], 'Fixture request set mismatch');
$temporary = sys_get_temp_dir().'/safehouse-native-'.bin2hex(random_bytes(8)); mkdir($temporary, 0700);
$now = 1000;
$spool = new AsyncSpool($temporary, $manifest['key'], true, 10, static function () use (&$now): int { return $now; });
try {
    foreach ($manifest['requests'] as $requestId => $fileKey) {
        verify(hash('sha256', $requestId) === $fileKey, 'Native filename digest mismatch');
        $original = file_get_contents($source.'/before-restart/'.$fileKey.'.request');
        verify($original === file_get_contents($source.'/after-restart/'.$fileKey.'.request'), 'Recovery changed immutable request');
        $requestEnvelope = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
        verify($requestEnvelope['issued_at'] === 1000 && $requestEnvelope['expires_at'] === 1120, 'Recovery re-signed original timewindow');
        foreach (['request', 'reply', 'reply.pending'] as $suffix) { copy($source.'/before-restart/'.$fileKey.'.'.$suffix, $temporary.'/'.$fileKey.'.'.$suffix); }
        verify($spool->poll($fileKey)['status'] === 'pending', 'Unpublished native reply accepted');
        copy($source.'/after-restart/'.$fileKey.'.reply', $temporary.'/'.$fileKey.'.reply');
        verify($spool->poll($fileKey)['status'] === 'pending', 'Complete native reply bypassed marker');
        unlink($temporary.'/'.$fileKey.'.reply.pending');
        $committed = $spool->poll($fileKey);
        verify($committed['status'] === 'committed' && $committed['request_id'] === $requestId, 'Native recovery receipt incompatible');
        $replyPath = $temporary.'/'.$fileKey.'.reply';
        $replyBytes = file_get_contents($replyPath);
        $replyEnvelope = json_decode($replyBytes, true, 32, JSON_THROW_ON_ERROR);
        $altered = $replyEnvelope; $altered['mac'] = str_repeat('0', 64); file_put_contents($replyPath, json_encode($altered));
        rejected(fn () => $spool->poll($fileKey));
        $alteredBody = json_decode(base64_decode($replyEnvelope['body'], true), true, 32, JSON_THROW_ON_ERROR);
        $alteredBody['discord_id'] = '999';
        file_put_contents($replyPath, json_encode(AsyncSpool::seal(json_encode($alteredBody), 'reply', 1000, 1120, $manifest['key'])));
        rejected(fn () => $spool->poll($fileKey));
        file_put_contents($replyPath, $replyBytes);
        $now = 2000; verify($spool->poll($fileKey)['status'] === 'pending', 'Expired native receipt accepted'); $now = 1000;
        verify(file_get_contents($temporary.'/'.$fileKey.'.request') === $original, 'Bot changed native operation');
        echo "PASS native $requestId marker/recovery/expiry/immutable-request/MAC/actor-binding\n";
    }
} finally {
    foreach (glob($temporary.'/*') as $path) { unlink($path); } rmdir($temporary);
}
