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
                return $interaction->updateOriginalResponse(self::message(self::render(['status' => 'pending', 'request_id' => $request['request_id']], $request)));
            }
            return $promise->then(
                fn (array $outcome): PromiseInterface => $interaction->updateOriginalResponse(self::message(self::render($outcome, $request))),
                fn (\Throwable $error): PromiseInterface => $interaction->updateOriginalResponse(self::message(self::render(['status' => 'pending', 'request_id' => $request['request_id']], $request)))
            );
        });
    }

    private static function message(string $content): MessageBuilder
    {
        // Bound Unicode codepoints without splitting UTF-8. 900 supplementary
        // codepoints are at most 1800 UTF-16 units for Discord's 2000 limit.
        if (!preg_match('/^.{0,900}/us', $content, $match)) { $match = ['Request remains unresolved.']; }
        return MessageBuilder::new()->setContent($match[0])->setAllowedMentions(['parse' => []]);
    }

    private static function render(array $outcome, array $request): string
    {
        $id = (string) ($outcome['request_id'] ?? 'unknown');
        $status = $outcome['status'] ?? 'pending';
        if ($status === 'pending') { return 'Request '.$id.' is pending. No completed trade is confirmed. Do not submit another economic operation. Use trade.status with payload '.json_encode(['operation_request_id' => $request['request_id'], 'operation_request_digest' => $request['request_digest']], JSON_THROW_ON_ERROR); }
        if ($status === 'rejected') { return 'Game rejected request '.$id.'. '.json_encode($outcome['data'] ?? $outcome['reason'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
        if ($status !== 'committed') { return 'Request '.$id.' remains unresolved.'; }
        if ($request['action'] === 'insurance.inspect') {
            try { $data = Controller::insuranceProjection($outcome['data'] ?? []); }
            catch (\Throwable $error) { return 'Insurance inspection completed, but its projection is invalid. No eligibility or claim is confirmed.'; }
            return 'Insurance returns: game-reported pending or eligible state. Pending returns remain locked through one additional full round closure. No Discord claim action is available. Expiration: to be implemented. '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($request['action'] === 'trade.status') {
            $operationStatus = $outcome['data']['operation_status'] ?? null;
            if (!in_array($operationStatus, ['pending', 'committed', 'rejected', 'not_found'], true)) { return 'Status query completed, but the original operation remains unresolved.'; }
            if ($operationStatus === 'pending' || $operationStatus === 'not_found') { return 'Status query completed. Original operation is '.$operationStatus.'. No completed trade is confirmed. Do not issue another economic operation; retain its original request for recovery.'; }
            $receipt = $outcome['data']['operation_receipt'] ?? null;
            if (!is_array($receipt) || ($receipt['request_id'] ?? null) !== $request['payload']['operation_request_id'] || ($receipt['request_digest'] ?? null) !== $request['payload']['operation_request_digest'] || ($receipt['discord_id'] ?? null) !== $request['discord_id'] || ($receipt['status'] ?? null) !== $operationStatus) { return 'Status query completed, but its original receipt binding is unresolved.'; }
            return 'Original operation '.$receipt['request_id'].' is '.$operationStatus.'. '.json_encode($receipt['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return 'Game confirmed request '.$id.'. '.json_encode($outcome['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
