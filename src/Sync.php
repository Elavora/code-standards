<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

use Elavora\CodeStandards\Exception\MalformedMarkersException;
use Elavora\CodeStandards\Exception\UnmanagedFileException;

/**
 * Sincroniza o `.editorconfig` e o stub `.php-cs-fixer.dist.php` na raiz de um projeto.
 */
final class Sync
{
    public const STUB = "<?php\n\nrequire_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';\n\nreturn Elavora\\CodeStandards\\PhpCsFixer::config(__DIR__);\n";

    private function __construct() {}

    /**
     * @return array{editorconfig: Status, stub: Status}
     *
     * @throws \InvalidArgumentException quando $dir não é um diretório existente
     * @throws MalformedMarkersException quando as linhas marcadoras do `.editorconfig` estão quebradas
     * @throws \RuntimeException         quando um arquivo não pode ser lido ou escrito
     */
    public static function run(string $dir, bool $force = false): array
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("O diretório \"{$dir}\" não existe ou não é um diretório.");
        }
        $root = rtrim($dir, '/\\');

        $editorconfig = self::syncEditorConfig($root . '/.editorconfig', $force);
        $stub = self::syncStub($root . '/.php-cs-fixer.dist.php');

        return ['editorconfig' => $editorconfig, 'stub' => $stub];
    }

    private static function syncEditorConfig(string $path, bool $force): Status
    {
        $current = self::read($path);
        try {
            $new = EditorConfig::merge($current, $force);
        } catch (UnmanagedFileException) {
            return Status::Skipped;
        }

        if ($current === $new) {
            return Status::Unchanged;
        }
        self::write($path, $new);

        return $current === null ? Status::Created : Status::Updated;
    }

    private static function syncStub(string $path): Status
    {
        if (file_exists($path)) {
            return Status::Unchanged;
        }
        self::write($path, self::STUB);

        return Status::Created;
    }

    private static function read(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Não foi possível ler {$path}");
        }

        return $content;
    }

    private static function write(string $path, string $content): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Não foi possível escrever {$path}");
        }
        $written = @fwrite($handle, $content);
        $closed = @fclose($handle);
        if ($written !== \strlen($content) || !$closed) {
            throw new \RuntimeException("Não foi possível escrever {$path}");
        }
    }
}
