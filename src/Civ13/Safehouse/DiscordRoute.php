<?php
declare(strict_types=1);
namespace Civ13\Safehouse;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/** Receives only authenticated DiscordPHP interactions. Guild scope and actor
 * never come from command options; every message is ephemeral with no mentions. */
final class DiscordRoute
{
    public function __construct(private AsyncGateway $gateway, private string $guildId, private bool $enabled = false) {}

    public function handle(Interaction $interaction): PromiseInterface
    {
        try {
            if (!$this->enabled) { throw new \LogicException('Safehouse integration is disabled.'); }
            if ($this->guildId === '' || (string) $interaction->guild_id !== $this->guildId || !$interaction->member || !$interaction->member->id) { throw new \LogicException('Use safehouse in its configured server.'); }
            $options = $interaction->data->options;
            $action = $options['action']->value ?? '';
            $payload = $options['payload']->value ?? '{}';
            $revision = $options['revision']->value ?? null;
            if (!is_string($action) || !is_string($payload) || ($revision !== null && !is_int($revision))) { throw new \InvalidArgumentException('Invalid safehouse options.'); }
            if (in_array($action, ['trade.confirm', 'market.list', 'market.buy', 'market.cancel', 'room.manage'], true) && ($options['confirm']->value ?? false) !== true) { throw new \LogicException('Review the game quote and set confirm to true before this irreversible action.'); }
            $request = Controller::prepare((string) $interaction->id, (string) $interaction->member->id, $action, $payload, $revision);
        } catch (\Throwable $error) {
            return $interaction->respondWithMessage(self::message($error->getMessage()), true);
        }
        // Defer ephemerally before enqueuing. Failed acknowledgement must produce
        // no economic request; failed edit leaves the durable request recoverable.
        return $interaction->acknowledgeWithResponse(true)->then(function () use ($interaction, $request): PromiseInterface {
            try {
                $promise = $this->gateway->submit($request);
            } catch (\Throwable $error) {
                return $interaction->updateOriginalResponse(self::message('Request remains unresolved. Inspect its status before retrying an economic action.'));
            }
            return $promise->then(
                fn (array $outcome): PromiseInterface => $interaction->updateOriginalResponse(self::message(self::render($outcome))),
                fn (\Throwable $error): PromiseInterface => $interaction->updateOriginalResponse(self::message('Request remains unresolved. Inspect its status before retrying an economic action.'))
            );
        });
    }

    private static function message(string $content): MessageBuilder
    {
        return MessageBuilder::new()->setContent(substr($content, 0, 1900))->setAllowedMentions(['parse' => []]);
    }

    private static function render(array $outcome): string
    {
        $id = (string) ($outcome['request_id'] ?? 'unknown');
        $status = $outcome['status'] ?? 'pending';
        if ($status === 'pending') { return 'Request '.$id.' is pending. No completed trade is confirmed. Use status recovery before another economic action.'; }
        if ($status === 'rejected') { return 'Game rejected request '.$id.'. '.json_encode($outcome['data'] ?? $outcome['reason'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
        if ($status !== 'committed') { return 'Request '.$id.' remains unresolved.'; }
        return 'Game confirmed request '.$id.'. '.json_encode($outcome['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
