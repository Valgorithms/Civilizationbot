<?php
declare(strict_types=1);
namespace Civ13\Safehouse;

/** Pure Discord adapter boundary. Actor and interaction IDs come from Discord,
 * never command options. Responses must be delivered ephemerally by the host. */
final class Controller
{
    public function __construct(private Client $client) {}

    public static function schema(): array
    {
        return ['name' => 'safehouse', 'description' => 'Manage your game safehouse', 'type' => 1, 'contexts' => [0], 'integration_types' => [0], 'options' => [
            ['name' => 'action', 'description' => 'Game-authoritative action', 'type' => 3, 'required' => true, 'choices' => array_map(fn ($action) => ['name' => $action, 'value' => $action], Client::ACTIONS)],
            ['name' => 'payload', 'description' => 'Action JSON (confirmation token for irreversible actions)', 'type' => 3, 'required' => true],
            ['name' => 'revision', 'description' => 'Expected game account revision', 'type' => 4, 'required' => false],
            ['name' => 'confirm', 'description' => 'Confirm the reviewed game quote for an irreversible action', 'type' => 5, 'required' => false],
        ]];
    }

    public function handle(string $interactionId, string $actorDiscordId, string $action, string $json, ?int $revision): array
    {
        $request = self::prepare($interactionId, $actorDiscordId, $action, $json, $revision);
        return ['ephemeral' => true, 'outcome' => $this->client->request($interactionId, $actorDiscordId, $action, $request['payload'], $revision)];
    }

    public static function prepare(string $interactionId, string $actorDiscordId, string $action, string $json, ?int $revision): array
    {
        if (strlen($json) > 4096) {
            throw new \InvalidArgumentException('Payload too large');
        }
        $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !str_starts_with(ltrim($json), '{')) {
            throw new \InvalidArgumentException('Payload must be a JSON object');
        }
        foreach (['discord_id', 'ckey', 'account_id', 'price', 'currency', 'items'] as $authority) {
            if (array_key_exists($authority, $payload)) {
                throw new \InvalidArgumentException('Game authority cannot be supplied: '.$authority);
            }
        }
        if (in_array($action, ['stash.inspect', 'room.inspect', 'stash.detail', 'market.browse'], true)) {
            $pageMax = match ($action) { 'stash.detail' => 31, 'market.browse' => 15, default => 7 };
            if (!is_int($payload['page'] ?? null) || $payload['page'] < 0 || $payload['page'] > $pageMax) { throw new \InvalidArgumentException('Invalid observation page'); }
        }
        $fields = match ($action) {
            'link.challenge' => ['challenge_id'],
            'link.complete' => ['challenge_id', 'proof'],
            'stash.inspect', 'room.inspect' => ['page'],
            'stash.detail' => ['item_id', 'page'],
            'market.browse' => ['page'],
            'room.quote' => ['item_ids', 'operation'],
            'room.manage', 'trade.confirm', 'market.list', 'market.buy', 'market.cancel' => ['confirmation_token'],
            'trade.status' => ['operation_request_digest', 'operation_request_id'],
            'trade.quote' => match ($payload['sale_kind'] ?? null) {
                'server_buyback', 'player_listing' => ['item_ids', 'sale_kind'],
                'player_purchase', 'listing_cancel' => ['listing_id', 'sale_kind'],
                default => throw new \InvalidArgumentException('Unknown sale kind'),
            },
            default => throw new \InvalidArgumentException('Unknown action'),
        };
        $keys = array_keys($payload); sort($keys); sort($fields);
        if ($keys !== $fields) { throw new \InvalidArgumentException('Unexpected or missing action payload fields'); }
        foreach ($payload as $field => $value) {
            if ($field === 'page') { continue; }
            if ($field === 'item_ids') {
                if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 16 || count(array_unique($value, SORT_REGULAR)) !== count($value)) { throw new \InvalidArgumentException('Select 1..16 distinct root IDs'); }
                foreach ($value as $id) { if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) { throw new \InvalidArgumentException('Invalid graph root ID'); } }
            } elseif (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value)) { throw new \InvalidArgumentException('Invalid game-issued identifier'); }
        }
        if ($action === 'stash.detail' && strlen($payload['item_id']) > 80) { throw new \InvalidArgumentException('Invalid graph root ID'); }
        if ($action === 'room.quote' && $payload['operation'] !== 'upgrade') { throw new \InvalidArgumentException('Unknown room operation'); }
        if ($action === 'trade.status' && !preg_match('/^[a-f0-9]{64}$/D', $payload['operation_request_digest'])) { throw new \InvalidArgumentException('Invalid original request digest'); }
        if (in_array($action, ['trade.quote', 'room.quote'], true) && $revision === null) { throw new \InvalidArgumentException('Quote requires expected account revision'); }
        if (in_array($action, ['trade.confirm', 'market.list', 'market.buy', 'market.cancel', 'room.manage'], true) && (!is_string($payload['confirmation_token'] ?? null) || $payload['confirmation_token'] === '' || $revision === null)) {
            throw new \InvalidArgumentException('Game confirmation token and revision required');
        }
        return Client::envelope($interactionId, $actorDiscordId, $action, $payload, $revision);
    }
}
