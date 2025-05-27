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
use Discord\Builders\Components\MediaGallery;
use Discord\Builders\Components\Section;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\Components\Thumbnail;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

use function React\Async\await;

/**
 * Handles the "verifyme" command.
 */
class SS14Verify extends Civ13MessageCommand
{
    // Header section
    const string TITLE = 'SS14 Verification';

    // Description section
    const string DESCRIPTION_TEXT          = 'Completing this process will grant you the `@SS14 Verified` role.';
    const string DESCRIPTION_BANNER_URL    = 'https://raw.githubusercontent.com/Civ13/Civ14/refs/heads/master/Resources/Textures/Logo/splash.png';
    const string DESCRIPTION_BANNER_ALT    = 'Civilization 14 Banner';
    //const string DESCRIPTION_THUMBNAIL     = 'https://raw.githubusercontent.com/Civ13/Civ14/refs/heads/master/Resources/Textures/Logo/splash.png';
    //const string DESCRIPTION_THUMBNAIL_ALT = 'Civilization 14 Thumbnail';

    // Steps section
    const string STEP_ONE_TODO = '1. Link your Discord account.';
    const string STEP_ONE_DONE = '1. Your Discord account is linked.';
    const string STEP_TWO_TODO = '2. Link your SS14 account.';
    const string STEP_TWO_DONE = '2. Your SS14 account is linked.';
    
    // Output section
    const string INITIAL       = 'Please use the `verifyme` command again to complete the process.';
    const string ROLE_ADDED    = 'You have been granted the `@SS14 Verified` role.';
    const string ROLE_EXISTS   = 'You already have the `@SS14 Verified` role.';
    const string UNAVAILABLE   = 'SS14 verification is not available at this time.';

    // Color codes
    const string ACCENT_COLOR_DEFAULT  = 'f1c40f';
    const string ACCENT_COLOR_SUCCESS  = '2ecc71';
    const string ACCENT_COLOR_ERROR    = 'e91e63';

    // @TODO: Use the bot's configurations
    protected string $dwa_oauth_url  = 'http://www.civ13.com:16260/dwa?login';
    protected string $ss14_oauth_url = 'http://www.civ13.com:16260/ss14wa?login';

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->reply($this->createBuilder($message->member));
    }

    public function createBuilder(Member $member): MessageBuilder
    {
        return Civ13::createBuilder(true)->addComponent($this->createContainer($member));
    }

    public function createContainer(Member $member): Container
    {
        $container = Container::new()->setAccentColor(self::ACCENT_COLOR_DEFAULT);
        $ip = $this->getIPFromDiscord($member->id);
        $ss14 = $ip ? $this->getSS14FromIP($ip) : false;


        $container->addComponents([
            TextDisplay::new('# ' . self::TITLE),
            $mediagallery = MediaGallery::new(self::DESCRIPTION_BANNER_URL/*, self::DESCRIPTION_BANNER_ALT*/),
            Separator::new(),
            TextDisplay::new('## Description'),
            TextDisplay::new(self::DESCRIPTION_TEXT),
            Separator::new(),
        ]);
        var_dump($mediagallery);

        if (!isset(
            $this->civ13->ss14verifier,
            $this->civ13->role_ids['SS14 Verified']
        )) return $container->addComponent(TextDisplay::new('### ' . self::UNAVAILABLE))->setAccentColor(self::ACCENT_COLOR_ERROR);

        if ($member->roles->has(
            $this->civ13->role_ids['SS14 Verified']
        )) return $container->addComponent(TextDisplay::new('### ' . self::ROLE_EXISTS))->setAccentColor(self::ACCENT_COLOR_SUCCESS);

        if (($this->civ13->ss14verifier->getEndpoint()->getIndex($member->id)) !== false) {
            $this->addRole($member);
            return $container->addComponent(TextDisplay::new('### ' . self::ROLE_ADDED))->setAccentColor(self::ACCENT_COLOR_SUCCESS);
        }

        $container->addComponent(TextDisplay::new('## Usage'));
        $container->addComponent(TextDisplay::new(($ip)
            ? "✅ " . self::STEP_ONE_DONE
            : "❌ " . self::STEP_ONE_TODO
        ));
        if (!$ip) $container->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_LINK)
            ->setEmoji('🔗')
            ->setLabel('Link Discord')
            ->setUrl($this->dwa_oauth_url)
        ));

        $container->addComponent(TextDisplay::new(($ss14)
            ? "✅ " . self::STEP_TWO_DONE
            : "❌ " . self::STEP_TWO_TODO
        ));
        if (!$ss14) $container->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_LINK)
            ->setEmoji('🔗')
            ->setLabel('Link SS14')
            ->setUrl($this->ss14_oauth_url)
        ));

        return $container
            ->addComponents([
                Separator::new(),
                ($ip && $ss14)
                    ? TextDisplay::new('### ' . $this->process($member, $container))
                    : TextDisplay::new('### ' . self::INITIAL)
            ]);
            
    }

    /**
     * Processes the verification of a member using the SS14 verifier.
     *
     * @param Member $member
     * @return string Success message if the role is added, or an error message if verification fails.
     */
    public function process(Member $member, ?Container &$container = null): string
    {
        if (!isset($this->civ13->ss14verifier)) {
            $container->setAccentColor(self::ACCENT_COLOR_ERROR);
            return self::UNAVAILABLE; // This is already checked in createContainer, so this is just a fallback if the method is called directly.
        }
        return await($this->civ13->ss14verifier->process($member->id)->then(
            function() use ($member, $container) {
                if ($member->roles->has($this->civ13->role_ids['SS14 Verified'])) return self::ROLE_EXISTS; // This is already checked in createContainer, so this is just a fallback if the method is called directly.
                $this->addRole($member);
                if ($container) $container->setAccentColor(self::ACCENT_COLOR_SUCCESS);
                return self::ROLE_ADDED;
            },
            function(\Throwable $e) use ($container) {
                if ($container) $container->setAccentColor(self::ACCENT_COLOR_ERROR);
                return $e->getMessage();
            } 
        ));
    }

    /**
     * Assigns the "SS14 Verified" role to the specified Discord member.
     *
     * @param Member $member
     * @return PromiseInterface<Member>
     */
    protected function addRole(Member $member): PromiseInterface
    {
        return $this->civ13->addRoles($member, $this->civ13->role_ids['SS14 Verified']);
    }

    /**
     * Retrieves the IP address associated with a given Discord user ID from the current sessions.
     *
     * @param string $discord_id
     * @return string|null
     */
    protected function getIPFromDiscord(string $discord_id): ?string
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['dwa'])) return null;
        foreach ($sessions['dwa'] as $ip => $array) if (
            isset(
                $array['user'],
                $array['user']->id
            ) && ($array['user']->id == $discord_id)
        ) return $ip;
        return null;
    }

    /**
     * Retrieves the SS14 name associated with an IP address from session data.
     *
     * @param string $ip
     * @return string|null
     */
    public function getSS14FromIP(string $ip): ?string
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['ss14wa'])) return null;
        if (
            isset(
                $sessions['ss14wa'][$ip]['user'],
                $sessions['ss14wa'][$ip]['user']->name
            ) && $sessions['ss14wa'][$ip]['user']->name
        ) return $sessions['ss14wa'][$ip]['user']->name;
        return null;
    }
}