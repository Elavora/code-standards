<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Substitui o Composer\Script\Event: só isDevMode() e getIO().
 */
final class FakeEvent
{
    public readonly FakeIo $io;

    public function __construct(private readonly bool $devMode)
    {
        $this->io = new FakeIo();
    }

    public function isDevMode(): bool
    {
        return $this->devMode;
    }

    public function getIO(): FakeIo
    {
        return $this->io;
    }
}
