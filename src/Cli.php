<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

/**
 * Lógica do comando `bin/elavora-standards` e das mensagens da sincronização.
 *
 * @internal
 */
final class Cli
{
    public const PREFIX = 'elavora/code-standards: ';

    private const USAGE = <<<'TXT'
        Uso:
            elavora-standards sync [--force] [--dir=<pasta>]
            elavora-standards help | --help | -h

        Comandos:
            sync    sincroniza o .editorconfig e o .php-cs-fixer.dist.php na raiz do projeto
            help    mostra esta ajuda

        Opções do sync:
            --force          substitui o .editorconfig inteiro pelo bloco gerado
            --dir=<pasta>    pasta do projeto (padrão: a pasta atual)

        TXT;

    private function __construct() {}

    /**
     * Roda o comando e devolve o código de saída.
     *
     * @param list<string> $args     os argumentos, sem o nome do script
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public static function main(array $args, $stdout, $stderr): int
    {
        if ($args === []) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        if (\count($args) === 1 && \in_array($args[0], ['help', '--help', '-h'], true)) {
            fwrite($stdout, self::USAGE);

            return 0;
        }

        $options = self::parseSync($args);
        if ($options === null) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        try {
            $result = Sync::run($options['dir'], $options['force']);
        } catch (\Throwable $e) {
            fwrite($stderr, self::error($e) . "\n");

            return 1;
        }

        foreach (self::report($result) as [$isError, $line]) {
            fwrite($isError ? $stderr : $stdout, $line . "\n");
        }

        return 0;
    }

    /**
     * As linhas de mensagem de um resultado de `Sync::run`, na ordem, sem quebra de linha final.
     *
     * @param array{editorconfig: Status, stub: Status} $result
     *
     * @return list<array{0: bool, 1: string}> pares [vai para a saída de erro, linha]
     */
    public static function report(array $result): array
    {
        $lines = [];
        $lines[] = match ($result['editorconfig']) {
            Status::Created => [false, self::PREFIX . '.editorconfig criado'],
            Status::Updated => [false, self::PREFIX . '.editorconfig atualizado'],
            Status::Unchanged => [false, self::PREFIX . '.editorconfig sem mudanças'],
            Status::Skipped => [true, self::PREFIX . '.editorconfig mantido: o arquivo não tem o bloco do '
                . 'elavora/code-standards. Rode "vendor/bin/elavora-standards sync --force" para substituí-lo pela base.'],
        };
        $lines[] = $result['stub'] === Status::Created
            ? [false, self::PREFIX . '.php-cs-fixer.dist.php criado']
            : [false, self::PREFIX . '.php-cs-fixer.dist.php já existe, mantido'];

        return $lines;
    }

    /** A linha de erro de uma exceção, sem quebra de linha final. */
    public static function error(\Throwable $e): string
    {
        return self::PREFIX . 'erro: ' . $e->getMessage();
    }

    /**
     * @param list<string> $args
     *
     * @return array{dir: string, force: bool}|null null quando os argumentos são inválidos
     */
    private static function parseSync(array $args): ?array
    {
        if ($args[0] !== 'sync') {
            return null;
        }

        $dir = null;
        $force = false;
        foreach (\array_slice($args, 1) as $arg) {
            if ($arg === '--force') {
                $force = true;
            } elseif (str_starts_with($arg, '--dir=') && \strlen($arg) > \strlen('--dir=')) {
                $dir = substr($arg, \strlen('--dir='));
            } else {
                return null;
            }
        }

        return ['dir' => $dir ?? (string) getcwd(), 'force' => $force];
    }
}
