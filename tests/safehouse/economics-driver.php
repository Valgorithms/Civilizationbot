<?php
declare(strict_types=1);
// Offline synthetic native-fixture driver. No bot, credentials or game bootstrap.
foreach (['Transport', 'Client', 'AsyncSpool'] as $class) { require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php'; }
use Civ13\Safehouse\AsyncSpool;
use Civ13\Safehouse\Client;
function required(bool $ok, string $reason): void { if (!$ok) { throw new UnexpectedValueException($reason); } }
[$script, $directory, $manifestPath, $command, $requestId] = array_pad($argv, 5, null);
required(is_string($requestId), 'Usage: driver spool synthetic-manifest command request-id [arguments]');
$manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
required(($manifest['synthetic'] ?? null) === true && ($manifest['key'] ?? null) === '0123456789abcdef0123456789abcdef', 'Explicit known synthetic fixture required');
$now = $manifest['clock'] ?? null; required(is_int($now) && $now >= 0, 'Synthetic integer clock required');
$spool = new AsyncSpool($directory, $manifest['key'], true, 1000, static fn (): int => $now);
$boundReply = static function (string $key) use ($spool): array {
    $reply = $spool->poll($key);
    required(($reply['status'] ?? null) === 'committed', 'Authenticated committed source reply required');
    return $reply;
};
switch ($command) {
    case 'poll':
        echo json_encode($spool->poll($requestId), JSON_THROW_ON_ERROR)."\n"; exit;
    case 'recover':
        echo $spool->recover($argv[5] ?? '', $requestId)."\n"; exit;
    case 'inspect':
        $request = Client::envelope($requestId, $argv[5] ?? '', 'stash.inspect', ['page' => 0], null); break;
    case 'quote':
        $source = $boundReply($argv[5] ?? '');
        required(($source['action'] ?? null) === 'stash.inspect', 'Quote revision must come from inspection');
        $revision = $source['data']['revision'] ?? null;
        required(is_int($revision) && $revision >= 0 && $revision <= 1000000, 'Invalid authoritative revision');
        $kind = $argv[6] ?? '';
        $selectors = ['server_buyback' => 'item_ids', 'player_listing' => 'item_ids', 'player_purchase' => 'listing_id', 'listing_cancel' => 'listing_id'];
        required(isset($selectors[$kind]), 'Unsupported sale kind');
        $selection = json_decode($argv[7] ?? '', true, 32, JSON_THROW_ON_ERROR);
        required($selectors[$kind] === 'item_ids' ? is_array($selection) && array_is_list($selection) && $selection !== [] : is_string($selection) && $selection !== '', 'Invalid selector');
        $request = Client::envelope($requestId, $source['discord_id'], 'trade.quote', ['sale_kind' => $kind, $selectors[$kind] => $selection], $revision); break;
    case 'confirm':
        $quote = $boundReply($argv[5] ?? '');
        required(($quote['action'] ?? null) === 'trade.quote', 'Confirmation requires bound quote reply');
        $data = $quote['data'] ?? [];
        required(in_array($data['confirm_action'] ?? null, ['trade.confirm', 'market.list', 'market.buy', 'market.cancel'], true), 'Invalid quote action');
        required(is_string($data['confirmation_token'] ?? null) && $data['confirmation_token'] !== '', 'Missing authoritative token');
        required(is_int($data['expected_account_revision'] ?? null) && $data['expected_account_revision'] >= 0 && $data['expected_account_revision'] <= 1000000, 'Invalid quoted revision');
        required(is_string($data['expires_at'] ?? null) && preg_match('/^(0|[1-9][0-9]{0,10})$/D', $data['expires_at']) === 1 && (int)$data['expires_at'] >= $now, 'Quote expired or noncanonical expiry');
        $request = Client::envelope($requestId, $quote['discord_id'], $data['confirm_action'], ['confirmation_token' => $data['confirmation_token']], $data['expected_account_revision']); break;
    default: throw new InvalidArgumentException('Unknown synthetic command');
}
// enqueue retains exact immutable bytes, rejects collisions and never overwrites.
echo $spool->enqueue($request)."\n";
