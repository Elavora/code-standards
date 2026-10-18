<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

use Elavora\CodeStandards\Exception\MalformedMarkersException;
use Elavora\CodeStandards\Exception\UnmanagedFileException;

/**
 * O `.editorconfig` base e o bloco gerado que a sincronização mantém na raiz do projeto.
 */
final class EditorConfig
{
    public const BEGIN_MARKER = '# >>> elavora/code-standards >>>';
    public const END_MARKER = '# <<< elavora/code-standards <<<';

    private const HEADER = [
        '# Bloco gerado pelo elavora/code-standards. Não edite: ele é reescrito a cada composer install e composer update.',
        '# Seções próprias do projeto vão depois da linha "# <<< elavora/code-standards <<<".',
    ];

    private function __construct() {}

    /** O conteúdo de `resources/editorconfig`. */
    public static function base(): string
    {
        $path = \dirname(__DIR__) . '/resources/editorconfig';
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException("Não foi possível ler o .editorconfig base em {$path}");
        }

        return $content;
    }

    /** O bloco gerado, entre as duas linhas marcadoras, com `\n` no final. */
    public static function managedBlock(): string
    {
        $base = self::base();
        if (str_ends_with($base, "\n")) {
            $base = substr($base, 0, -1);
        }

        return implode("\n", [self::BEGIN_MARKER, ...self::HEADER, $base, self::END_MARKER]) . "\n";
    }

    /**
     * Devolve o conteúdo novo do `.editorconfig` a partir do atual (`null` quando o arquivo não existe).
     *
     * @throws UnmanagedFileException    quando o texto tem conteúdo e nenhuma linha marcadora
     * @throws MalformedMarkersException quando as linhas marcadoras estão quebradas
     */
    public static function merge(?string $current, bool $force = false): string
    {
        $block = self::managedBlock();
        if ($force || $current === null || trim($current, " \t\r\n") === '') {
            return $block;
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $current));
        $begins = [];
        $ends = [];
        foreach ($lines as $index => $line) {
            $trimmed = rtrim($line, " \t");
            if ($trimmed === self::BEGIN_MARKER) {
                $begins[] = $index;
            } elseif ($trimmed === self::END_MARKER) {
                $ends[] = $index;
            }
        }

        if ($begins === [] && $ends === []) {
            throw new UnmanagedFileException('O .editorconfig não tem o bloco do elavora/code-standards.');
        }
        if (\count($begins) > 1 || \count($ends) > 1) {
            throw new MalformedMarkersException(
                'O .editorconfig tem linhas marcadoras do elavora/code-standards repetidas.',
            );
        }
        if ($ends === []) {
            throw new MalformedMarkersException(
                'O .editorconfig tem a linha "' . self::BEGIN_MARKER . '", mas não a linha "' . self::END_MARKER . '".',
            );
        }
        if ($begins === []) {
            throw new MalformedMarkersException(
                'O .editorconfig tem a linha "' . self::END_MARKER . '", mas não a linha "' . self::BEGIN_MARKER . '".',
            );
        }

        $begin = $begins[0];
        $end = $ends[0];
        if ($end < $begin) {
            throw new MalformedMarkersException(
                'No .editorconfig, a linha "' . self::END_MARKER . '" vem antes da linha "' . self::BEGIN_MARKER . '".',
            );
        }

        $before = '';
        foreach (\array_slice($lines, 0, $begin) as $line) {
            $before .= $line . "\n";
        }
        $after = implode("\n", \array_slice($lines, $end + 1));
        if ($after !== '' && !str_ends_with($after, "\n")) {
            $after .= "\n";
        }

        return $before . $block . $after;
    }
}
