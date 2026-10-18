<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Lint;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lint::files(): quais arquivos são conferidos (docs/lint.md, "Quais arquivos são conferidos" e
 * "Classe Elavora\CodeStandards\Lint").
 */
final class LintFilesTest extends TestCase
{
    use TempDirectories;

    /** @param list<string> $files */
    private function project(array $files): string
    {
        $dir = $this->makeTempDir();
        foreach ($files as $file) {
            $this->writeFile($dir . '/' . $file, Expected::PHP_VALID);
        }

        return $dir;
    }

    /** A pasta dos exemplos da referência: src/A.php, src/Legado/B.php, tests/ATest.php, bin/console e vendor/x/D.php. */
    private function referenceProject(): string
    {
        return $this->project(['src/A.php', 'src/Legado/B.php', 'tests/ATest.php', 'bin/console', 'vendor/x/D.php']);
    }

    /** @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}> */
    public static function referenceExamples(): iterable
    {
        $all = ['src/A.php', 'src/Legado/B.php', 'tests/ATest.php'];

        yield 'padrão' => [[], [], $all];
        yield '.' => [['.'], [], $all];
        yield 'src' => [['src'], [], ['src/A.php', 'src/Legado/B.php']];
        yield './src/' => [['./src/'], [], ['src/A.php', 'src/Legado/B.php']];
        yield 'exclude src/Legado' => [[], ['src/Legado'], ['src/A.php', 'tests/ATest.php']];
        yield 'exclude src/Leg (pasta inteira)' => [[], ['src/Leg'], $all];
        yield '. mais bin/console' => [['.', 'bin/console'], [], ['bin/console', ...$all]];
        yield 'src e src/A.php repetido' => [['src', 'src/A.php'], [], ['src/A.php', 'src/Legado/B.php']];
        yield 'src/A.php com exclude src' => [['src/A.php'], ['src'], []];
        yield 'vendor/x pedido pelo nome' => [['vendor/x'], [], ['vendor/x/D.php']];
        yield 'exclude que não existe' => [[], ['nao-existe'], $all];
    }

    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @param list<string> $expected
     */
    #[DataProvider('referenceExamples')]
    public function testReferenceExamples(array $paths, array $exclude, array $expected): void
    {
        $dir = $this->referenceProject();

        self::assertSame($expected, Lint::files($dir, $paths, $exclude));
    }

    public function testDefaultArgumentsAreTheWholeFolder(): void
    {
        $dir = $this->referenceProject();

        self::assertSame(['src/A.php', 'src/Legado/B.php', 'tests/ATest.php'], Lint::files($dir));
    }

    public function testMissingPathThrows(): void
    {
        $dir = $this->referenceProject();

        try {
            Lint::files($dir, ['nao-existe']);
            self::fail('Deveria lançar \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nao-existe', $e->getMessage());
        }
    }

    public function testMissingPathThrowsEvenWithValidPaths(): void
    {
        $dir = $this->referenceProject();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('src/Nada.php');

        Lint::files($dir, ['src', 'src/Nada.php']);
    }

