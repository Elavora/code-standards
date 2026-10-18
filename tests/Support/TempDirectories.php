<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Pastas temporárias em sys_get_temp_dir(), apagadas no fim de cada teste.
 */
trait TempDirectories
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elavora-cs-test-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            self::fail("Não foi possível criar a pasta temporária {$dir}");
        }
        $dir = (string) realpath($dir);
        $this->tempDirectories[] = $dir;

        return $dir;
    }

    protected function writeFile(string $path, string $content): void
    {
        $parent = \dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0o777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * Lista os caminhos relativos de todos os arquivos e pastas de $dir, com conteúdo e data de modificação.
     *
     * @return array<string, array{0: string|null, 1: int|false}>
     */
    protected function snapshot(string $dir): array
    {
        clearstatcache();
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), \strlen($dir) + 1);
            $content = $item->isFile() && $item->isReadable() ? (string) file_get_contents($item->getPathname()) : null;
            $result[$relative] = [$content, @filemtime($item->getPathname())];
        }
        ksort($result);

        return $result;
    }

    /** Coloca a data de modificação de $path no passado, para que uma escrita seja detectável. */
    protected function ageFile(string $path): void
    {
        touch($path, time() - 3600);
        clearstatcache();
    }

    protected static function isRoot(): bool
    {
        return \function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    #[After]
    protected function removeTempDirectories(): void
    {
        foreach ($this->tempDirectories as $dir) {
            self::removeRecursive($dir);
        }
        $this->tempDirectories = [];
    }

    private static function removeRecursive(string $path): void
    {
        // Symlink (o repositório "path" do Composer liga vendor/elavora/code-standards à raiz do repo):
        // só remove o link, sem chmod nem recursão, que seguiriam o link e alterariam o alvo.
        if (is_link($path)) {
            @unlink($path);

            return;
        }
        if (is_file($path)) {
            @chmod($path, 0o666);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o777);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeRecursive($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
