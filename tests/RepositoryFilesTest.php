<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\EditorConfig;
use Elavora\CodeStandards\Tests\Support\DependabotRefs;
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

    /**
     * Nos workflows, as referências `@<SHA> # <versão>` das linhas `uses:` são conferidas só pelo formato
     * (docs/desenvolvimento.md, "Arquivos do repositório e o Dependabot"); o resto é comparado byte a byte.
     */
    #[DataProvider('filesSpecifiedInTheDocs')]
    public function testFileMatchesTheDocumentation(string $file, string $doc, string $marker): void
    {
        self::assertSame(
            DependabotRefs::normalizeUses(Doc::codeBlockAfter($doc, $marker)),
            DependabotRefs::normalizeUses(self::read($file)),
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function workflowsSpecifiedInTheDocs(): iterable
    {
        yield 'test.yml' => ['.github/workflows/test.yml', 'Arquivo `.github/workflows/test.yml`'];
        yield 'release.yml' => ['.github/workflows/release.yml', 'Arquivo `.github/workflows/release.yml`'];
        yield 'sync-labels.yml' => ['.github/workflows/sync-labels.yml', 'Arquivo `.github/workflows/sync-labels.yml`'];
    }

    #[DataProvider('workflowsSpecifiedInTheDocs')]
    public function testEveryUsesLineHasShaAndVersionFormat(string $file, string $marker): void
    {
        foreach (['arquivo' => self::read($file), 'doc' => Doc::codeBlockAfter('desenvolvimento.md', $marker)] as $source => $text) {
            preg_match_all('/^\s*(?:-\s+)?uses:.*$/m', $text, $matches);
            self::assertNotEmpty($matches[0], "{$file} ({$source}) sem linha uses:");
            foreach ($matches[0] as $line) {
                self::assertStringEndsWith(
                    DependabotRefs::PLACEHOLDER,
                    DependabotRefs::normalizeUses($line),
                    "{$file} ({$source}): referência fora do formato @<SHA de 40> # <versão>: {$line}",
                );
            }
        }
    }

    public function testImagesAreComparedByValue(): void
    {
        $workflow = self::read('.github/workflows/test.yml');

        self::assertStringContainsString('ghcr.io/elavora/php-dev:8.3-latest', $workflow);
        self::assertStringNotContainsString('8.3-0.', $workflow);
        self::assertStringContainsString('rhysd/actionlint:1.7.12', $workflow);
    }

    /**
     * Tabela de exemplos de "Arquivos do repositório e o Dependabot", partindo do bloco da doc com
     * `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1`.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function usesExamples(): iterable
    {
        $doc = "      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\n"
            . "        with:\n          persist-credentials: false\n";
        $reusable = "    uses: Elavora/.github/.github/workflows/release.yml@a24df9fa1816423221a4ad0aaf6a416da2087c41 # 1.1.0\n";
        $replace = static fn(string $to): string => str_replace(
            'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1',
            $to,
            $doc,
        );

        yield 'SHA e versão novos' => [$doc, $replace('actions/checkout@08eba0b27e820071cde6df949e0beb9ba4906955 # v7.1.0'), true];
        yield 'workflow reutilizável em 1.3.0' => [
            $reusable,
            "    uses: Elavora/.github/.github/workflows/release.yml@0123456789abcdef0123456789abcdef01234567 # 1.3.0\n",
            true,
        ];
        yield 'tag no lugar do SHA' => [$doc, $replace('actions/checkout@v7'), false];
        yield 'SHA curto' => [$doc, $replace('actions/checkout@3d3c42e # v7.0.1'), false];
        yield 'SHA em maiúsculas' => [$doc, $replace('actions/checkout@3D3C42E5AAC5BA805825DA76410C181273BA90B1 # v7.0.1'), false];
        yield 'sem comentário de versão' => [$doc, $replace('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1'), false];
        yield 'comentário não é versão' => [$doc, $replace('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # main'), false];
        yield 'outra action' => [$doc, $replace('actions/setup-node@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1'), false];
        yield 'fora das referências' => [$doc, str_replace('persist-credentials: false', 'persist-credentials: true', $doc), false];
    }

    #[DataProvider('usesExamples')]
    public function testUsesExamplesFromTheDocumentation(string $doc, string $file, bool $passes): void
    {
        self::assertSame($passes, DependabotRefs::normalizeUses($doc) === DependabotRefs::normalizeUses($file));
    }

    /** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: bool}> */
    public static function composerExamples(): iterable
    {
        yield 'mesma restrição' => [static fn(array $c): array => $c, true];
        yield 'phpunit em ^13.0' => [static function (array $c): array {
            $c['require-dev']['phpunit/phpunit'] = '^13.0';

            return $c;
        }, true];
        yield 'restrição vazia' => [static function (array $c): array {
            $c['require-dev']['phpunit/phpunit'] = '';

            return $c;
        }, false];
        yield 'dependência a mais' => [static function (array $c): array {
            $c['require-dev']['vendor/extra'] = '^1.0';

            return $c;
        }, false];
        yield 'dependência a menos' => [static function (array $c): array {
            $c['require-dev'] = [];

            return $c;
        }, false];
        yield 'php >=8.4 no require' => [static function (array $c): array {
            $c['require']['php'] = '>=8.4';

            return $c;
        }, false];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    #[DataProvider('composerExamples')]
    public function testComposerExamplesFromTheDocumentation(callable $change, bool $passes): void
    {
        $expected = json_decode(Doc::codeBlockAfter('desenvolvimento.md', '### `composer.json`'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^12.0', $expected['require-dev']['phpunit/phpunit']);

        self::assertSame($passes, DependabotRefs::composerMatches($expected, $change($expected)));
    }

    public function testResourcesEditorconfigIsTheExpectedBase(): void
    {
        self::assertSame(Expected::BASE, self::read('resources/editorconfig'));
    }

    public function testComposerJsonMatchesTheDocumentation(): void
    {
        $expected = json_decode(Doc::codeBlockAfter('desenvolvimento.md', '### `composer.json`'), true, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(
            DependabotRefs::composerMatches($expected, $actual),
            'composer.json difere da doc (o require-dev só pode mudar a restrição de cada dependência)',
        );
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
