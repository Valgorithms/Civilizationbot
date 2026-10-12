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
        if (in_array($action, ['trade.confirm', 'market.list', 'market.buy', 'market.cancel', 'room.manage'], true) && (!is_string($payload['confirmation_token'] ?? null) || $payload['confirmation_token'] === '' || $revision === null)) {
            throw new \InvalidArgumentException('Game confirmation token and revision required');
        }
        return Client::envelope($interactionId, $actorDiscordId, $action, $payload, $revision);
    }
}
