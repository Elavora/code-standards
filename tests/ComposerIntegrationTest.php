<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\TestCase;

/**
 * Integração com o Composer de verdade (o da imagem), num projeto temporário que instala este repositório por um
 * repositório "path", sem acesso à rede.
 */
final class ComposerIntegrationTest extends TestCase
{
    use TempDirectories;

    /** @param array<string, string|list<string>> $scripts scripts acrescentados aos de sincronização */
    private function makeProject(array $scripts = []): string
    {
        $dir = $this->makeTempDir();
        $composerJson = [
            'name' => 'elavora/projeto-de-teste',
            'require-dev' => ['elavora/code-standards' => '*@dev'],
            'repositories' => [
                ['type' => 'path', 'url' => Expected::repoRoot()],
                ['packagist.org' => false],
            ],
            'scripts' => [
                'post-install-cmd' => ['Elavora\\CodeStandards\\Composer::sync'],
                'post-update-cmd' => ['Elavora\\CodeStandards\\Composer::sync'],
                ...$scripts,
            ],
        ];
        $this->writeFile(
            $dir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        return $dir;
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function composer(array $args, string $cwd): array
    {
        $home = $this->makeTempDir();

        return Process::run(
            // As opções globais vêm antes, para não virarem argumentos do script em "composer lint -- ...".
            ['composer', '--no-interaction', '--no-ansi', ...$args],
            $cwd,
            ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $home . '/cache', 'COMPOSER_DISABLE_NETWORK' => '1'],
        );
    }

    /** Copia composer.json e composer.lock de $from para uma pasta nova, sem vendor/ e sem os dois arquivos. */
    private function copyWithLock(string $from): string
    {
        $to = $this->makeTempDir();
        copy($from . '/composer.json', $to . '/composer.json');
        copy($from . '/composer.lock', $to . '/composer.lock');

        return $to;
    }

    public function testUpdateCreatesBothFiles(): void
    {
        $dir = $this->makeProject();

        $result = $this->composer(['update'], $dir);
        $output = $result['stdout'] . $result['stderr'];

        self::assertSame(0, $result['code'], $output);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
        self::assertStringContainsString(Expected::MSG_EDITORCONFIG_CREATED, $output);
        self::assertStringContainsString(Expected::MSG_STUB_CREATED, $output);
    }

    public function testBinaryWorksThroughTheVendorBinProxy(): void
    {
        $dir = $this->makeProject();
        self::assertSame(0, $this->composer(['update'], $dir)['code']);
        unlink($dir . '/.editorconfig');

        $result = Process::run([PHP_BINARY, $dir . '/vendor/bin/elavora-standards', 'sync'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::MSG_EDITORCONFIG_CREATED . "\n" . Expected::MSG_STUB_KEPT . "\n", $result['stdout']);
    }

    public function testInstallNoDevDoesNothingAndExitsZero(): void
    {
        $dir = $this->makeProject();
        self::assertSame(0, $this->composer(['update'], $dir)['code']);
        $copy = $this->copyWithLock($dir);

        $result = $this->composer(['install', '--no-dev'], $copy);
        $output = $result['stdout'] . $result['stderr'];

        self::assertSame(0, $result['code'], $output);
        self::assertFileDoesNotExist($copy . '/.editorconfig');
        self::assertFileDoesNotExist($copy . '/.php-cs-fixer.dist.php');
        self::assertStringContainsString('is not autoloadable', $output);
    }

    public function testInstallWithWorkingDirOptionSynchronizesTheProject(): void
    {
        $dir = $this->makeProject();
        self::assertSame(0, $this->composer(['update'], $dir)['code']);
        $project = $this->copyWithLock($dir);
        $elsewhere = $this->makeTempDir();

        $result = $this->composer(['-d', $project, 'install'], $elsewhere);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame(Expected::block(), file_get_contents($project . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($project . '/.php-cs-fixer.dist.php'));
        self::assertSame([], $this->snapshot($elsewhere));
    }

    /** O projeto temporário com "lint": "elavora-standards lint" em scripts, já instalado. */
    private function makeLintProject(): string
    {
        $dir = $this->makeProject(['lint' => 'elavora-standards lint']);
        $update = $this->composer(['update'], $dir);
        self::assertSame(0, $update['code'], $update['stdout'] . $update['stderr']);
        $this->writeFile($dir . '/src/A.php', Expected::PHP_VALID);
        $this->writeFile($dir . '/tests/ATest.php', Expected::PHP_VALID);

        return $dir;
    }

    public function testComposerLintExitsZeroWhenAllFilesAreValid(): void
    {
        $dir = $this->makeLintProject();

        $result = $this->composer(['lint'], $dir);
        $output = $result['stdout'] . $result['stderr'];

        self::assertSame(0, $result['code'], $output);
        // vendor/ (com o link para este repositório) e .php-cs-fixer.dist.php ficam de fora.
        self::assertStringContainsString(Expected::lintOk(2), $output);
    }

    public function testComposerLintFailsWithASyntaxError(): void
    {
        $dir = $this->makeLintProject();
        $this->writeFile($dir . '/src/Quebrado.php', Expected::PHP_BROKEN);

        $result = $this->composer(['lint'], $dir);
        $output = $result['stdout'] . $result['stderr'];

        self::assertNotSame(0, $result['code'], $output);
        self::assertStringContainsString(Expected::lintErrorTitle('src/Quebrado.php'), $output);
        self::assertStringContainsString(Expected::lintErrorSummary(1, 3), $output);
    }

    public function testComposerLintAcceptsExtraArguments(): void
    {
        $dir = $this->makeLintProject();
        $this->writeFile($dir . '/tests/legado/Quebrado.php', Expected::PHP_BROKEN);

        $result = $this->composer(['lint', '--', '--exclude=tests/legado'], $dir);
        $output = $result['stdout'] . $result['stderr'];

        self::assertSame(0, $result['code'], $output);
        self::assertStringContainsString(Expected::lintOk(2), $output);
    }

    public function testBrokenMarkersDoNotMakeComposerFail(): void
    {
        $dir = $this->makeProject();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());

        $result = $this->composer(['update'], $dir);
        $output = $result['stdout'] . $result['stderr'];

        self::assertSame(0, $result['code'], $output);
        self::assertStringContainsString(Expected::MSG_ERROR_PREFIX, $output);
        self::assertSame(Expected::malformed(), file_get_contents($dir . '/.editorconfig'));
    }
}
