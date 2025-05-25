<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

use function React\Async\await;

/**
 * Handles the "verifyme" command.
 */
class SS14Verify extends Civ13MessageCommand
{
    const string COMMAND_TITLE        = 'SS14 Verification';
    const string STRING_DESCRIPTION   = 'Completing this process will grant you the `SS14 Verified` role.';
    const string STRING_STEP_ONE_TODO = '1. Link your Discord account.';
    const string STRING_STEP_ONE_DONE = '1. Your Discord account is linked.';
    const string STRING_STEP_TWO_TODO = '2. Link your SS14 account.';
    const string STRING_STEP_TWO_DONE = '2. Your SS14 account is linked.';
    const string STRING_INSTRUCTIONS  = 'Please use the `verifyme` command again to complete the process.';

    protected string $dwa_oauth_url  = 'http://www.civ13.com:16260/dwa?login';
    protected string $ss14_oauth_url = 'http://www.civ13.com:16260/ss14wa?login';

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->reply($builder = $this->createBuilder($message->member));
    }

    public function createBuilder(Member $member): MessageBuilder
    {
        return Civ13::createBuilder(true)->addComponent($this->createContainer($member));
    }

    public function createContainer(Member $member): Container
    {
        $container = Container::new();
        $ip = $this->getIPFromDiscord($member->id);
        $ss14 = $ip ? $this->checkSessionForSS14($ip) : false;
        
        $container->addComponents([
            TextDisplay::new('# ' . self::COMMAND_TITLE),
            Separator::new(),
            TextDisplay::new(self::STRING_DESCRIPTION),
            Separator::new(),
        ]);
        
        $container->addComponent(TextDisplay::new(($ip)
            ? "✅ " . self::STRING_STEP_ONE_DONE
            : "❌ " . self::STRING_STEP_ONE_TODO
        ));
        if (!$ip) $container->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_LINK)
            ->setEmoji('🔗')
            ->setLabel('Link Discord')
            ->setUrl($this->dwa_oauth_url)
        ));

        $container->addComponent(TextDisplay::new(($ss14)
            ? "✅ " . self::STRING_STEP_TWO_DONE
            : "❌ " . self::STRING_STEP_TWO_TODO
        ));
        if (!$ss14) $container->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_LINK)
            ->setEmoji('🔗')
            ->setLabel('Link SS14')
            ->setUrl($this->ss14_oauth_url)
        ));

        return $container
            ->addComponent(Separator::new())
            ->addComponent(($ip && $ss14)
                ? TextDisplay::new($this->process($member, null))
                : TextDisplay::new(self::STRING_INSTRUCTIONS)
            );
    }

    protected function getIPFromDiscord(string $discord_id): ?string
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['dwa'])) return null;
        foreach ($sessions['dwa'] as $ip => $array) {
            if (!isset($array['user']->id)) continue;
            if ($array['user']->id == $discord_id) return $ip;
        }
        return null;
    }

    public function checkSessionForSS14(string $requesting_ip): ?string
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['ss14wa'])) return null;
        if (
            !isset(
                $sessions['ss14wa'][$requesting_ip]['user'],
                $sessions['ss14wa'][$requesting_ip]['user']->name
            ) || ! $ss14 = $sessions['ss14wa'][$requesting_ip]['user']->name
        ) return null;
        return $ss14;
    }

    protected function process(Member $member): string
    {
        if (isset($this->civ13->ss14verifier, $this->civ13->role_ids['SS14 Verified'])) return await($this->civ13->ss14verifier->process($member->id)->then(
            function() use ($member) {
                $this->addRole($member);
                return "The SS14 Verified role has been added.";
            },
            fn(\Throwable $e) => $this->errorResponse($e)
        ));
        return $this->verifierUnavailableResponse();
    }

    protected function getSession()
    {
        return $this->civ13->verifier_server->getSessions();
    }

    protected function addRole(Member $member): PromiseInterface
    {
        return $this->civ13->addRoles($member, $this->civ13->role_ids['SS14 Verified']);
    }

    public function verifierUnavailableResponse(): string
    {
        return 'SS14 verification is not available at this time.';
    }

    public function errorResponse(\Throwable $e): string
    {
        return $e->getMessage();
    }

    public function respondWithMessage(Interaction $interaction, MessageBuilder|string $content, bool $ephemeral = false, string $file_name = 'message.txt') : PromiseInterface
    {
        if ($content instanceof MessageBuilder) return $interaction->respondWithMessage($content, $ephemeral);
        $builder = Civ13::createBuilder();
        if (strlen($content)<=2000) return $interaction->respondWithMessage($builder->setContent($content), $ephemeral);
        if (strlen($content)<=4096) return $interaction->respondWithMessage($builder->addEmbed($this->civ13->createEmbed()->setDescription($content)), $ephemeral);
        return $interaction->respondWithMessage($builder->addFileFromContent($file_name, $content), $ephemeral);
    }
}