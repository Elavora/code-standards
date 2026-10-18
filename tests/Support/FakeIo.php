<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Substitui o Composer\IO\IOInterface: guarda as mensagens de write() e writeError().
 */
final class FakeIo
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $errors = [];

    /** @param string|list<string> $messages */
    public function write(string|array $messages, bool $newline = true, int $verbosity = 2): void
    {
        foreach ((array) $messages as $message) {
            $this->written[] = $message;
        }
    }

    /** @param string|list<string> $messages */
    public function writeError(string|array $messages, bool $newline = true, int $verbosity = 2): void
    {
        foreach ((array) $messages as $message) {
            $this->errors[] = $message;
        }
    }
}
