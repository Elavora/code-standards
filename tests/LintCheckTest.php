<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Lint;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\TestCase;

/**
 * Lint::check(): confere um arquivo com php -l (docs/lint.md, "Como cada arquivo é conferido").
 */
final class LintCheckTest extends TestCase
{
    use TempDirectories;

    public function testValidFileReturnsNull(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/src/A.php', Expected::PHP_VALID);

        self::assertNull(Lint::check($dir, 'src/A.php'));
    }

    public function testSyntaxErrorReturnsTheMessage(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/src/Quebrado.php', Expected::PHP_BROKEN);

        $message = Lint::check($dir, 'src/Quebrado.php');

        self::assertIsString($message);
        self::assertStringContainsString('src/Quebrado.php', $message);
        self::assertMatchesRegularExpression('/on line \d+/', $message);
        self::assertStringNotContainsString($dir, $message, 'A mensagem traz o caminho mostrado, e não o absoluto');
        self::assertStringNotContainsString('Errors parsing ', $message);
        self::assertStringNotContainsString("\r", $message);
        self::assertStringEndsNotWith("\n", $message);
        foreach (explode("\n", $message) as $line) {
            self::assertNotSame('', trim($line), 'A mensagem não tem linhas vazias');
            self::assertStringStartsNotWith('  ', $line, 'Os dois espaços são da saída do comando, não da mensagem');
        }
    }

    public function testErrorMessageAppearsOnlyOnce(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/src/Quebrado.php', Expected::PHP_BROKEN);

        $message = Lint::check($dir, 'src/Quebrado.php');
        self::assertIsString($message);

        // Sem display_errors=1 e log_errors=0, a imagem escreveria o erro duas vezes; a linha "Errors parsing ..." sai.
        self::assertSame(1, substr_count($message, 'src/Quebrado.php'), $message);
    }

    public function testCompileTimeDeprecationIsNotAnError(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/src/Antigo.php', Expected::PHP_DEPRECATED);

        self::assertNull(Lint::check($dir, 'src/Antigo.php'));
    }

    public function testFileIsResolvedFromTheProjectFolderAndNotFromTheCurrentDirectory(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/A.php', Expected::PHP_BROKEN);
        $other = $this->makeTempDir();
        $this->writeFile($other . '/A.php', Expected::PHP_VALID);
        $cwd = (string) getcwd();

        chdir($other);
        try {
            $message = Lint::check($dir, 'A.php');
        } finally {
            chdir($cwd);
        }

        self::assertIsString($message);
        self::assertStringContainsString('A.php', $message);
    }

    /** O php -l roda sem shell: espaços e caracteres especiais do shell no nome chegam ao PHP como estão. */
    public function testFileNameWithShellCharactersIsPassedAsIs(): void
    {
        $dir = $this->makeTempDir();
        $broken = 'src/Com espaço $(echo x) `y` ;&.php';
        $valid = "src/Válido 'aspas' \"duplas\".php";
        $this->writeFile($dir . '/' . $broken, Expected::PHP_BROKEN);
        $this->writeFile($dir . '/' . $valid, Expected::PHP_VALID);

        $message = Lint::check($dir, $broken);

        self::assertIsString($message);
        self::assertStringContainsString($broken, $message);
        self::assertNull(Lint::check($dir, $valid));
    }

    public function testCheckWritesNothing(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/A.php', Expected::PHP_VALID);
        $this->writeFile($dir . '/B.php', Expected::PHP_BROKEN);
        $before = $this->snapshot($dir);

        Lint::check($dir, 'A.php');
        Lint::check($dir, 'B.php');

        self::assertSame($before, $this->snapshot($dir));
    }
}
