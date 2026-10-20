<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

/**
 * Callback dos scripts `post-install-cmd` e `post-update-cmd` do Composer.
 */
final class Composer
{
    private function __construct() {}

    /**
     * Sincroniza a raiz do projeto. Nunca lança exceção: um erro vira uma linha na saída de erro.
     *
     * @param object $event o `Composer\Script\Event` (usa só `isDevMode()` e `getIO()`)
     */
    public static function sync(object $event): void
    {
        if (!method_exists($event, 'isDevMode') || $event->isDevMode() !== true || !method_exists($event, 'getIO')) {
            return;
        }
        $io = $event->getIO();
        if (!\is_object($io) || !method_exists($io, 'write') || !method_exists($io, 'writeError')) {
            return;
        }

        try {
            $result = Sync::run((string) getcwd());
        } catch (\Throwable $e) {
            $io->writeError(Cli::error($e));

            return;
        }

        foreach (Cli::report($result) as [$isError, $line]) {
            if ($isError) {
                $io->writeError($line);
            } else {
                $io->write($line);
            }
        }
    }
}
