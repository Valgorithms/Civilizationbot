<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Permissions\RolePermission;
use Discord\Parts\User\Member;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * `/staffpanel` and `/playerpanel` — Components V2 dashboards that put the
 * commonly-used bot actions one click away instead of behind a dozen slash
 * commands. Both replies are ephemeral; every button re-checks permission on
 * click, so a panel left open in scrollback can't be used by someone who
 * shouldn't.
 *
 * Staff: host / restart / kill each game server, panic bunker, ban & admin list
 * refreshes, log cleanup, `git pull` / `composer update`.
 * Player: verification status + a "Verify" modal, role resync, personal rank and
 * ban lookup, live server status, campaign faction join.
 *
 * @since 1.0.0
 */
final class Panels
{
    public Civ13 $civ13;

    public Discord $discord;

    public Logger $logger;

    private bool $setup = false;

    public function __construct(Civ13 &$civ13)
    {
        $this->civ13 = &$civ13;
        $this->discord = &$civ13->discord;
        $this->logger = &$civ13->logger;

        $fn = function (): void {
            $this->registerCommands();
            $this->registerListeners();
        };
        $this->civ13->ready ? $fn() : $this->discord->once('init', $fn);
    }

    // --- command registration ------------------------------------------

    private function registerCommands(): void
    {
        if ($this->setup) {
            return;
        }
        $this->setup = true;

        $this->discord->application->commands->freshen()->then(function (GlobalCommandRepository $commands): void {
            if (! $commands->get('name', 'staffpanel')) {
                $commands->save(new Command($this->discord, [
                    'name' => 'staffpanel',
                    'description' => 'Open the staff control panel (servers, panic bunker, list refreshes).',
                    'contexts' => [Interaction::CONTEXT_TYPE_GUILD],
                    'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
                ]));
            }
            if (! $commands->get('name', 'playerpanel')) {
                $commands->save(new Command($this->discord, [
                    'name' => 'playerpanel',
                    'description' => 'Open your personal panel: verify, roles, rank, bans, server status.',
                    'contexts' => [Interaction::CONTEXT_TYPE_GUILD],
                ]));
            }
        }, fn (\Throwable $e) => $this->logger->error('[panels] command registration: '.$e->getMessage()));
    }

    private function registerListeners(): void
    {
        $this->discord->listenCommand('staffpanel', function (Interaction $i): PromiseInterface {
            if (! $this->isStaff($i->member)) {
                return $i->respondWithMessage($this->plain('You need a staff role or `Moderate Members` to open this.'), true);
            }

            return $i->respondWithMessage($this->staffPanel(), true);
        });

        $this->discord->listenCommand('playerpanel', fn (Interaction $i): PromiseInterface => $i->respondWithMessage($this->playerPanel($i->member), true));
    }

    // --- staff panel -------------------------------------------------

