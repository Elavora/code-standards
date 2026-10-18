<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O subcomando lint, rodado como processo (php bin/elavora-standards lint ...), conforme docs/lint.md.
 */
final class LintCliTest extends TestCase
{
    use TempDirectories;

    private static function bin(): string
    {
        return Expected::repoRoot() . '/bin/elavora-standards';
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function lint(array $args, string $cwd): array
    {
        return Process::run([PHP_BINARY, self::bin(), 'lint', ...$args], $cwd);
    }

    /** @param array<string, string> $files caminho relativo => conteúdo */
    private function project(array $files): string
    {
        $dir = $this->makeTempDir();
        foreach ($files as $file => $content) {
            $this->writeFile($dir . '/' . $file, $content);
        }

        return $dir;
    }

    /**
     * Confere o formato da saída de erro e devolve os arquivos dos títulos, na ordem.
     *
     * @return list<string>
     */
    private static function assertErrorOutput(string $stderr, int $errors, int $total): array
    {
        self::assertStringEndsWith("\n", $stderr);
        $lines = explode("\n", substr($stderr, 0, -1));
        self::assertSame(Expected::lintErrorSummary($errors, $total), array_pop($lines), $stderr);

        $titles = [];
        $current = null;
        foreach ($lines as $line) {
            $titlePrefix = Expected::PREFIX . 'erro de sintaxe em ';
            if (str_starts_with($line, $titlePrefix)) {
                $current = substr($line, \strlen($titlePrefix));
                $titles[] = $current;
                continue;
            }
            self::assertNotNull($current, "Linha antes do primeiro título: {$line}");
            self::assertStringStartsWith('  ', $line, "Linha da mensagem sem os dois espaços: {$line}");
            self::assertNotSame('', trim($line));
        }
        self::assertCount($errors, $titles, $stderr);

        return $titles;
    }

    /** O bloco de erro de $file: as linhas da mensagem, sem os dois espaços. */
    private static function messageOf(string $stderr, string $file): string
    {
        $lines = explode("\n", $stderr);
        $start = array_search(Expected::lintErrorTitle($file), $lines, true);
        self::assertIsInt($start, "Sem título para {$file}:\n{$stderr}");
        $message = [];
        for ($i = $start + 1; $i < \count($lines) && str_starts_with($lines[$i], '  '); $i++) {
            $message[] = substr($lines[$i], 2);
        }

        return implode("\n", $message);
    }

    public function testAllFilesValidExitsZeroWithOneLineOnStdout(): void
    {
        $dir = $this->project([
            'src/A.php' => Expected::PHP_VALID,
            'tests/ATest.php' => Expected::PHP_VALID,
            'worker.php' => Expected::PHP_VALID,
            'vendor/x/D.php' => Expected::PHP_BROKEN,
            '.phpstan.cache/x.php' => Expected::PHP_BROKEN,
        ]);

        $result = self::lint([], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(3) . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testZeroFilesExitsZero(): void
    {
        $dir = $this->project(['README.md' => "# x\n", 'vendor/x/D.php' => Expected::PHP_BROKEN]);

        $result = self::lint([], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(0) . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testEverythingExcludedExitsZeroWithZeroFiles(): void
    {
        $dir = $this->project(['src/Quebrado.php' => Expected::PHP_BROKEN]);

        $result = self::lint(['--exclude=src'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(0) . "\n", $result['stdout']);
    }

    public function testSyntaxErrorExitsOneWithTheDocumentedFormat(): void
    {
        $dir = $this->project([
            'src/A.php' => Expected::PHP_VALID,
            'src/Quebrado.php' => Expected::PHP_BROKEN,
            'tests/ATest.php' => Expected::PHP_VALID,
        ]);

        $result = self::lint([], $dir);

        self::assertSame(1, $result['code'], $result['stderr']);
        self::assertSame('', $result['stdout'], 'Nada vai para a saída padrão quando há erro');
        self::assertSame(['src/Quebrado.php'], self::assertErrorOutput($result['stderr'], 1, 3));
        $message = self::messageOf($result['stderr'], 'src/Quebrado.php');
        self::assertStringContainsString('src/Quebrado.php', $message);
        self::assertMatchesRegularExpression('/on line \d+/', $message);
        self::assertStringNotContainsString('Errors parsing ', $result['stderr']);
        self::assertStringNotContainsString('src/A.php', $result['stderr'], 'Os arquivos sem erro não aparecem');
        self::assertStringNotContainsString('tests/ATest.php', $result['stderr']);
    }

    public function testSeveralErrorsAppearInFileOrder(): void
    {
        $dir = $this->project([
            'z.php' => Expected::PHP_BROKEN,
            'a.php' => Expected::PHP_BROKEN,
            'm.php' => Expected::PHP_VALID,
            'src/b.php' => Expected::PHP_BROKEN,
        ]);

        $result = self::lint([], $dir);

        self::assertSame(1, $result['code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame(['a.php', 'src/b.php', 'z.php'], self::assertErrorOutput($result['stderr'], 3, 4));
        foreach (['a.php', 'src/b.php', 'z.php'] as $file) {
            self::assertStringContainsString($file, self::messageOf($result['stderr'], $file));
        }
    }

    public function testCompileTimeDeprecationExitsZeroAndDoesNotAppear(): void
    {
        $dir = $this->project(['src/Antigo.php' => Expected::PHP_DEPRECATED, 'src/A.php' => Expected::PHP_VALID]);

        $result = self::lint([], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(2) . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testPathsOnTheLineReplaceTheDefault(): void
    {
        $dir = $this->project([
            'src/A.php' => Expected::PHP_VALID,
            'bin/console' => Expected::PHP_VALID,
            'tests/Quebrado.php' => Expected::PHP_BROKEN,
        ]);

        $result = self::lint(['src', 'bin/console'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(2) . "\n", $result['stdout']);
    }

    public function testDotPlusAFileWithoutExtension(): void
    {
        $dir = $this->project(['src/A.php' => Expected::PHP_VALID, 'bin/console' => Expected::PHP_BROKEN]);

        $result = self::lint(['.', 'bin/console'], $dir);

        self::assertSame(1, $result['code'], $result['stderr']);
        self::assertSame(['bin/console'], self::assertErrorOutput($result['stderr'], 1, 2));
    }

    public function testExcludeRemovesAFolder(): void
    {
        $dir = $this->project(['src/A.php' => Expected::PHP_VALID, 'tests/fixtures/Quebrado.php' => Expected::PHP_BROKEN]);

        $result = self::lint(['--exclude=tests/fixtures'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(1) . "\n", $result['stdout']);
    }

    public function testExcludeCanBeRepeated(): void
    {
        $dir = $this->project([
            'src/A.php' => Expected::PHP_VALID,
            'a/Quebrado.php' => Expected::PHP_BROKEN,
            'b/Quebrado.php' => Expected::PHP_BROKEN,
        ]);

        $result = self::lint(['--exclude=a', '--exclude=./b/'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(1) . "\n", $result['stdout']);
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function argumentOrders(): iterable
    {
        yield 'opções antes' => [['--dir=projeto', '--exclude=src/Legado', 'src']];
        yield 'caminho antes' => [['src', '--dir=projeto', '--exclude=src/Legado']];
        yield 'misturado' => [['--exclude=src/Legado', 'src', '--dir=projeto']];
    }

    /** @param list<string> $args */
    #[DataProvider('argumentOrders')]
    public function testOptionsAndPathsInAnyOrder(array $args): void
    {
        $cwd = $this->project([
            'projeto/src/A.php' => Expected::PHP_VALID,
            'projeto/src/Legado/Quebrado.php' => Expected::PHP_BROKEN,
            'projeto/tests/Quebrado.php' => Expected::PHP_BROKEN,
        ]);

        $result = self::lint($args, $cwd);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(1) . "\n", $result['stdout']);
    }

    public function testDirWithAbsolutePath(): void
    {
        $cwd = $this->project(['Quebrado.php' => Expected::PHP_BROKEN]);
        $target = $this->project(['src/A.php' => Expected::PHP_VALID, 'src/B.php' => Expected::PHP_VALID]);

        $result = self::lint(['--dir=' . $target], $cwd);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(2) . "\n", $result['stdout']);
    }

    public function testDirWithRelativePathAndPathsRelativeToTheProject(): void
    {
        $cwd = $this->project([
            'Quebrado.php' => Expected::PHP_BROKEN,
            'projeto/sub/src/Quebrado.php' => Expected::PHP_BROKEN,
            'projeto/sub/src/A.php' => Expected::PHP_VALID,
        ]);

        $result = self::lint(['--dir=projeto/sub', 'src'], $cwd);

        self::assertSame(1, $result['code'], $result['stderr']);
        self::assertSame(['src/Quebrado.php'], self::assertErrorOutput($result['stderr'], 1, 2));
        self::assertStringContainsString('src/Quebrado.php', self::messageOf($result['stderr'], 'src/Quebrado.php'));
        self::assertStringNotContainsString('projeto/sub', $result['stderr'], 'O caminho mostrado é relativo à pasta do projeto');
    }

    public function testFileOutsideTheProjectIsShownAbsolute(): void
    {
        $base = $this->project(['projeto/src/A.php' => Expected::PHP_VALID, 'fora/Quebrado.php' => Expected::PHP_BROKEN]);

        $result = self::lint(['src', '../fora/Quebrado.php'], $base . '/projeto');

        self::assertSame(1, $result['code'], $result['stderr']);
        self::assertSame([$base . '/fora/Quebrado.php'], self::assertErrorOutput($result['stderr'], 1, 2));
    }

    public function testLintWritesNothing(): void
    {
        $dir = $this->project(['src/A.php' => Expected::PHP_VALID, 'src/Quebrado.php' => Expected::PHP_BROKEN]);
        $before = $this->snapshot($dir);

        self::lint([], $dir);

        self::assertSame($before, $this->snapshot($dir));
    }

    public function testDirThatDoesNotExistExitsOne(): void
    {
        $cwd = $this->project(['Quebrado.php' => Expected::PHP_BROKEN]);

        $result = self::lint(['--dir=nao-existe'], $cwd);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertStringContainsString('nao-existe', $result['stderr']);
        self::assertStringNotContainsString('erro de sintaxe', $result['stderr'], 'Nada é conferido');
    }

    public function testDirThatIsAFileExitsOne(): void
    {
        $cwd = $this->project(['arquivo.php' => Expected::PHP_VALID]);

        $result = self::lint(['--dir=arquivo.php'], $cwd);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertStringContainsString('arquivo.php', $result['stderr']);
    }

    public function testPathThatDoesNotExistExitsOneAndChecksNothing(): void
    {
        $dir = $this->project(['src/Quebrado.php' => Expected::PHP_BROKEN]);

        $result = self::lint(['src', 'nao-existe/X.php'], $dir);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertStringContainsString('nao-existe/X.php', $result['stderr']);
        self::assertStringNotContainsString('erro de sintaxe', $result['stderr'], 'Nada é conferido');
    }

    public function testExcludeThatDoesNotExistIsIgnoredSilently(): void
    {
        $dir = $this->project(['src/A.php' => Expected::PHP_VALID]);

        $result = self::lint(['--exclude=nao-existe'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::lintOk(1) . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function usageErrors(): iterable
    {
        yield '--force' => [['--force']];
        yield '--bar' => [['--bar']];
        yield '-x' => [['-x']];
        yield '--dir sem valor' => [['--dir']];
        yield '--dir= vazio' => [['--dir=']];
        yield '--exclude sem valor' => [['--exclude']];
        yield '--exclude= vazio' => [['--exclude=']];
        yield '--dir repetido' => [['--dir=.', '--dir=.']];
        yield 'opção desconhecida depois de um caminho' => [['src', '--bar']];
    }

    /** @param list<string> $args */
    #[DataProvider('usageErrors')]
    public function testUsageErrorsExitTwo(array $args): void
    {
        $dir = $this->project(['src/Quebrado.php' => Expected::PHP_BROKEN]);
        $before = $this->snapshot($dir);

        $result = self::lint($args, $dir);

        self::assertSame(2, $result['code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString(Expected::LINT_USAGE_LINE, $result['stderr']);
        self::assertStringNotContainsString('erro de sintaxe', $result['stderr'], 'Nada é conferido');
        self::assertSame($before, $this->snapshot($dir));
    }

    /** @return iterable<string, array{0: string}> */
    public static function helpArguments(): iterable
    {
        yield 'help' => ['help'];
        yield '--help' => ['--help'];
        yield '-h' => ['-h'];
    }

    #[DataProvider('helpArguments')]
    public function testHelpShowsBothUsageLines(string $argument): void
    {
        $dir = $this->makeTempDir();

        $result = Process::run([PHP_BINARY, self::bin(), $argument], $dir);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString(Expected::USAGE_LINE, $result['stdout']);
        self::assertStringContainsString(Expected::LINT_USAGE_LINE, $result['stdout']);
    }

    public function testSyncUsageErrorAlsoShowsTheLintLine(): void
    {
        $dir = $this->makeTempDir();

        $result = Process::run([PHP_BINARY, self::bin(), 'sync', '--bar'], $dir);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString(Expected::USAGE_LINE, $result['stderr']);
        self::assertStringContainsString(Expected::LINT_USAGE_LINE, $result['stderr']);
    }

    /** O script "lint" deste repositório (docs/desenvolvimento.md): o repositório inteiro e o próprio comando. */
    public function testThisRepositoryPassesItsOwnLint(): void
    {
        $result = Process::run([PHP_BINARY, self::bin(), 'lint', '.', 'bin/elavora-standards'], Expected::repoRoot());

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertMatchesRegularExpression(
            '/^' . preg_quote(Expected::PREFIX, '/') . 'lint ok: [1-9]\d* arquivo\(s\) sem erro de sintaxe\n$/',
            $result['stdout'],
        );
    }
}
