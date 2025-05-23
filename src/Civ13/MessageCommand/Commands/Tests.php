<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "tests" command.
 */
class Tests extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $tokens = explode(' ', self::messageWithoutCommand($command, $message_filtered));
        if (empty($tokens[0])) {
            if (empty($this->civ13->tests)) return $this->civ13->reply($message, "No tests have been created yet! Try creating one with `tests add {test_key} {question}`");
            $reply = 'Available tests: `' . implode('`, `', array_keys($this->civ13->tests)) . '`';
            $reply .= PHP_EOL . 'Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`';
            return $this->civ13->reply($message, $reply);
        }
        if (! isset($tokens[1])) return $this->civ13->reply($message, 'Invalid format! You must include the name of the test, e.g. `tests list {test_key}.');
        if (! isset($this->civ13->tests[$test_key = strtolower($tokens[1])]) && $tokens[0] !== 'add') return $this->civ13->reply($message, "Test `$test_key` hasn't been created yet! Please add a question first.");
        switch ($tokens[0]) {
            case 'list':
                return $message->reply(Civ13::createBuilder()->addFileFromContent("$test_key.txt", var_export($this->civ13->tests[$test_key], true))->setContent('Number of questions: ' . count(array_keys($this->civ13->tests[$test_key]))));
            case 'delete':
                if (isset($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests delete {test_key}`"); // Prevents accidental deletion of tests
                unset($this->civ13->tests[$test_key]);
                $this->civ13->VarSave('tests.json', $this->civ13->tests);
                return $this->civ13->reply($message, "Deleted test `$test_key`");
            case 'add':
                if (! $question = implode(' ', array_slice($tokens, 2))) return $this->civ13->reply($message, 'Invalid format! Please use the format `tests add {test_key} {question}`');
                $this->civ13->tests[$test_key][] = $question;
                $this->civ13->VarSave('tests.json', $this->civ13->tests);
                return $this->civ13->reply($message, "Added question to test `$test_key`: `$question`");
            case 'remove':
                if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests remove {test_key} {question #}`");
                if (!isset($this->civ13->tests[$test_key][$tokens[2]])) return $this->civ13->reply($message, "Question not found in test `$test_key`! Please use the format `tests {test_key} remove {question #}`");
                $question = $this->civ13->tests[$test_key][$tokens[2]];
                unset($this->civ13->tests[$test_key][$tokens[2]]);
                $this->civ13->VarSave('tests.json', $this->civ13->tests);
                return $this->civ13->reply($message, "Removed question `{$tokens[2]}`: `$question`");
            case 'post':
                if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests post {test_key} {# of questions}`");
                if (count($this->civ13->tests[$test_key]) < $tokens[2]) return $this->civ13->reply($message, "Can't return more questions than exist in a test!");
                $test = $this->civ13->tests[$test_key]; // Copy the array, don't reference it
                shuffle($test);
                return $this->civ13->reply($message, implode(PHP_EOL, array_slice($test, 0, intval($tokens[2]))));
            default:
                return $this->civ13->reply($message, 'Invalid format! Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`');
        }
    }
}