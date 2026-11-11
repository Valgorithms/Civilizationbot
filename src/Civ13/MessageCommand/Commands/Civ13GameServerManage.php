<?php
declare(strict_types=1);
namespace Civ13\MessageCommand\Commands;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

final class Civ13GameServerManage extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $manager = $this->gameserver->launcherManagement;
        if ($manager === null) { return $this->civ13->reply($message, 'Launcher management is disabled or unavailable for this server.'); }
        return $manager->handle((string)($message->author->id ?? ''), (string)($message->guild_id ?? ''),
            (string)($message->channel_id ?? ''), self::messageWithoutCommand($command, $message_filtered),
            (bool)($message->author->bot ?? false) || !empty($message->webhook_id)
        )->then(fn(string $line): PromiseInterface => $this->civ13->reply($message, $line));
    }
}
