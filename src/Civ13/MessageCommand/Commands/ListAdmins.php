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
 * Handles the "adminlist" command.
 */
class ListAdmins extends Civ13MessageCommand
{
    // Header section
    protected const string TITLE = 'Civ13 Admins List';

    // Description section
    protected const string DESCRIPTION_TEXT = 'Retrieves the admins.txt files for locally hosted Civ13 servers.';

    // Output section
    protected const string UNAVAILABLE = 'Files are not available at this time.';

    // Color codes
    protected const string ACCENT_COLOR_DEFAULT = 'f1c40f';
    protected const string ACCENT_COLOR_ERROR   = 'e91e63';

    protected Container $container;

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        /*return $message->reply(
            array_reduce($this->civ13->enabled_gameservers, static fn($builder, $gameserver) =>
                file_exists($path = $gameserver->basedir . Civ13::admins)
                    ? $builder->addFile($path, $gameserver->key . '_admins.txt')
                    : $builder,
                Civ13::createBuilder()->setContent('Admin Lists')));*/
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

        foreach ($this->civ13->enabled_gameservers as $gameserver) if ($admins = $gameserver->listadmins()) {
            $builder->addFileFromContent($filename = $gameserver->name . '_admins.txt', $admins);
            $this->container->addComponent(File::new($filename));
        };
        if (empty($builder->getFiles())) $this->container->addComponent(TextDisplay::new('### ' . self::UNAVAILABLE))->setAccentColor(self::ACCENT_COLOR_ERROR);

        return $this->container;
    }
}