    private function staffPanel(): MessageBuilder
    {
        $servers = $this->civ13->enabled_gameservers;
        $components = [
            TextDisplay::new("## 🛠️ Staff Panel\nPanic bunker: **".($this->civ13->panic_bunker ? 'ON' : 'off').'** · '
                .count($servers).' game server(s) · '.count($this->civ13->verifier->getVerified()).' verified'),
            Separator::new(),
        ];

        foreach ($servers as $key => $server) {
            $components[] = TextDisplay::new("**{$server->name}** · `{$key}` · <byond://{$server->ip}:{$server->port}>");
            $components[] = ActionRow::new()->addComponents([
                $this->staffButton(Button::success(), '▶️ Host', "sp_host_{$key}", fn (Interaction $i) => $this->serverAction($i, $key, 'Host')),
                $this->staffButton(Button::primary(), '⟳ Restart', "sp_restart_{$key}", fn (Interaction $i) => $this->serverAction($i, $key, 'Restart')),
                $this->staffButton(Button::danger(), '⏹️ Kill', "sp_kill_{$key}", fn (Interaction $i) => $this->serverAction($i, $key, 'Kill')),
                $this->staffButton(Button::secondary(), '🗺️ Map', "sp_map_{$key}", fn (Interaction $i) => $this->promptMapSwap($i, $key)),
                $this->staffButton(Button::secondary(), '📊 Status', "sp_status_{$key}", fn (Interaction $i) => $this->isStaff($i->member)
                    ? $i->respondWithMessage($this->civ13->createServerstatusEmbed(), true)
                    : $this->deny($i)),
            ]);
        }

        $components[] = Separator::new();
        $components[] = ActionRow::new()->addComponents([
            $this->staffButton(Button::danger(), '🚨 Panic Bunker', 'sp_panic', function (Interaction $i) {
                $this->civ13->panic_bunker = ! $this->civ13->panic_bunker;

                return $i->updateMessage($this->staffPanel());
            }),
            $this->staffButton(Button::secondary(), '📋 Update Bans', 'sp_bans', fn (Interaction $i) => $this->bg($i, function () {
                foreach ($this->civ13->enabled_gameservers as $s) {
                    $s->updateBanCache();
                }
            }, 'Ban cache refresh queued.')),
            $this->staffButton(Button::secondary(), '👮 Update Admins', 'sp_admins', fn (Interaction $i) => $this->bg($i, function () {
                foreach ($this->civ13->enabled_gameservers as $s) {
                    $s->adminlistUpdate();
                }
            }, 'Admin list refresh queued.')),
            $this->staffButton(Button::secondary(), '🧹 Cleanup Logs', 'sp_logs', fn (Interaction $i) => $this->bg($i, function () {
                foreach ($this->civ13->enabled_gameservers as $s) {
                    $s->cleanupLogs();
                }
            }, 'Log cleanup queued.')),
            $this->staffButton(Button::primary(), '↻ Refresh', 'sp_refresh', fn (Interaction $i) => $i->updateMessage($this->staffPanel())),
        ]);
        $components[] = ActionRow::new()->addComponents([
            $this->staffButton(Button::secondary(), '⬇️ Git Pull', 'sp_pull', fn (Interaction $i) => $this->bg($i, fn () => OSFunctions::execInBackground('git pull'), 'Pulling latest code…')),
            $this->staffButton(Button::secondary(), '📦 Composer Update', 'sp_composer', fn (Interaction $i) => $this->bg($i, fn () => OSFunctions::execInBackground('composer update'), 'Updating dependencies…')),
        ]);
        $components[] = Separator::new();
        $components[] = TextDisplay::new('-# Destructive actions run immediately. Every button re-checks your permission.');

        return $this->container($components);
    }

    /** Host / Restart / Kill one game server, then refresh the panel. */
    private function serverAction(Interaction $i, string $key, string $method): PromiseInterface
    {
        if (! $this->isStaff($i->member)) {
            return $this->deny($i);
        }
        $server = $this->civ13->enabled_gameservers[$key] ?? null;
        if ($server === null) {
            return $i->respondWithMessage($this->plain("No enabled server `{$key}`."), true);
        }

        $server->{$method}(null, true);
        $this->logger->info("[panels] {$method} {$key} by {$i->user->id}");

        return $i->respondWithMessage($this->plain("`{$method}` sent to **{$server->name}** (`{$key}`)."), true);
    }

    private function promptMapSwap(Interaction $i, string $key): PromiseInterface
    {
        if (! $this->isStaff($i->member)) {
            return $this->deny($i);
        }
        $maps = $this->readMaps();
        if ($maps === []) {
            return $i->respondWithMessage($this->plain('No map list is available on disk.'), true);
        }

        $select = StringSelect::new('sp_mappick_'.$key)
            ->setPlaceholder('Swap '.$key.' to…')
            ->setMaxValues(1)
            ->setListener(function (Interaction $si) use ($key): PromiseInterface {
                if (! $this->isStaff($si->member)) {
                    return $this->deny($si);
                }
                $server = $this->civ13->enabled_gameservers[$key] ?? null;
                $map = $si->data->values[0] ?? '';
                if ($server === null || $map === '') {
                    return $si->respondWithMessage($this->plain('Could not swap map.'), true);
                }
                $server->MapSwap($map, (string) ($si->user->username ?? $si->user->id));

                return $si->respondWithMessage($this->plain("Queued a map swap on **{$server->name}** to `{$map}`."), true);
            }, $this->discord);

        foreach (array_slice($maps, 0, 25) as $map) {
            $select->addOption(\Discord\Builders\Components\Option::new($map, $map));
        }

        return $i->respondWithMessage($this->container([
            TextDisplay::new("Pick a map for **{$key}**:"),
            ActionRow::new()->addComponents([$select]),
        ]), true);
    }

