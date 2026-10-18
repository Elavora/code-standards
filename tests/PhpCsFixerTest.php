<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\PhpCsFixer;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\TestCase;

/**
 * PhpCsFixer::config().
 *
 * No processo do PHPUnit, sem o PHAR, só as exceções. A inspeção do objeto devolvido roda em um processo PHP
 * separado que carrega o autoloader de dentro do PHAR da imagem, porque o PHAR traz pacotes que o PHPUnit também
 * usa (como sebastian/diff) sem trocar o namespace (veja docs/desenvolvimento.md).
 */
final class PhpCsFixerTest extends TestCase
{
    use TempDirectories;

    private const PHAR = '/usr/local/bin/php-cs-fixer';

    private static function requirePhar(): void
    {
        if (!is_file(self::PHAR)) {
            self::markTestSkipped('O php-cs-fixer da imagem ghcr.io/elavora/php-dev não está em ' . self::PHAR);
        }
    }

    public function testFileIsAtTheDocumentedPath(): void
    {
        self::assertFileExists(Expected::repoRoot() . '/src/PhpCsFixer.php');
    }

    public function testWithoutPhpCsFixerThrowsRuntimeException(): void
    {
        self::assertFalse(class_exists('PhpCsFixer\\Config'), 'O processo do PHPUnit não deveria ter o PHP-CS-Fixer');
        $dir = $this->makeTempDir();
        $cwd = (string) getcwd();
        $cwdBefore = scandir($cwd);

        try {
            PhpCsFixer::config($dir);
            self::fail('RuntimeException esperada');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('php-cs-fixer', $e->getMessage());
            self::assertStringContainsString('ghcr.io/elavora/php-dev', $e->getMessage());
        }

        self::assertSame([], $this->snapshot($dir));
        self::assertSame($cwdBefore, scandir($cwd));
    }

