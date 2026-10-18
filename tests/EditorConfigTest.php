<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\EditorConfig;
use Elavora\CodeStandards\Exception\MalformedMarkersException;
use Elavora\CodeStandards\Exception\UnmanagedFileException;
use Elavora\CodeStandards\Tests\Support\Expected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditorConfigTest extends TestCase
{
    public function testClassIsFinal(): void
    {
        self::assertTrue((new \ReflectionClass(EditorConfig::class))->isFinal());
    }

    public function testMarkers(): void
    {
        self::assertSame('# >>> elavora/code-standards >>>', EditorConfig::BEGIN_MARKER);
        self::assertSame('# <<< elavora/code-standards <<<', EditorConfig::END_MARKER);
    }

    public function testBaseIsTheDocumentedEditorConfig(): void
    {
        self::assertSame(Expected::BASE, EditorConfig::base());
    }

    public function testBaseIsTheContentOfResourcesEditorconfig(): void
    {
        $file = Expected::repoRoot() . '/resources/editorconfig';
        self::assertFileExists($file);
        self::assertSame((string) file_get_contents($file), EditorConfig::base());
    }

    public function testManagedBlockIsExact(): void
    {
        self::assertSame(Expected::block(), EditorConfig::managedBlock());
    }

    public function testManagedBlockUsesOnlyLfAndEndsWithNewline(): void
    {
        $block = EditorConfig::managedBlock();
        self::assertStringNotContainsString("\r", $block);
        self::assertStringEndsWith(Expected::END_MARKER . "\n", $block);
        self::assertStringStartsWith(Expected::BEGIN_MARKER . "\n", $block);
    }

    /** @return iterable<string, array{0: string|null, 1: bool, 2: string}> */
    public static function mergeExamples(): iterable
    {
        $b = Expected::block();
        $oldBlock = Expected::BEGIN_MARKER . "\nroot = true\n\n[*]\nindent_style = tab\n" . Expected::END_MARKER . "\n";

        yield 'null' => [null, false, $b];
        yield 'só quebras de linha' => ["\n\n", false, $b];
        yield 'texto vazio' => ['', false, $b];
        yield 'espaços, TABs e quebras de linha' => [" \t\n\r\n  \t", false, $b];
        yield 'bloco igual' => [$b, false, $b];
        yield 'bloco seguido de seção própria' => [$b . "\n[*.sql]\nindent_size = 2\n", false, $b . "\n[*.sql]\nindent_size = 2\n"];
        yield 'bloco antigo entre conteúdo do projeto, sem quebra de linha final' => [
            "# projeto\n" . $oldBlock . "[*.sql]\nindent_size = 2",
            false,
            "# projeto\n" . $b . "[*.sql]\nindent_size = 2\n",
        ];
        yield 'bloco com CRLF' => [str_replace("\n", "\r\n", $b), false, $b];
        yield 'bloco com CR' => [str_replace("\n", "\r", $b), false, $b];
        yield 'trechos preservados com CRLF' => [
            "# projeto\r\n" . $oldBlock . "[*.sql]\r\nindent_size = 2\r\n",
            false,
            "# projeto\n" . $b . "[*.sql]\nindent_size = 2\n",
        ];
        yield 'sem marcadores, com force' => [Expected::UNMANAGED, true, $b];
        yield 'marcadores quebrados, com force' => [Expected::malformed(), true, $b];
        yield 'marcadores repetidos, com force' => [$b . $b, true, $b];
        yield 'fim antes do início, com force' => [Expected::END_MARKER . "\n" . Expected::BEGIN_MARKER . "\n", true, $b];
        yield 'null, com force' => [null, true, $b];
        yield 'bloco com seção própria, com force' => [$b . "\n[*.sql]\nindent_size = 2\n", true, $b];
        yield 'marcadores com espaços e TABs no fim da linha' => [
            Expected::BEGIN_MARKER . " \t\nroot = true\n" . Expected::END_MARKER . "\t \n[*.sql]\nindent_size = 2\n",
            false,
            $b . "[*.sql]\nindent_size = 2\n",
        ];
    }

    #[DataProvider('mergeExamples')]
    public function testMerge(?string $current, bool $force, string $expected): void
    {
        self::assertSame($expected, EditorConfig::merge($current, $force));
    }

    public function testForceDefaultsToFalse(): void
    {
        $this->expectException(UnmanagedFileException::class);
        EditorConfig::merge(Expected::UNMANAGED);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unmanagedExamples(): iterable
    {
        yield 'editorconfig escrito à mão' => [Expected::UNMANAGED];
        yield 'cópia antiga da base, sem marcadores' => [Expected::BASE];
        yield 'só um comentário' => ["# projeto\n"];
        yield 'marcadores com espaço no começo da linha não contam' => [
            ' ' . Expected::BEGIN_MARKER . "\nroot = true\n  " . Expected::END_MARKER . "\n",
        ];
    }

    #[DataProvider('unmanagedExamples')]
    public function testMergeWithoutMarkersThrowsUnmanaged(string $current): void
    {
        $this->expectException(UnmanagedFileException::class);
        EditorConfig::merge($current, false);
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedExamples(): iterable
    {
        $b = Expected::block();

        yield 'falta o fim' => [Expected::malformed()];
        yield 'falta o início' => ["root = true\n" . Expected::END_MARKER . "\n"];
        yield 'marcadores repetidos' => [$b . $b];
        yield 'início repetido' => [Expected::BEGIN_MARKER . "\n" . $b];
        yield 'fim repetido' => [$b . Expected::END_MARKER . "\n"];
        yield 'fim antes do início' => [Expected::END_MARKER . "\n" . Expected::BEGIN_MARKER . "\n"];
    }

    #[DataProvider('malformedExamples')]
    public function testMergeWithBrokenMarkersThrowsMalformed(string $current): void
    {
        $this->expectException(MalformedMarkersException::class);
        EditorConfig::merge($current, false);
    }

    public function testMalformedMessageSaysWhatIsWrong(): void
    {
        $b = Expected::block();
        $messages = [];
        foreach (
            [
                'falta o fim' => Expected::malformed(),
                'repetidos' => $b . $b,
                'fim antes do início' => Expected::END_MARKER . "\n" . Expected::BEGIN_MARKER . "\n",
            ] as $case => $current
        ) {
            try {
                EditorConfig::merge($current, false);
                self::fail("MalformedMarkersException esperada no caso: {$case}");
            } catch (MalformedMarkersException $e) {
                self::assertNotSame('', trim($e->getMessage()), "Mensagem vazia no caso: {$case}");
                $messages[$case] = $e->getMessage();
            }
        }

        self::assertCount(3, array_unique($messages), 'Cada problema deveria ter uma mensagem própria');
    }

    public function testMergeDoesNotTouchTheFilesystem(): void
    {
        $cwd = (string) getcwd();
        $before = scandir($cwd);
        EditorConfig::merge(null);
        EditorConfig::merge(Expected::UNMANAGED, true);
        self::assertSame($before, scandir($cwd));
    }
}
