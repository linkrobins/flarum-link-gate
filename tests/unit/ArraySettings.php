<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * A settings repository held in memory, so the unit tests need no database.
 */
class ArraySettings implements SettingsRepositoryInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        unset($this->data[$keyLike]);
    }
}