    public function testEmptyPathThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhpCsFixer::config('');
    }

    public function testNonexistentPathThrowsInvalidArgumentWithTheValue(): void
    {
        $dir = $this->makeTempDir() . '/nao-existe';

        try {
            PhpCsFixer::config($dir);
            self::fail('InvalidArgumentException esperada');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($dir, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testFilePathThrowsInvalidArgumentWithTheValue(): void
    {
        $parent = $this->makeTempDir();
        $file = $parent . '/arquivo.php';
        $this->writeFile($file, "<?php\n");
        $before = $this->snapshot($parent);

        try {
            PhpCsFixer::config($file);
            self::fail('InvalidArgumentException esperada');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($file, $e->getMessage());
        }

        self::assertSame($before, $this->snapshot($parent));
    }

    /**
     * Roda config() em um processo separado com o autoloader do PHAR e devolve o resultado em JSON.
     *
     * @return array<string, mixed>
     */
    private function inspect(string $dir, string $cwd): array
    {
        $script = ltrim(sprintf(
            <<<'PHP'
                <?php
                Phar::loadPhar(%s, 'php-cs-fixer.phar');
                require 'phar://php-cs-fixer.phar/vendor/autoload.php';
                require_once %s;
                chdir(%s);
                $dir = %s;
                $config = Elavora\CodeStandards\PhpCsFixer::config($dir);
                $other = Elavora\CodeStandards\PhpCsFixer::config($dir);
                $files = [];
                $root = realpath($dir);
                foreach ($config->getFinder() as $file) {
                    $files[] = substr((string) $file->getRealPath(), strlen($root) + 1);
                }
                sort($files);
                $loaded = array_values(array_filter(
                    get_included_files(),
                    static fn (string $f): bool => !str_starts_with($f, 'phar://') && $f !== __FILE__,
                ));
                $other->setRules(['@PSR12' => true]);
                $other->setRiskyAllowed(true);
                echo json_encode([
                    'class' => get_class($config),
                    'interface' => $config instanceof PhpCsFixer\ConfigInterface,
                    'name' => $config->getName(),
                    'rules' => $config->getRules(),
                    'risky' => $config->getRiskyAllowed(),
                    'cache' => $config->getUsingCache(),
                    'finder' => get_class($config->getFinder()),
                    'files' => $files,
                    'same' => $config === $other,
                    'sameFinder' => $config->getFinder() === $other->getFinder(),
                    'rulesAfterChange' => $config->getRules(),
                    'riskyAfterChange' => $config->getRiskyAllowed(),
                    'loaded' => $loaded,
                ], JSON_THROW_ON_ERROR);
                PHP,
            var_export(self::PHAR, true),
            var_export(Expected::repoRoot() . '/src/PhpCsFixer.php', true),
            var_export($cwd, true),
            var_export($dir, true),
        ));

        $result = Process::php($script);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Cria o projeto de exemplo de "Quais arquivos são formatados", com casos a mais. */
    private function makeSampleProject(): string
    {
        $dir = $this->makeTempDir();
        $php = "<?php\n";
        $this->writeFile($dir . '/src/A.php', $php);
        $this->writeFile($dir . '/tests/ATest.php', $php);
        $this->writeFile($dir . '/bin/console', $php);
        $this->writeFile($dir . '/.gitignore', "build/\ncoverage/\n");
        $this->writeFile($dir . '/build/B.php', $php);
        $this->writeFile($dir . '/coverage/X.php', $php);
        $this->writeFile($dir . '/.hidden/C.php', $php);
        $this->writeFile($dir . '/vendor/x/D.php', $php);
        $this->writeFile($dir . '/tests/fixtures/vendor/E.php', $php);
        $this->writeFile($dir . '/.github/F.php', $php);
        $this->writeFile($dir . '/.elavora/G.php', $php);
        $this->writeFile($dir . '/.devcontainer/H.php', $php);
        $this->writeFile($dir . '/.git/hooks/I.php', $php);
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', Expected::STUB);
        $this->writeFile($dir . '/src/.oculto.php', $php);
        $this->writeFile($dir . '/src/.gitignore', "gerado.php\n");
        $this->writeFile($dir . '/src/gerado.php', $php);
        $this->writeFile($dir . '/src/texto.txt', 'x');

        return $dir;
    }

    public function testConfigObject(): void
    {
        self::requirePhar();
        $dir = $this->makeSampleProject();

        $result = $this->inspect($dir, $dir);

        self::assertSame('PhpCsFixer\\Config', $result['class']);
        self::assertTrue($result['interface']);
        self::assertSame('elavora/code-standards', $result['name']);
        self::assertSame(['@PER-CS' => true], $result['rules']);
        self::assertFalse($result['risky']);
        self::assertFalse($result['cache']);
        self::assertSame('PhpCsFixer\\Finder', $result['finder']);
    }

    public function testFinderSelectsOnlyProjectPhpFiles(): void
    {
        self::requirePhar();
        $dir = $this->makeSampleProject();

        $result = $this->inspect($dir, $dir);

        self::assertSame(['src/A.php', 'tests/ATest.php'], $result['files']);
    }

    public function testFinderWorksOutsideAGitRepository(): void
    {
        self::requirePhar();
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/src/A.php', "<?php\n");
        $this->writeFile($dir . '/.gitignore', "build/\n");
        $this->writeFile($dir . '/build/B.php', "<?php\n");

        $result = $this->inspect($dir, $dir);

        self::assertSame(['src/A.php'], $result['files']);
    }

    public function testResultDoesNotDependOnTheCurrentDirectory(): void
    {
        self::requirePhar();
        $dir = $this->makeSampleProject();

        $fromRoot = $this->inspect($dir, $dir);
        $fromSlash = $this->inspect($dir, '/');
        $fromSrc = $this->inspect($dir, $dir . '/src');

        self::assertSame($fromRoot['files'], $fromSlash['files']);
        self::assertSame($fromRoot['files'], $fromSrc['files']);
    }

    public function testEachCallReturnsAnIndependentObject(): void
    {
        self::requirePhar();
        $dir = $this->makeSampleProject();

        $result = $this->inspect($dir, $dir);

        self::assertFalse($result['same']);
        self::assertFalse($result['sameFinder']);
        self::assertSame(['@PER-CS' => true], $result['rulesAfterChange']);
        self::assertFalse($result['riskyAfterChange']);
    }

    public function testClassFileIsSelfContained(): void
    {
        self::requirePhar();
        $dir = $this->makeSampleProject();

        $result = $this->inspect($dir, $dir);

        self::assertSame([Expected::repoRoot() . '/src/PhpCsFixer.php'], $result['loaded']);
    }

    /** Projeto de exemplo de "Comportamento observável", com o stub e o pacote em vendor/. */
    private function makeFixerProject(): string
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', Expected::STUB);
        $this->writeFile(
            $dir . '/vendor/elavora/code-standards/src/PhpCsFixer.php',
            (string) file_get_contents(Expected::repoRoot() . '/src/PhpCsFixer.php'),
        );
        $this->writeFile($dir . '/src/A.php', "<?php\nif(true){echo 1;}\n");
        $this->writeFile($dir . '/.gitignore', "build/\n");
        $this->writeFile($dir . '/build/B.php', "<?php\nif(true){echo 1;}\n");
        $this->writeFile($dir . '/vendor/x/D.php', "<?php\nif(true){echo 1;}\n");

        return $dir;
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function fixer(array $args, string $cwd): array
    {
        return Process::run([self::PHAR, ...$args], $cwd);
    }

    public function testCheckReportsTheFileWithoutChangingIt(): void
    {
        self::requirePhar();
        $dir = $this->makeFixerProject();
        $before = (string) file_get_contents($dir . '/src/A.php');

        $result = self::fixer(['check'], $dir);

        self::assertSame(8, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('src/A.php', $result['stdout']);
        self::assertSame($before, file_get_contents($dir . '/src/A.php'));
        self::assertFileDoesNotExist($dir . '/.php-cs-fixer.cache');
    }

    public function testFixFormatsWithPerCs(): void
    {
        self::requirePhar();
        $dir = $this->makeFixerProject();

        $fix = self::fixer(['fix'], $dir);

        self::assertSame(0, $fix['code'], $fix['stdout'] . $fix['stderr']);
        $formatted = (string) file_get_contents($dir . '/src/A.php');
        self::assertStringContainsString('if (true) {', $formatted);
        self::assertSame(0, self::fixer(['check'], $dir)['code'], 'Depois do fix, o check deveria passar');
        self::assertSame("<?php\nif(true){echo 1;}\n", file_get_contents($dir . '/build/B.php'));
        self::assertSame("<?php\nif(true){echo 1;}\n", file_get_contents($dir . '/vendor/x/D.php'));
        self::assertFileDoesNotExist($dir . '/.php-cs-fixer.cache');
    }

    public function testCheckWithConfigFromAnotherDirectory(): void
    {
        self::requirePhar();
        $dir = $this->makeFixerProject();

        $result = self::fixer(['check', '--config=' . $dir . '/.php-cs-fixer.dist.php'], '/');

        self::assertSame(8, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('src/A.php', $result['stdout']);
        self::assertFileDoesNotExist($dir . '/.php-cs-fixer.cache');
        self::assertFileDoesNotExist('/.php-cs-fixer.cache');
    }

    public function testListFiles(): void
    {
        self::requirePhar();
        $dir = $this->makeFixerProject();

        $result = self::fixer(['list-files'], $dir);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('src/A.php', $result['stdout']);
        self::assertStringNotContainsString('B.php', $result['stdout']);
        self::assertStringNotContainsString('D.php', $result['stdout']);
        self::assertStringNotContainsString('PhpCsFixer.php', $result['stdout']);
        self::assertStringNotContainsString('.php-cs-fixer.dist.php', $result['stdout']);
        self::assertFileDoesNotExist($dir . '/.php-cs-fixer.cache');
    }

    public function testVerboseCheckShowsTheConfigName(): void
    {
        self::requirePhar();
        $dir = $this->makeFixerProject();

        $result = self::fixer(['check', '-v'], $dir);

        self::assertStringContainsString('Loaded config elavora/code-standards', $result['stdout'] . $result['stderr']);
    }
}
