<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\EditorConfig;
use Elavora\CodeStandards\Tests\Support\Doc;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Os arquivos do repositório conferem com o que docs/desenvolvimento.md especifica.
 */
final class RepositoryFilesTest extends TestCase
{
    private static function read(string $relative): string
    {
        $path = Expected::repoRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function filesSpecifiedInTheDocs(): iterable
    {
        yield '.gitattributes' => ['.gitattributes', 'desenvolvimento.md', 'A primeira linha força LF'];
        yield '.gitignore' => ['.gitignore', 'desenvolvimento.md', '`.gitignore`:'];
        yield '.php-cs-fixer.dist.php' => [
            '.php-cs-fixer.dist.php',
            'desenvolvimento.md',
            '### Configuração do PHP-CS-Fixer deste repositório',
        ];
        yield 'test.yml' => ['.github/workflows/test.yml', 'desenvolvimento.md', 'Arquivo `.github/workflows/test.yml`'];
        yield 'release.yml' => [
            '.github/workflows/release.yml',
            'desenvolvimento.md',
            'Arquivo `.github/workflows/release.yml`',
        ];
        yield 'sync-labels.yml' => [
            '.github/workflows/sync-labels.yml',
            'desenvolvimento.md',
            'Arquivo `.github/workflows/sync-labels.yml`',
        ];
        yield 'dependabot.yml' => ['.github/dependabot.yml', 'desenvolvimento.md', 'Arquivo `.github/dependabot.yml`'];
        yield 'resources/editorconfig' => ['resources/editorconfig', 'editorconfig.md', 'Conteúdo de `resources/editorconfig`'];
        yield 'resources/phpstan.neon' => ['resources/phpstan.neon', 'phpstan.md', 'Conteúdo de `resources/phpstan.neon`'];
        yield 'phpstan.neon' => ['phpstan.neon', 'desenvolvimento.md', 'Arquivo `phpstan.neon` deste repositório'];
        yield 'phpstan-bootstrap.php' => ['phpstan-bootstrap.php', 'desenvolvimento.md', 'Arquivo `phpstan-bootstrap.php`'];
    }

    public function testComposerAnalysePassesInThisRepository(): void
    {
        $result = Process::run(['composer', 'analyse', '--no-ansi', '--no-interaction'], Expected::repoRoot());

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
    }

    public function testConflictOnlyHasLowerBoundsForThePhpStanTools(): void
    {
        $composer = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['phpstan/phpstan' => '<2.2', 'phpstan/phpstan-strict-rules' => '<2.0', 'spaze/phpstan-disallowed-calls' => '<4.0'],
            $composer['conflict'] ?? null,
        );
        self::assertSame('^2.2', $composer['require-dev']['phpstan/phpstan'] ?? null);
        self::assertSame('^2.0', $composer['require-dev']['phpstan/phpstan-strict-rules'] ?? null);
        self::assertSame('^4.0', $composer['require-dev']['spaze/phpstan-disallowed-calls'] ?? null);
        self::assertSame('phpstan analyse --no-progress --memory-limit=512M', $composer['scripts']['analyse'] ?? null);
    }

    public function testCiRunsComposerAnalyseRightAfterLint(): void
    {
        self::assertStringContainsString(
            "              composer lint\n              composer analyse\n              composer test\n",
            self::read('.github/workflows/test.yml'),
        );
    }

    public function testPhpStanFilesOfThisRepositoryAreExportIgnored(): void
    {
        $attributes = self::read('.gitattributes');

        self::assertStringContainsString("/phpstan-bootstrap.php export-ignore\n", $attributes);
        self::assertStringContainsString("/phpstan.neon export-ignore\n", $attributes);
        self::assertDoesNotMatchRegularExpression('#^/resources(/|\s).*export-ignore#m', $attributes);
    }

    #[DataProvider('filesSpecifiedInTheDocs')]
    public function testFileMatchesTheDocumentation(string $file, string $doc, string $marker): void
    {
        self::assertSame(Doc::codeBlockAfter($doc, $marker), self::read($file));
    }

    public function testResourcesEditorconfigIsTheExpectedBase(): void
    {
        self::assertSame(Expected::BASE, self::read('resources/editorconfig'));
    }

    public function testComposerJsonMatchesTheDocumentation(): void
    {
        $expected = json_decode(Doc::codeBlockAfter('desenvolvimento.md', '### `composer.json`'), true, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($expected, $actual);
    }

    public function testComposerJsonRequiresOnlyPhp(): void
    {
        $composer = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['php' => '>=8.3'], $composer['require']);
        self::assertArrayNotHasKey('friendsofphp/php-cs-fixer', $composer['require-dev'] ?? []);
        self::assertArrayNotHasKey('composer/composer', $composer['require-dev'] ?? []);
    }

    public function testComposerValidateStrictPasses(): void
    {
        $result = Process::run(['composer', 'validate', '--strict', '--no-check-lock', '--no-ansi'], Expected::repoRoot());

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
    }

    public function testPackageFilesAreNeverExportIgnored(): void
    {
        $attributes = self::read('.gitattributes');

        foreach (['/src', '/resources', '/bin', '/composer.json', '/README.md', '/LICENSE'] as $path) {
            self::assertDoesNotMatchRegularExpression(
                '#^' . preg_quote($path, '#') . '(/|\s).*export-ignore#m',
                $attributes,
                "{$path} não pode ter export-ignore",
            );
        }
        self::assertStringStartsWith("* text=auto eol=lf\n", $attributes);
    }

    public function testLicenseIsMit(): void
    {
        $license = self::read('LICENSE');

        self::assertStringStartsWith('MIT License', $license);
        self::assertStringContainsString('Copyright (c) 2026 Elavora', $license);
        self::assertStringContainsString('Permission is hereby granted, free of charge', $license);
    }

    public function testOwnEditorconfigIsTheOneSyncGenerates(): void
    {
        $current = self::read('.editorconfig');

        self::assertStringContainsString(Expected::block(), $current);
        self::assertSame($current, EditorConfig::merge($current));
    }

    public function testDocumentedStubIsTheSyncStub(): void
    {
        self::assertSame(Expected::STUB, Doc::codeBlockAfter('php-cs-fixer.md', '## Visão geral'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function packageFiles(): iterable
    {
        yield 'src/PhpCsFixer.php' => ['src/PhpCsFixer.php'];
        yield 'src/EditorConfig.php' => ['src/EditorConfig.php'];
        yield 'src/Sync.php' => ['src/Sync.php'];
        yield 'src/Status.php' => ['src/Status.php'];
        yield 'src/Composer.php' => ['src/Composer.php'];
        yield 'src/Lint.php' => ['src/Lint.php'];
        yield 'resources/editorconfig' => ['resources/editorconfig'];
        yield 'resources/phpstan.neon' => ['resources/phpstan.neon'];
        yield 'bin/elavora-standards' => ['bin/elavora-standards'];
    }

    #[DataProvider('packageFiles')]
    public function testPackageFileExists(string $file): void
    {
        self::assertFileExists(Expected::repoRoot() . '/' . $file);
    }

    public function testNoEditorconfigCheckerConfigIsShipped(): void
    {
        self::assertFileDoesNotExist(Expected::repoRoot() . '/.editorconfig-checker.json');
        self::assertFileDoesNotExist(Expected::repoRoot() . '/resources/.editorconfig-checker.json');
    }
}
