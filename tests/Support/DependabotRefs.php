<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Regras de "Arquivos do repositório e o Dependabot" (docs/desenvolvimento.md): nos valores que o Dependabot
 * atualiza, o teste confere o formato, nunca o valor.
 */
final class DependabotRefs
{
    /** Texto fixo que substitui cada `@<SHA> # <versão>` antes de comparar. */
    public const PLACEHOLDER = '@<SHA> # <versão>';

    /**
     * Troca, nas linhas `uses:`, cada `@<SHA de 40 hex minúsculos> # <v?N.N.N>` no fim da linha pelo texto fixo.
     * O nome da action ou do workflow (antes do `@`) fica como está.
     */
    public static function normalizeUses(string $text): string
    {
        return (string) preg_replace(
            '/^(\s*(?:-\s+)?uses:\s+[^\s@]+)@[0-9a-f]{40} # v?[0-9]+\.[0-9]+\.[0-9]+$/m',
            '$1' . self::PLACEHOLDER,
            $text,
        );
    }

    /**
     * Compara dois composer.json decodificados: o require-dev precisa ter as mesmas dependências, cada uma com
     * restrição de texto não vazio; o resto é comparado por valor.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    public static function composerMatches(array $expected, array $actual): bool
    {
        $expectedDev = $expected['require-dev'] ?? null;
        $actualDev = $actual['require-dev'] ?? null;
        if (!\is_array($expectedDev) || !\is_array($actualDev)) {
            return $expected == $actual;
        }

        $expectedNames = array_keys($expectedDev);
        $actualNames = array_keys($actualDev);
        sort($expectedNames);
        sort($actualNames);
        if ($expectedNames !== $actualNames) {
            return false;
        }
        foreach ($actualDev as $constraint) {
            if (!\is_string($constraint) || $constraint === '') {
                return false;
            }
        }

        unset($expected['require-dev'], $actual['require-dev']);

        return $expected == $actual;
    }
}