    // --- player panel ----------------------------------------------

    private function playerPanel(Member $member): MessageBuilder
    {
        $item = $this->civ13->verifier->get('discord', $member->id);
        $ckey = $item['ss13'] ?? null;
        $status = $ckey !== null
            ? "You are verified as **{$ckey}**."
            : 'You are **not verified**. Use **Verify** to link your BYOND account.';

        $row1 = ActionRow::new()->addComponents([
            Button::success('pp_verify')->setLabel('🔗 Verify')->setDisabled($ckey !== null)
                ->setListener(fn (Interaction $i) => $this->verifyModal($i), $this->discord),
            Button::secondary('pp_roles')->setLabel('♻️ Refresh Roles')
                ->setListener(fn (Interaction $i) => $this->refreshRoles($i), $this->discord),
            Button::secondary('pp_rank')->setLabel('🎖️ My Rank')
                ->setListener(fn (Interaction $i) => $this->myRank($i), $this->discord),
            Button::secondary('pp_bans')->setLabel('🚫 My Bans')
                ->setListener(fn (Interaction $i) => $this->myBans($i), $this->discord),
            Button::secondary('pp_status')->setLabel('📊 Server Status')
                ->setListener(fn (Interaction $i) => $i->respondWithMessage($this->civ13->createServerstatusEmbed(), true), $this->discord),
        ]);
        $row2 = ActionRow::new()->addComponents([
            Button::primary('pp_campaign')->setLabel('⚔️ Join Campaign')
                ->setListener(fn (Interaction $i) => $this->joinCampaign($i), $this->discord),
            Button::secondary('pp_help')->setLabel('❓ Help')
                ->setListener(fn (Interaction $i) => $i->respondWithMessage($this->plain($this->civ13->messageServiceManager->generateHelp($i->member->roles)), true), $this->discord),
        ]);

        return $this->container([
            TextDisplay::new("## 🎮 Player Panel\n{$status}"),
            Separator::new(),
            $row1,
            $row2,
        ]);
    }

    private function verifyModal(Interaction $i): PromiseInterface
    {
        if ($this->civ13->verifier->get('discord', $i->member->id)) {
            return $i->respondWithMessage($this->plain('You are already verified.'), true);
        }

        return $i->showModal('Verify your BYOND account', 'pp_verify_modal', [
            ActionRow::new()->addComponent(
                TextInput::new()
                    ->setCustomId('ckey')
                    ->setLabel('BYOND username (ckey)')
                    ->setStyle(TextInput::STYLE_SHORT)
                    ->setMinLength(2)
                    ->setMaxLength(32)
                    ->setRequired(true),
            ),
        ], function (Interaction $si): PromiseInterface {
            $ckey = Civ13::sanitizeInput((string) $this->modalValue($si, 'ckey'));
            if ($ckey === '') {
                return $si->respondWithMessage($this->plain('That does not look like a ckey.'), true);
            }
            if (isset($this->civ13->softbanned[$si->member->id]) || isset($this->civ13->softbanned[$ckey])) {
                return $si->respondWithMessage($this->plain('This account is under investigation.'), true);
            }

            return $si->respondWithMessage($this->plain($this->civ13->verifier->process($ckey, $si->member->id, $si->member)), true);
        });
    }

    private function refreshRoles(Interaction $i): PromiseInterface
    {
        $item = $this->civ13->verifier->get('discord', $i->member->id);
        if (! $item) {
            return $i->respondWithMessage($this->plain('You are not verified, so there are no roles to sync.'), true);
        }
        $i->member->roles->has($this->civ13->role_ids['Verified'])
            ? resolve(null)
            : $this->civ13->addRoles($i->member, $this->civ13->role_ids['Verified']);

        return $i->respondWithMessage($this->plain("Re-synced your roles from ckey **{$item['ss13']}**."), true);
    }

    private function myRank(Interaction $i): PromiseInterface
    {
        $ckey = $this->civ13->verifier->get('discord', $i->member->id)['ss13'] ?? null;
        if ($ckey === null) {
            return $i->respondWithMessage($this->plain('You are not verified.'), true);
        }
        $server = $this->firstServer();
        if ($server === null) {
            return $i->respondWithMessage($this->plain('No game server is enabled.'), true);
        }

        return $i->respondWithMessage($this->plain($server->getRank($ckey) ?: "No rank recorded for `{$ckey}`."), true);
    }

