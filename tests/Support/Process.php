<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Roda um processo e devolve o código de saída, a saída padrão e a saída de erro.
 */
final class Process
{
    /**
     * @param list<string>               $command
     * @param array<string, string>|null $env     variáveis acrescentadas ao ambiente atual
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $command, ?string $cwd = null, ?array $env = null, int $timeout = 300): array
    {
        $fullEnv = null;
        if ($env !== null) {
            $fullEnv = array_merge(getenv(), $env);
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $fullEnv,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Não foi possível iniciar ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $code = $status['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($process);
                throw new \RuntimeException('Tempo esgotado: ' . implode(' ', $command));
            }
            usleep(10_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Roda um script PHP em um processo separado. */
    public static function php(string $code, ?string $cwd = null): array
    {
        $file = tempnam(sys_get_temp_dir(), 'elavora-cs-script-');
        file_put_contents($file, $code);
        try {
            return self::run([PHP_BINARY, $file], $cwd);
        } finally {
            @unlink($file);
        }
    }
}
