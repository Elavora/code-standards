<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O comando bin/elavora-standards, rodado como processo (php bin/elavora-standards ...).
 */
final class CliTest extends TestCase
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
    private static function cli(array $args, string $cwd): array
    {
        return Process::run([PHP_BINARY, self::bin(), ...$args], $cwd);
    }

    public function testBinaryHasPhpShebang(): void
    {
        self::assertFileExists(self::bin());
        $firstLine = strtok((string) file_get_contents(self::bin()), "\n");
        self::assertSame('#!/usr/bin/env php', $firstLine);
    }

    public function testSyncInCurrentDirectory(): void
    {
        $dir = $this->makeTempDir();

        $result = self::cli(['sync'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::MSG_EDITORCONFIG_CREATED . "\n" . Expected::MSG_STUB_CREATED . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    public function testSyncTwiceReportsUnchanged(): void
    {
        $dir = $this->makeTempDir();
        self::cli(['sync'], $dir);

        $result = self::cli(['sync'], $dir);

        self::assertSame(0, $result['code']);
        self::assertSame(Expected::MSG_EDITORCONFIG_UNCHANGED . "\n" . Expected::MSG_STUB_KEPT . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testSyncUpdatesTheBlock(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', "\n");
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', Expected::STUB);

        $result = self::cli(['sync'], $dir);

        self::assertSame(0, $result['code']);
        self::assertSame(Expected::MSG_EDITORCONFIG_UPDATED . "\n" . Expected::MSG_STUB_KEPT . "\n", $result['stdout']);
    }

    public function testUnmanagedFileWarnsOnStderrAndExitsZero(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);

        $result = self::cli(['sync'], $dir);

        self::assertSame(0, $result['code']);
        self::assertSame(Expected::MSG_EDITORCONFIG_KEPT . "\n", $result['stderr']);
        self::assertSame(Expected::MSG_STUB_CREATED . "\n", $result['stdout']);
        self::assertSame(Expected::UNMANAGED, file_get_contents($dir . '/.editorconfig'));
    }

    public function testDirWithAbsolutePath(): void
    {
        $cwd = $this->makeTempDir();
        $target = $this->makeTempDir();

        $result = self::cli(['sync', '--dir=' . $target], $cwd);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame([], $this->snapshot($cwd));
        self::assertSame(Expected::block(), file_get_contents($target . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($target . '/.php-cs-fixer.dist.php'));
    }

    public function testDirWithRelativePath(): void
    {
        $cwd = $this->makeTempDir();
        mkdir($cwd . '/projeto/sub', 0o777, true);

        $result = self::cli(['sync', '--dir=projeto/sub'], $cwd);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileExists($cwd . '/projeto/sub/.editorconfig');
        self::assertFileExists($cwd . '/projeto/sub/.php-cs-fixer.dist.php');
        self::assertFileDoesNotExist($cwd . '/.editorconfig');
        self::assertFileDoesNotExist($cwd . '/projeto/.editorconfig');
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function forceArgumentOrders(): iterable
    {
        yield '--force' => [['sync', '--force']];
        yield '--force --dir' => [['sync', '--force', '--dir=.']];
        yield '--dir --force' => [['sync', '--dir=.', '--force']];
    }

    /** @param list<string> $args */
    #[DataProvider('forceArgumentOrders')]
    public function testForceReplacesTheFile(array $args): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', "<?php\n// projeto\n");

        $result = self::cli($args, $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::MSG_EDITORCONFIG_UPDATED . "\n" . Expected::MSG_STUB_KEPT . "\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        self::assertSame("<?php\n// projeto\n", file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    public function testForceWithBrokenMarkers(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());

        $result = self::cli(['sync', '--force'], $dir);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
    }

    public function testBrokenMarkersExitWithOneAndWriteNothing(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());
        $before = $this->snapshot($dir);

        $result = self::cli(['sync'], $dir);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertStringEndsWith("\n", $result['stderr']);
        self::assertSame($before, $this->snapshot($dir));
    }

    public function testDirThatDoesNotExistExitsWithOne(): void
    {
        $cwd = $this->makeTempDir();

        $result = self::cli(['sync', '--dir=' . $cwd . '/nao-existe'], $cwd);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertSame([], $this->snapshot($cwd));
    }

    public function testDirThatIsAFileExitsWithOne(): void
    {
        $cwd = $this->makeTempDir();
        $this->writeFile($cwd . '/arquivo', 'x');
        $before = $this->snapshot($cwd);

        $result = self::cli(['sync', '--dir=arquivo'], $cwd);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertSame($before, $this->snapshot($cwd));
    }

    public function testNoWritePermissionExitsWithOne(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('Como root, a falta de permissão de escrita não tem efeito.');
        }
        $dir = $this->makeTempDir();
        chmod($dir, 0o555);

        try {
            $result = self::cli(['sync'], $dir);
        } finally {
            chmod($dir, 0o777);
        }

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function helpArguments(): iterable
    {
        yield 'help' => ['help'];
        yield '--help' => ['--help'];
        yield '-h' => ['-h'];
    }

    #[DataProvider('helpArguments')]
    public function testHelpGoesToStdoutAndExitsZero(string $argument): void
    {
        $dir = $this->makeTempDir();

        $result = self::cli([$argument], $dir);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString(Expected::USAGE_LINE, $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertSame([], $this->snapshot($dir));
    }

    public function testNoArgumentsShowsUsageOnStderrAndExitsTwo(): void
    {
        $dir = $this->makeTempDir();

        $result = self::cli([], $dir);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString(Expected::USAGE_LINE, $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame([], $this->snapshot($dir));
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'comando desconhecido' => [['foo']];
        yield 'opção desconhecida' => [['sync', '--bar']];
        yield '--dir sem valor' => [['sync', '--dir']];
        yield '--dir= vazio' => [['sync', '--dir=']];
        yield 'argumento a mais' => [['sync', 'x']];
        yield 'opção desconhecida com --force' => [['sync', '--force', '--bar']];
    }

    /** @param list<string> $args */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsShowUsageAndExitTwo(array $args): void
    {
        $dir = $this->makeTempDir();

        $result = self::cli($args, $dir);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString(Expected::USAGE_LINE, $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame([], $this->snapshot($dir), 'Nada deveria ser escrito');
    }

    public function testMissingAutoloaderExitsWithOne(): void
    {
        $root = $this->makeTempDir();
        $copy = $root . '/a/b/c/bin/elavora-standards';
        $this->writeFile($copy, (string) file_get_contents(self::bin()));
        $work = $this->makeTempDir();

        $result = Process::run([PHP_BINARY, $copy, 'sync'], $work);

        self::assertSame(1, $result['code']);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertSame([], $this->snapshot($work));
    }
}
