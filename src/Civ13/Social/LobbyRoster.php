<?php

declare(strict_types=1);

namespace Civ13\Social;

use Discord\Repository\LobbyRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Inert backend primitive, not an authorization service or proximity implementation.
 * The caller must supply a freshly observed roster and a server-authorized target.
 */
final class LobbyRoster
{
    private bool $busy = false;

    public function __construct(private readonly LobbyRepository $repository)
    {
    }

    /**
     * Plan all removals before additions. IDs remain strings; no float coercion.
     * This conservatively limits a prototype room to 25 participants.
     *
     * @return array{remove: list<string>, add: list<string>}
     */
    public static function plan(array $observed, array $authorized): array
    {
        $observed = self::ids($observed);
        $authorized = self::ids($authorized);
        if (count($authorized) > 25) {
            throw new \InvalidArgumentException('Prototype rooms support at most 25 participants.');
        }

        return [
            'remove' => array_values(array_diff($observed, $authorized)),
            'add' => array_values(array_diff($authorized, $observed)),
        ];
    }

    /**
     * Apply one plan using DiscordPHP, stopping on the first failed request.
     * Partial success is NOT rolled back: never re-admit a revoked participant.
     * Refresh the remote roster before any retry. No cross-process locking is provided.
     * The returned promise reports operations accepted, not native SDK connectivity.
     */
    public function apply(string $lobbyId, array $observed, array $authorized): PromiseInterface
    {
        self::ids([$lobbyId]);
        $plan = self::plan($observed, $authorized);
        if ($this->busy) {
            throw new \LogicException('A roster update is already in flight.');
        }
        $this->busy = true;
        $accepted = ['remove' => [], 'add' => []];
        $chain = resolve(null);
        foreach (['remove', 'add'] as $operation) {
            foreach ($plan[$operation] as $userId) {
                $chain = $chain->then(function () use ($operation, $lobbyId, $userId) {
                    return $operation === 'remove'
                        ? $this->repository->removeMember($lobbyId, $userId)
                        : $this->repository->addMember($lobbyId, $userId);
                })->then(static function () use (&$accepted, $operation, $userId): void {
                    $accepted[$operation][] = $userId;
                });
            }
        }

        return $chain->then(static function () use (&$accepted): array {
            return $accepted;
        })->finally(function (): void {
            $this->busy = false;
        });
    }

    /** @return list<string> */
    private static function ids(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new \InvalidArgumentException('Expected a list of Discord ID strings.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)
                || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
                throw new \InvalidArgumentException('Invalid Discord ID string.');
            }
        }

        return array_values(array_unique($ids));
    }
}