    public function testDirThatDoesNotExistThrows(): void
    {
        $missing = $this->makeTempDir() . '/nao-existe';

        try {
            Lint::files($missing);
            self::fail('Deveria lançar \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        }
    }

    public function testDirThatIsAFileThrows(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/arquivo.php';
        $this->writeFile($file, Expected::PHP_VALID);

        try {
            Lint::files($file);
            self::fail('Deveria lançar \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($file, $e->getMessage());
        }
    }

    public function testDocumentedDefaultExample(): void
    {
        $dir = $this->project([
            'src/A.php',
            'src/B.PHP',
            'tests/ATest.php',
            'worker.php',
            'bin/console',
            '.php-cs-fixer.dist.php',
            '.phpstan.cache/x.php',
            'vendor/x/D.php',
            'tests/fixtures/vendor/E.php',
        ]);

        self::assertSame(['src/A.php', 'src/B.PHP', 'tests/ATest.php', 'worker.php'], Lint::files($dir));
    }

    public function testExtensionIsCaseInsensitiveAndOnlyPhp(): void
    {
        $dir = $this->project(['a.php', 'b.PHP', 'c.Php', 'd.phtml', 'e.php.bak', 'f.inc', 'php', 'g.txt']);

        self::assertSame(['a.php', 'b.PHP', 'c.Php'], Lint::files($dir));
    }

    public function testVendorFoldersAreExcludedAtAnyLevel(): void
    {
        $dir = $this->project([
            'vendor/a/A.php',
            'src/vendor/B.php',
            'tests/fixtures/vendor/deep/C.php',
            'src/vendor.php',
            'src/vendors/D.php',
        ]);

        self::assertSame(['src/vendor.php', 'src/vendors/D.php'], Lint::files($dir));
    }

    public function testNamesStartingWithADotAreExcludedAtAnyLevel(): void
    {
        $dir = $this->project([
            '.php-cs-fixer.dist.php',
            '.git/hooks/x.php',
            '.github/scripts/y.php',
            '.elavora/z.php',
            'src/.cache/w.php',
            'src/.oculto.php',
            'src/A.php',
        ]);

        self::assertSame(['src/A.php'], Lint::files($dir));
    }

    public function testDottedFolderRequestedByNameIsWalked(): void
    {
        $dir = $this->project(['.github/scripts/y.php', '.github/scripts/.interno/z.php', '.github/scripts/vendor/v.php']);

        self::assertSame(['.github/scripts/y.php'], Lint::files($dir, ['.github/scripts']));
    }

    public function testDottedFileRequestedByNameIsChecked(): void
    {
        $dir = $this->project(['.php-cs-fixer.dist.php', 'src/A.php']);

        self::assertSame(['.php-cs-fixer.dist.php'], Lint::files($dir, ['.php-cs-fixer.dist.php']));
    }

    public function testFileRequestedByNameIsCheckedWhateverTheExtension(): void
    {
        $dir = $this->project(['bin/console', 'notas.txt']);

        self::assertSame(['bin/console', 'notas.txt'], Lint::files($dir, ['notas.txt', 'bin/console']));
    }

    public function testPathsReplaceTheDefault(): void
    {
        $dir = $this->project(['src/A.php', 'tests/ATest.php']);

        self::assertSame(['src/A.php'], Lint::files($dir, ['src']));
    }

    public function testDotIsTheSameAsTheDefault(): void
    {
        $dir = $this->project(['src/A.php', 'worker.php', '.x.php', 'vendor/v.php']);

        self::assertSame(Lint::files($dir), Lint::files($dir, ['.']));
    }

    public function testSymlinkedFoldersAreNotFollowed(): void
    {
        $dir = $this->project(['src/A.php']);
        $outside = $this->project(['Fora.php']);
        if (!@symlink($outside, $dir . '/link-pasta') || !@symlink($dir . '/src', $dir . '/src/ciclo')) {
            self::markTestSkipped('Não foi possível criar links simbólicos.');
        }

        self::assertSame(['src/A.php'], Lint::files($dir));
    }

    public function testSymlinkToAPhpFileIsCheckedAsAFile(): void
    {
        $dir = $this->project(['src/A.php']);
        $outside = $this->project(['Fora.php']);
        if (!@symlink($outside . '/Fora.php', $dir . '/src/Link.php')) {
            self::markTestSkipped('Não foi possível criar links simbólicos.');
        }

        self::assertSame(['src/A.php', 'src/Link.php'], Lint::files($dir));
    }

    /** @return iterable<string, array{0: string}> */
    public static function equivalentExcludes(): iterable
    {
        yield 'tests/fixtures' => ['tests/fixtures'];
        yield './tests/fixtures' => ['./tests/fixtures'];
        yield 'tests/fixtures/' => ['tests/fixtures/'];
        yield './tests/fixtures/' => ['./tests/fixtures/'];
    }

    #[DataProvider('equivalentExcludes')]
    public function testExcludeIgnoresLeadingDotSlashAndTrailingSlash(string $exclude): void
    {
        $dir = $this->project(['tests/ATest.php', 'tests/fixtures/A.php', 'tests/fixtures/sub/B.php', 'tests/fixtures2/C.php']);

        self::assertSame(['tests/ATest.php', 'tests/fixtures2/C.php'], Lint::files($dir, [], [$exclude]));
    }

    public function testExcludeWithAbsolutePath(): void
    {
        $dir = $this->project(['src/A.php', 'tests/fixtures/A.php']);

        self::assertSame(['src/A.php'], Lint::files($dir, [], [$dir . '/tests/fixtures']));
    }

    public function testExcludeComparesWholeFolders(): void
    {
        $dir = $this->project(['tests/fixtures/A.php', 'tests/fix/B.php']);

        self::assertSame(['tests/fixtures/A.php'], Lint::files($dir, [], ['tests/fix']));
    }

    public function testExcludeASingleFile(): void
    {
        $dir = $this->project(['src/A.php', 'src/B.php']);

        self::assertSame(['src/B.php'], Lint::files($dir, [], ['src/A.php']));
    }

    public function testExcludeCanBeRepeated(): void
    {
        $dir = $this->project(['src/A.php', 'src/Legado/B.php', 'tests/ATest.php', 'worker.php']);

        self::assertSame(['src/A.php'], Lint::files($dir, [], ['src/Legado', 'tests', 'worker.php']));
    }

    public function testExcludeAppliesToRequestedPaths(): void
    {
        $dir = $this->project(['src/A.php', 'src/Legado/B.php', 'tests/ATest.php']);

        self::assertSame(['src/A.php'], Lint::files($dir, ['src'], ['src/Legado']));
    }

    public function testExcludeIsLiteralWithoutWildcards(): void
    {
        $dir = $this->project(['src/A.php', 'tests/ATest.php']);

        self::assertSame(['src/A.php', 'tests/ATest.php'], Lint::files($dir, [], ['*', '**', 'src/*']));
    }

    public function testOrderIsByteOrderOfTheShownPath(): void
    {
        $dir = $this->project(['a.php', 'B.php', 'Z.php', 'src/A.php', 'src-x/A.php', 'src/a.php', '_.php']);

        $expected = ['B.php', 'Z.php', '_.php', 'a.php', 'src-x/A.php', 'src/A.php', 'src/a.php'];
        $sorted = $expected;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $expected, 'O próprio exemplo precisa estar na ordem de sort() com SORT_STRING');

        self::assertSame($expected, Lint::files($dir));
    }

    public function testPathsGivenOutOfOrderAreSorted(): void
    {
        $dir = $this->project(['src/A.php', 'tests/ATest.php', 'bin/console']);

        self::assertSame(['bin/console', 'src/A.php', 'tests/ATest.php'], Lint::files($dir, ['tests', 'bin/console', 'src']));
    }

    public function testRepeatedFilesAppearOnce(): void
    {
        $dir = $this->project(['src/A.php']);

        self::assertSame(['src/A.php'], Lint::files($dir, ['src/A.php', './src/A.php', 'src', '.', $dir . '/src/A.php']));
    }

    public function testAbsolutePathInsideTheProjectIsShownRelative(): void
    {
        $dir = $this->project(['src/A.php', 'src/B.php']);

        self::assertSame(['src/A.php', 'src/B.php'], Lint::files($dir, [$dir . '/src']));
        self::assertSame(['src/A.php'], Lint::files($dir, [$dir . '/src/A.php']));
    }

    public function testFileOutsideTheProjectIsShownAbsolute(): void
    {
        $base = $this->makeTempDir();
        $this->writeFile($base . '/projeto/src/A.php', Expected::PHP_VALID);
        $this->writeFile($base . '/fora/X.php', Expected::PHP_VALID);
        $dir = $base . '/projeto';

        self::assertSame([$base . '/fora/X.php'], Lint::files($dir, [$base . '/fora/X.php']));
        self::assertSame([$base . '/fora/X.php'], Lint::files($dir, ['../fora/X.php']));
    }

    public function testZeroFilesIsAnEmptyList(): void
    {
        $dir = $this->project(['README.md', 'vendor/x/D.php', '.x.php']);

        self::assertSame([], Lint::files($dir));
    }

    public function testEmptyFolderIsAnEmptyList(): void
    {
        self::assertSame([], Lint::files($this->makeTempDir()));
    }

    public function testResultDoesNotDependOnTheCurrentDirectory(): void
    {
        $dir = $this->referenceProject();
        $other = $this->project(['outro/Z.php']);
        $expected = ['src/A.php', 'src/Legado/B.php', 'tests/ATest.php'];
        $cwd = (string) getcwd();

        chdir($other);
        try {
            $default = Lint::files($dir);
            $withPaths = Lint::files($dir, ['src', 'tests']);
            $withExclude = Lint::files($dir, [], ['src/Legado']);
        } finally {
            chdir($cwd);
        }

        self::assertSame($expected, $default);
        self::assertSame($expected, $withPaths);
        self::assertSame(['src/A.php', 'tests/ATest.php'], $withExclude);
    }

    public function testFilesWritesNothing(): void
    {
        $dir = $this->referenceProject();
        $before = $this->snapshot($dir);

        Lint::files($dir);
        Lint::files($dir, ['.', 'bin/console'], ['src/Legado']);

        self::assertSame($before, $this->snapshot($dir));
    }

    public function testLintClassIsFinalWithOnlyStaticMembers(): void
    {
        $class = new \ReflectionClass(Lint::class);

        self::assertTrue($class->isFinal());
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' deveria ser estático');
        }
        self::assertTrue($class->getMethod('files')->isPublic());
        self::assertTrue($class->getMethod('check')->isPublic());
    }
}
