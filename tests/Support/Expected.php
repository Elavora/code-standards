<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

/**
 * Valores exatos tirados da documentação (docs/editorconfig.md e docs/php-cs-fixer.md).
 */
final class Expected
{
    public const BEGIN_MARKER = '# >>> elavora/code-standards >>>';
    public const END_MARKER = '# <<< elavora/code-standards <<<';

    public const BASE = <<<'INI'
        root = true

        [*]
        charset = utf-8
        end_of_line = lf
        indent_style = space
        indent_size = 4
        insert_final_newline = true
        trim_trailing_whitespace = true

        [*.{yml,yaml,json,neon}]
        indent_size = 2

        [*.md]
        indent_size = unset
        trim_trailing_whitespace = false

        [Makefile]
        indent_style = tab

        INI;

    public const STUB = "<?php\n\nrequire_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';\n\n"
        . "return Elavora\\CodeStandards\\PhpCsFixer::config(__DIR__);\n";

    public const PREFIX = 'elavora/code-standards: ';

    public const MSG_EDITORCONFIG_CREATED = 'elavora/code-standards: .editorconfig criado';
    public const MSG_EDITORCONFIG_UPDATED = 'elavora/code-standards: .editorconfig atualizado';
    public const MSG_EDITORCONFIG_UNCHANGED = 'elavora/code-standards: .editorconfig sem mudanças';
    public const MSG_EDITORCONFIG_KEPT = 'elavora/code-standards: .editorconfig mantido: o arquivo não tem o bloco do '
        . 'elavora/code-standards. Rode "vendor/bin/elavora-standards sync --force" para substituí-lo pela base.';
    public const MSG_STUB_CREATED = 'elavora/code-standards: .php-cs-fixer.dist.php criado';
    public const MSG_STUB_KEPT = 'elavora/code-standards: .php-cs-fixer.dist.php já existe, mantido';
    public const MSG_ERROR_PREFIX = 'elavora/code-standards: erro: ';

    public const USAGE_LINE = 'elavora-standards sync [--force] [--dir=<pasta>]';

    /** O bloco gerado (`B` nas tabelas da documentação). */
    public static function block(): string
    {
        return self::BEGIN_MARKER . "\n"
            . "# Bloco gerado pelo elavora/code-standards. Não edite: ele é reescrito a cada composer install e composer update.\n"
            . "# Seções próprias do projeto vão depois da linha \"# <<< elavora/code-standards <<<\".\n"
            . self::BASE
            . self::END_MARKER . "\n";
    }

    /** Um `.editorconfig` sem nenhuma linha marcadora. */
    public const UNMANAGED = "root = true\n[*]\nindent_style = tab\n";

    /** Marcadores quebrados: falta a linha de fim. */
    public static function malformed(): string
    {
        return self::BEGIN_MARKER . "\nroot = true\n";
    }

    public static function repoRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
