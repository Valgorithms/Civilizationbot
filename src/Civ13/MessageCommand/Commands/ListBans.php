<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\File;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "listbans" command.
 */
class ListBans extends Civ13MessageCommand
{
    // Header section
    public const string TITLE = 'Civ13 Bans List';

    // Description section
    public const string DESCRIPTION_TEXT = 'Retrieves the bans.txt files for locally hosted Civ13 servers.';
    
    // Output section
    public const string UNAVAILABLE = 'Files are not available at this time.';

    // Color codes
    public const string ACCENT_COLOR_DEFAULT = 'f1c40f';
    public const string ACCENT_COLOR_ERROR   = 'e91e63';

    protected Container $container;

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        //return $this->civ13->listbans($message, self::messageWithoutCommand($command, $message_filtered, true));
        return $message->reply($this->createBuilder());
    }

    public function createBuilder(): MessageBuilder
    {
        $builder = Civ13::createBuilder(true);
        return $builder->addComponent($this->createContainer($builder));
    }

    private function createContainer(MessageBuilder $builder): Container
    {
        $this->container = Container::new()->setAccentColor(self::ACCENT_COLOR_DEFAULT)->addComponents([
            TextDisplay::new('# ' . self::TITLE),
            Separator::new(),
            TextDisplay::new('## Description'),
            TextDisplay::new(self::DESCRIPTION_TEXT),
            Separator::new(),
        ]);

        foreach ($this->civ13->enabled_gameservers as $gameserver) if ($bans = $gameserver->listbans()) {
            $builder->addFileFromContent($filename = $gameserver->name . '_bans.txt', $bans);
            $this->container->addComponent(File::new($filename));
        };
        if (empty($builder->getFiles())) $this->container->addComponent(TextDisplay::new(self::UNAVAILABLE))->setAccentColor(self::ACCENT_COLOR_ERROR);

        return $this->container;
    }
}