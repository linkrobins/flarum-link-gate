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
 *
 * The parameters are deliberately untyped. Flarum 1.8 declares this interface
 * without types and 2.x declares it with them, and a child may widen a
 * parameter type but not narrow one, so dropping them is the only shape that
 * satisfies both release lines from one file. The return types are safe to keep
 * because a child may add one where the parent has none.
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

    public function get($key, $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function delete($keyLike): void
    {
        unset($this->data[$keyLike]);
    }
}
