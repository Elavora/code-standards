<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Exception\MalformedMarkersException;
use Elavora\CodeStandards\Status;
use Elavora\CodeStandards\Sync;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    use TempDirectories;

    public function testClassIsFinal(): void
    {
        self::assertTrue((new \ReflectionClass(Sync::class))->isFinal());
    }

    public function testStubConstantIsExact(): void
    {
        self::assertSame(Expected::STUB, Sync::STUB);
    }

    public function testEmptyDirectoryCreatesBothFiles(): void
    {
        $dir = $this->makeTempDir();

        $result = Sync::run($dir);

        self::assertSame(['editorconfig' => Status::Created, 'stub' => Status::Created], $result);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    public function testOnlyTheTwoFilesAreCreated(): void
    {
        $dir = $this->makeTempDir();

        Sync::run($dir);

        self::assertSame(['.editorconfig', '.php-cs-fixer.dist.php'], array_keys($this->snapshot($dir)));
    }

    public function testResultHasExactlyTheTwoKeys(): void
    {
        $dir = $this->makeTempDir();

        $result = Sync::run($dir);

        self::assertSame(['editorconfig', 'stub'], array_keys($result));
    }

    /** @return iterable<string, array{0: string}> */
    public static function blankContents(): iterable
    {
        yield 'vazio' => [''];
        yield 'só quebras de linha' => ["\n\n"];
        yield 'espaços, TABs e quebras de linha' => ["  \t\r\n \n"];
    }

    #[DataProvider('blankContents')]
    public function testBlankEditorconfigIsRewritten(string $content): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', $content);

        $result = Sync::run($dir);

        self::assertSame(Status::Updated, $result['editorconfig']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
    }

    public function testEqualBlockWritesNothing(): void
    {
        $dir = $this->makeTempDir();
        $content = "# projeto\n" . Expected::block() . "\n[*.sql]\nindent_size = 2\n";
        $this->writeFile($dir . '/.editorconfig', $content);
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', Expected::STUB);
        $this->ageFile($dir . '/.editorconfig');
        $this->ageFile($dir . '/.php-cs-fixer.dist.php');
        $before = $this->snapshot($dir);

        $result = Sync::run($dir);

        self::assertSame(['editorconfig' => Status::Unchanged, 'stub' => Status::Unchanged], $result);
        self::assertSame($before, $this->snapshot($dir));
    }

    public function testSecondRunChangesNothing(): void
    {
        $dir = $this->makeTempDir();
        Sync::run($dir);
        $this->ageFile($dir . '/.editorconfig');
        $this->ageFile($dir . '/.php-cs-fixer.dist.php');
        $before = $this->snapshot($dir);

        $result = Sync::run($dir);

        self::assertSame(['editorconfig' => Status::Unchanged, 'stub' => Status::Unchanged], $result);
        self::assertSame($before, $this->snapshot($dir));
    }

    public function testDifferentBlockIsReplacedKeepingTheRest(): void
    {
        $dir = $this->makeTempDir();
        $old = Expected::BEGIN_MARKER . "\nroot = true\n" . Expected::END_MARKER . "\n";
        $this->writeFile($dir . '/.editorconfig', "# projeto\n\n" . $old . "\n[*.sql]\nindent_size = 2\n");

        $result = Sync::run($dir);

        self::assertSame(Status::Updated, $result['editorconfig']);
        self::assertSame(
            "# projeto\n\n" . Expected::block() . "\n[*.sql]\nindent_size = 2\n",
            file_get_contents($dir . '/.editorconfig'),
        );
    }

    public function testEditedBlockIsRestored(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', str_replace('indent_size = 4', 'indent_size = 8', Expected::block()));

        $result = Sync::run($dir);

        self::assertSame(Status::Updated, $result['editorconfig']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
    }

    public function testPreservedPartsAreWrittenWithLf(): void
    {
        $dir = $this->makeTempDir();
        $old = Expected::BEGIN_MARKER . "\r\nroot = true\r\n" . Expected::END_MARKER . "\r\n";
        $this->writeFile($dir . '/.editorconfig', "# projeto\r\n" . $old . "[*.sql]\rindent_size = 2");

        $result = Sync::run($dir);

        self::assertSame(Status::Updated, $result['editorconfig']);
        self::assertSame(
            "# projeto\n" . Expected::block() . "[*.sql]\nindent_size = 2\n",
            file_get_contents($dir . '/.editorconfig'),
        );
    }

    public function testBlockWithCrlfIsUpdatedToLf(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', str_replace("\n", "\r\n", Expected::block()));

        $result = Sync::run($dir);

        self::assertSame(Status::Updated, $result['editorconfig']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
    }

    public function testUnmanagedEditorconfigIsSkippedAndStubStillHandled(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);
        $this->ageFile($dir . '/.editorconfig');
        $before = $this->snapshot($dir)['.editorconfig'];

        $result = Sync::run($dir);

        self::assertSame(['editorconfig' => Status::Skipped, 'stub' => Status::Created], $result);
        self::assertSame($before, $this->snapshot($dir)['.editorconfig']);
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedContents(): iterable
    {
        yield 'falta o fim' => [Expected::malformed()];
        yield 'repetidos' => [Expected::block() . Expected::block()];
        yield 'fim antes do início' => [Expected::END_MARKER . "\n" . Expected::BEGIN_MARKER . "\n"];
    }

    #[DataProvider('malformedContents')]
    public function testMalformedMarkersPropagateAndNothingIsWritten(string $content): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', $content);
        $this->ageFile($dir . '/.editorconfig');
        $before = $this->snapshot($dir);

        try {
            Sync::run($dir);
            self::fail('MalformedMarkersException esperada');
        } catch (MalformedMarkersException) {
        }

        self::assertSame($before, $this->snapshot($dir), 'Nada deveria ser escrito, nem o stub');
        self::assertFileDoesNotExist($dir . '/.php-cs-fixer.dist.php');
    }

    /** @return iterable<string, array{0: string|null, 1: string}> */
    public static function forceCases(): iterable
    {
        yield 'não existe' => [null, 'Created'];
        yield 'já é igual' => [Expected::block(), 'Unchanged'];
        yield 'vazio' => ['', 'Updated'];
        yield 'sem marcadores' => [Expected::UNMANAGED, 'Updated'];
        yield 'marcadores quebrados' => [Expected::malformed(), 'Updated'];
        yield 'bloco com seção própria' => [Expected::block() . "\n[*.sql]\nindent_size = 2\n", 'Updated'];
    }

    #[DataProvider('forceCases')]
    public function testForceReplacesTheWholeFile(?string $content, string $expectedName): void
    {
        $expected = \constant(Status::class . '::' . $expectedName);
        $dir = $this->makeTempDir();
        if ($content !== null) {
            $this->writeFile($dir . '/.editorconfig', $content);
            $this->ageFile($dir . '/.editorconfig');
        }
        $mtime = $content === null ? null : filemtime($dir . '/.editorconfig');

        $result = Sync::run($dir, true);

        self::assertSame($expected, $result['editorconfig']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        if ($expected === Status::Unchanged) {
            clearstatcache();
            self::assertSame($mtime, filemtime($dir . '/.editorconfig'));
        }
    }

    public function testForceDefaultsToFalse(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);

        self::assertSame(Status::Skipped, Sync::run($dir)['editorconfig']);
        self::assertSame(Expected::UNMANAGED, file_get_contents($dir . '/.editorconfig'));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function existingStubs(): iterable
    {
        yield 'stub padrão' => [Expected::STUB, false];
        yield 'stub com ajustes' => ["<?php\n\nreturn (new PhpCsFixer\\Config())->setRules([]);\n", false];
        yield 'vazio' => ['', false];
        yield 'stub com ajustes, com force' => ["<?php\n// projeto\n", true];
        yield 'vazio, com force' => ['', true];
    }

    #[DataProvider('existingStubs')]
    public function testExistingStubIsNeverRewritten(string $content, bool $force): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', $content);
        $this->ageFile($dir . '/.php-cs-fixer.dist.php');
        $before = $this->snapshot($dir)['.php-cs-fixer.dist.php'];

        $result = Sync::run($dir, $force);

        self::assertSame(Status::Unchanged, $result['stub']);
        self::assertSame($before, $this->snapshot($dir)['.php-cs-fixer.dist.php']);
    }

    public function testPersonalOverrideDoesNotChangeTheStubRule(): void
    {
        $dir = $this->makeTempDir();
        $override = "<?php\n\nreturn require __DIR__ . '/.php-cs-fixer.dist.php';\n";
        $this->writeFile($dir . '/.php-cs-fixer.php', $override);

        $result = Sync::run($dir);

        self::assertSame(Status::Created, $result['stub']);
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
        self::assertSame($override, file_get_contents($dir . '/.php-cs-fixer.php'));
    }

    public function testNonexistentDirectoryThrowsInvalidArgument(): void
    {
        $dir = $this->makeTempDir() . '/nao-existe';

        try {
            Sync::run($dir);
            self::fail('InvalidArgumentException esperada');
        } catch (\InvalidArgumentException) {
        }

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testFileInsteadOfDirectoryThrowsInvalidArgument(): void
    {
        $parent = $this->makeTempDir();
        $file = $parent . '/arquivo';
        $this->writeFile($file, 'x');
        $before = $this->snapshot($parent);

        try {
            Sync::run($file);
            self::fail('InvalidArgumentException esperada');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($before, $this->snapshot($parent));
    }

    public function testEmptyPathThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sync::run('');
    }

    public function testWriteFailureThrowsRuntimeExceptionWithThePath(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('Como root, a falta de permissão de escrita não tem efeito.');
        }
        $dir = $this->makeTempDir();
        chmod($dir, 0o555);

        try {
            Sync::run($dir);
            self::fail('RuntimeException esperada');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(MalformedMarkersException::class, $e);
            self::assertStringContainsString($dir . '/.editorconfig', $e->getMessage());
        } finally {
            chmod($dir, 0o777);
        }
    }

    public function testStubWriteFailureThrowsRuntimeExceptionWithThePath(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('Como root, a falta de permissão de escrita não tem efeito.');
        }
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::block());
        chmod($dir, 0o555);

        try {
            Sync::run($dir);
            self::fail('RuntimeException esperada');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($dir . '/.php-cs-fixer.dist.php', $e->getMessage());
        } finally {
            chmod($dir, 0o777);
        }
    }

    public function testReadFailureThrowsRuntimeExceptionWithThePath(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('Como root, a falta de permissão de leitura não tem efeito.');
        }
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::block());
        chmod($dir . '/.editorconfig', 0o000);

        try {
            Sync::run($dir);
            self::fail('RuntimeException esperada');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($dir . '/.editorconfig', $e->getMessage());
        } finally {
            chmod($dir . '/.editorconfig', 0o666);
        }
    }
}
