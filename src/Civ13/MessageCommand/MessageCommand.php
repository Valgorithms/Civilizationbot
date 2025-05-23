<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand;

use Civ13\Civ13;
use Civ13\MessageHandlerCallback;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

class MessageCommand implements MessageCommandInterface
{
    protected \Closure|null $closure;
    
    /**
     * Creates a new instance of the current class.
     *
     * Optionally accepts a closure or callable to be set as a callback.
     *
     * @param \Closure|callable|null $callback The closure or callable to be set as a callback.
     * @return static
     */
    public function new(\Closure|callable|null $callback = null): static
    {
        $new = new static();
        $new->setCallback($callback);
        return $new;
    }

    /**
     * Handles the invocation of a message command.
     *
     * @param Message $message The message object containing the command.
     * @param string $command The command string to be executed.
     * @param array $message_filtered The filtered message arguments.
     * @return PromiseInterface A promise that is rejected with an exception indicating the command is not implemented.
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return isset($this->closure)
            ? call_user_func($this->closure, $message, $command, $message_filtered)
            : reject(new \Exception("Command not implemented"));
    }

    public static function messageWithoutCommand(string $command, array $message_filtered, bool $lower = false, bool $sanitize = false): string
    {
        return $sanitize
            ? Civ13::sanitizeInput(trim(substr($lower ? $message_filtered['message_content_lower'] : $message_filtered['message_content'], strlen($command))))
            : trim(substr($lower ? $message_filtered['message_content_lower'] : $message_filtered['message_content'], strlen($command)));
    }

    public function setCallback(\Closure|callable|null $closure = null): void
    {
        if (is_callable($closure)) {
            MessageHandlerCallback::validate($closure, true);
            $this->closure = is_object($closure)
                ? \Closure::fromCallable([$closure, '__invoke'])
                : \Closure::fromCallable($closure);
        } else $this->closure = $closure;
    }

    public function getCallback(): \Closure
    {
        return $this->closure;
    }

    public function __debugInfo(): array
    {
        return [
            'class' => get_class($this),
            'methods' => get_class_methods($this),
        ];
    }
}