<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

class User
{
    use DynamicPropertyAccessorTrait;

    public function __construct(
        protected string $discord,
        protected string $ss14,
    ){}

    public function getDiscord(): string
    {
        return $this->discord;
    }

    public function getSS14(): string
    {
        return $this->ss14;
    }

    public function setDiscord(string $discord): void
    {
        $this->discord = $discord;
    }
    
    public function setSS14(string $ss14): void
    {
        $this->ss14 = $ss14;
    }

    public function toArray(): array
    {
        return [
            'discord' => $this->discord,
            'ss14' => $this->ss14,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}