    private function myBans(Interaction $i): PromiseInterface
    {
        $ckey = $this->civ13->verifier->get('discord', $i->member->id)['ss13'] ?? null;
        if ($ckey === null) {
            return $i->respondWithMessage($this->plain('You are not verified.'), true);
        }

        $hits = [];
        foreach ($this->civ13->enabled_gameservers as $key => $server) {
            if ($server->bancheck($ckey, true)) {
                $hits[] = $key;
            }
        }

        return $i->respondWithMessage($this->plain(
            $hits === []
                ? "No active bans found for **{$ckey}**."
                : "**{$ckey}** is banned on: `".implode('`, `', $hits).'`. Appeal in the ban-appeals channel.',
        ), true);
    }

    private function joinCampaign(Interaction $i): PromiseInterface
    {
        if (! $this->civ13->verifier->getVerifiedItem($i->member->id)) {
            return $i->respondWithMessage($this->plain('You must verify first.'), true);
        }
        foreach ($i->member->roles as $role) {
            if (in_array($role->id, $this->civ13->faction_ids, true)) {
                return $i->respondWithMessage($this->plain('You are already in a faction.'), true);
            }
        }
        $counts = [];
        foreach ($this->civ13->faction_ids as $roleId) {
            $counts[$roleId] = $i->guild->members->filter(fn ($m) => $m->roles->has($roleId))->count();
        }
        if ($counts === []) {
            return $i->respondWithMessage($this->plain('No campaign factions are configured.'), true);
        }
        $lowest = array_keys($counts, min($counts), true);
        $chosen = $lowest[array_rand($lowest)];
        $i->member->addRole($chosen);

        return $i->respondWithMessage($this->plain("You have joined <@&{$chosen}>."), true);
    }

    // --- helpers -------------------------------------------------

    private function isStaff(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }
        foreach (['Admin', 'Chief Technical Officer', 'Ambassador'] as $roleName) {
            if (isset($this->civ13->role_ids[$roleName]) && $member->roles->has($this->civ13->role_ids[$roleName])) {
                return true;
            }
        }
        $perms = $member->getPermissions();

        return (bool) ($perms?->moderate_members || $perms?->administrator || $perms?->ban_members);
    }

    private function staffButton(Button $button, string $label, string $customId, callable $handler): Button
    {
        return $button->setCustomId($customId)->setLabel($label)->setListener(function (Interaction $i) use ($handler): PromiseInterface {
            return $this->isStaff($i->member) ? $handler($i) : $this->deny($i);
        }, $this->discord);
    }

    /** Fire a side-effect on the loop and answer immediately. */
    private function bg(Interaction $i, callable $work, string $message): PromiseInterface
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->warning('[panels] background action failed: '.$e->getMessage());

            return $i->respondWithMessage($this->plain("That didn't run: {$e->getMessage()}"), true);
        }

        return $i->respondWithMessage($this->plain($message), true);
    }

    private function deny(Interaction $i): PromiseInterface
    {
        return $i->respondWithMessage($this->plain('You no longer have permission for that.'), true);
    }

    private function firstServer(): ?GameServer
    {
        foreach ($this->civ13->enabled_gameservers as $server) {
            return $server;
        }

        return null;
    }

    /** @return list<string> */
    private function readMaps(): array
    {
        $path = (isset($this->civ13->gitdir) ? $this->civ13->gitdir : '').Civ13::maps;
        if (! is_file($path) || ! $raw = @file_get_contents($path)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn ($m) => $m !== ''));
    }

    private function modalValue(Interaction $i, string $customId): ?string
    {
        foreach ($i->data->components ?? [] as $row) {
            foreach ($row->components ?? [] as $component) {
                if (($component->custom_id ?? null) === $customId) {
                    return $component->value ?? null;
                }
            }
        }

        return null;
    }

    /** @param list<mixed> $components */
    private function container(array $components): MessageBuilder
    {
        return Civ13::createBuilder(true)
            ->setIsComponentsV2Flag(true)
            ->addComponent(Container::new()->addComponents($components));
    }

    private function plain(string $text): MessageBuilder
    {
        return Civ13::createBuilder(true)->setContent($text);
    }
}
