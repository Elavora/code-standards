<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

/**
 * Lint de sintaxe PHP: escolhe os arquivos do projeto e roda `php -l` em cada um.
 */
final class Lint
{
    private function __construct() {}

    /**
     * Os arquivos a conferir, com os caminhos mostrados, em ordem de bytes e sem repetição.
     *
     * @param list<string> $paths   pastas e arquivos pedidos; vazio é a pasta inteira
     * @param list<string> $exclude arquivos e pastas tirados do resultado
     *
     * @return list<string>
     */
    public static function files(string $dir, array $paths = [], array $exclude = []): array
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("a pasta do projeto não existe ou não é uma pasta: {$dir}");
        }

        $root = self::normalize(self::absolute($dir, (string) getcwd()));
        if ($paths === []) {
            $paths = ['.'];
        }

        $found = [];
        foreach ($paths as $path) {
            $absolute = self::normalize(self::absolute($path, $root));
            if (is_dir($absolute)) {
                self::walk($absolute, $found);
            } elseif (file_exists($absolute)) {
                $found[] = $absolute;
            } else {
                throw new \InvalidArgumentException("o caminho não existe: {$path}");
            }
        }

        $excluded = array_map(static fn(string $e): string => self::normalize(self::absolute($e, $root)), $exclude);

        $files = [];
        foreach ($found as $absolute) {
            foreach ($excluded as $e) {
                if ($absolute === $e || str_starts_with($absolute, rtrim($e, '/') . '/')) {
                    continue 2;
                }
            }
            $files[] = self::shown($absolute, $root);
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Confere um arquivo com `php -l`, rodando com `$dir` como pasta atual.
     *
     * @return string|null null sem erro; senão, a mensagem do PHP
     */
    public static function check(string $dir, string $file): ?string
    {
        $command = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $file];
        // @phpstan-ignore argument.type (o PHPStan não conhece o descritor ['redirect', 1] do proc_open)
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $dir);
        if (!\is_resource($process)) {
            return 'não foi possível iniciar o php -l';
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        if ($code === 0) {
            return null;
        }

        $lines = [];
        $split = preg_split('/\R/', $output);
        foreach ($split === false ? [] : $split as $line) {
            if (trim($line) === '' || str_starts_with($line, 'Errors parsing ')) {
                continue;
            }
            $lines[] = rtrim($line);
        }

        return $lines === [] ? "o php -l saiu com o código {$code}" : implode("\n", $lines);
    }

    /**
     * Acrescenta a $found os arquivos `.php` de $folder, sem `vendor`, sem nomes com ponto e sem links para pastas.
     *
     * @param list<string> $found
     */
    private static function walk(string $folder, array &$found): void
    {
        $entries = scandir($folder);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = rtrim($folder, '/') . '/' . $name;
            if (is_dir($path)) {
                if (!is_link($path) && $name !== 'vendor') {
                    self::walk($path, $found);
                }
            } elseif (is_file($path) && str_ends_with(strtolower($name), '.php')) {
                $found[] = $path;
            }
        }
    }

    /** O caminho mostrado: relativo à pasta do projeto ou, fora dela, absoluto. */
    private static function shown(string $absolute, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($absolute, $prefix) ? substr($absolute, \strlen($prefix)) : $absolute;
    }

    private static function absolute(string $path, string $base): string
    {
        $path = str_replace(\DIRECTORY_SEPARATOR, '/', $path);
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1) {
            return $path;
        }

        return rtrim(str_replace(\DIRECTORY_SEPARATOR, '/', $base), '/') . '/' . $path;
    }

    /** Resolve `.` e `..` e tira as barras repetidas e a do fim, sem consultar o disco. */
    private static function normalize(string $path): string
    {
        $prefix = '';
        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            $prefix = substr($path, 0, 3);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
        }

        $parts = [];
        foreach (explode('/', substr($path, \strlen($prefix))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif ($prefix === '') {
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $part;
        }

        return $prefix . implode('/', $parts);
    }
}
