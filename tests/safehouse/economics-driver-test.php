<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/Safehouse/Transport.php';
require __DIR__.'/../../src/Civ13/Safehouse/Client.php';
require __DIR__.'/../../src/Civ13/Safehouse/AsyncSpool.php';
use Civ13\Safehouse\AsyncSpool;
$directory = sys_get_temp_dir().'/synthetic-economics-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
$key = '0123456789abcdef0123456789abcdef';
$manifest = $directory.'/manifest.json'; file_put_contents($manifest, json_encode(['synthetic' => true, 'key' => $key, 'clock' => 1000]));
function invoke(array $arguments, bool $success = true): string {
    global $directory, $manifest;
    $pipes = []; $process = proc_open(array_merge([PHP_BINARY, __DIR__.'/economics-driver.php', $directory, $manifest], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process); if (($exit === 0) !== $success) { throw new RuntimeException('Driver result mismatch '.$error); } return trim($output);
}
function reply(string $id, array $data): void {
    global $directory, $key;
    $envelope = json_decode(file_get_contents($directory.'/'.$id.'.request'), true);
    $body = json_decode(base64_decode($envelope['body']), true);
    $body['status'] = 'committed'; $body['data'] = $data;
    file_put_contents($directory.'/'.$id.'.reply', json_encode(AsyncSpool::seal(json_encode($body), 'reply', 1000, 1120, $key)));
}
try {
    $inspect = invoke(['inspect', 'inspect-seller', '123']);
    $inspectBody = json_decode(base64_decode(json_decode(file_get_contents($directory.'/'.$inspect.'.request'), true)['body']), true);
    if ($inspectBody['payload'] !== ['page' => 0]) { throw new RuntimeException('Native inspection page missing'); }
    reply($inspect, ['revision' => 7]);
    invoke(['browse', 'browse-market', $inspect, '15']); invoke(['detail', 'detail-root', $inspect, 'native-root', '31']);
    invoke(['browse', 'invalid-page', $inspect, '16'], false); invoke(['detail', 'invalid-detail-page', $inspect, 'native-root', '32'], false);
    $roomQuote = invoke(['room-quote', 'room-quote', $inspect, '["native-part"]']);
    $roomData = ['confirm_action' => 'room.manage', 'confirmation_token' => 'native-room-token', 'expected_account_revision' => 7, 'expires_at' => '1120']; reply($roomQuote, $roomData);
    invoke(['confirm', 'room-manage', $roomQuote]);
    $roomData['confirm_action'] = 'trade.confirm'; reply($roomQuote, $roomData); invoke(['confirm', 'wrong-room-action', $roomQuote], false);
    foreach (['server_buyback' => 'trade.confirm', 'player_listing' => 'market.list', 'player_purchase' => 'market.buy', 'listing_cancel' => 'market.cancel'] as $kind => $action) {
        $selection = in_array($kind, ['server_buyback', 'player_listing'], true) ? '["native-root"]' : '"native-listing"';
        $quote = invoke(['quote', 'quote-'.$kind, $inspect, $kind, $selection]);
        $data = ['confirm_action' => $action, 'confirmation_token' => 'native-token-'.$kind, 'expected_account_revision' => 7, 'expires_at' => '1120']; reply($quote, $data);
        $confirmation = invoke(['confirm', 'confirm-'.$kind, $quote]);
        $bytes = file_get_contents($directory.'/'.$confirmation.'.request');
        if (invoke(['confirm', 'confirm-'.$kind, $quote]) !== $confirmation || file_get_contents($directory.'/'.$confirmation.'.request') !== $bytes) { throw new RuntimeException('Immutable confirmation changed'); }
        $body = json_decode(base64_decode(json_decode($bytes, true)['body']), true);
        if ($body['action'] !== $action || $body['expected_account_revision'] !== 7 || $body['payload'] !== ['confirmation_token' => $data['confirmation_token']]) { throw new RuntimeException('Quote authority replaced'); }
        $data['confirmation_token'] = 'changed'; reply($quote, $data); invoke(['confirm', 'confirm-'.$kind, $quote], false);
        $data['expires_at'] = '999'; reply($quote, $data); invoke(['confirm', 'expired-'.$kind, $quote], false);
        $data['expires_at'] = 1120; reply($quote, $data); invoke(['confirm', 'numeric-'.$kind, $quote], false);
        $data['expires_at'] = '1120'; reply($quote, $data);
        file_put_contents($directory.'/'.$quote.'.reply.pending', 'incomplete'); invoke(['confirm', 'pending-'.$kind, $quote], false); unlink($directory.'/'.$quote.'.reply.pending');
        $status = invoke(['recover', 'status-'.$kind, $confirmation]);
        $body = json_decode(base64_decode(json_decode(file_get_contents($directory.'/'.$status.'.request'), true)['body']), true);
        if ($body['action'] !== 'trade.status' || $body['payload']['operation_request_id'] !== 'confirm-'.$kind) { throw new RuntimeException('Recovery reissued trade'); }
    }
    echo "PASS synthetic economics driver quote-derived actions/tokens/revisions, immutable replay, token-conflict/expiry/type/pending negatives and status recovery\n";
} finally { foreach (glob($directory.'/*') as $path) { unlink($path); } rmdir($directory); }
