<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Lê blocos de código da documentação, para comparar os arquivos do repositório com o que a doc especifica.
 */
final class Doc
{
    /**
     * Devolve o primeiro bloco cercado por ``` depois da primeira ocorrência de $marker em docs/$file,
     * com uma quebra de linha no fim de cada linha.
     */
    public static function codeBlockAfter(string $file, string $marker): string
    {
        $path = Expected::repoRoot() . '/docs/' . $file;
        $text = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
        $position = strpos($text, $marker);
        if ($position === false) {
            throw new \LogicException("Marcador \"{$marker}\" não encontrado em docs/{$file}");
        }

        $lines = explode("\n", substr($text, $position));
        $inside = false;
        $block = '';
        foreach ($lines as $line) {
            if (!$inside) {
                if (str_starts_with($line, '```')) {
                    $inside = true;
                }
                continue;
            }
            if (str_starts_with($line, '```')) {
                return $block;
            }
            $block .= $line . "\n";
        }

        throw new \LogicException("Bloco de código depois de \"{$marker}\" não fechado em docs/{$file}");
    }
}
