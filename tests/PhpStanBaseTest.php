<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\Process;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A base do PHPStan (resources/phpstan.neon), rodando o vendor/bin/phpstan deste repositório num projeto temporário
 * que inclui a base pelo caminho absoluto (docs/phpstan.md e docs/desenvolvimento.md, "O que precisa ser coberto").
 */
final class PhpStanBaseTest extends TestCase
{
    use TempDirectories;

    private const MSG_JSON_DECODE = 'Calling json_decode() is forbidden, passe JSON_THROW_ON_ERROR em $flags (regra 7).';
    private const MSG_JSON_ENCODE = 'Calling json_encode() is forbidden, passe JSON_THROW_ON_ERROR em $flags (regra 7).';
    private const SHELL_REASON = 'não rode comandos pelo shell; use proc_open() com o comando num array (regra 8).';
    private const SUPERGLOBAL_REASON = 'receba o valor por parâmetro (regra 6).';

    /** Projeto com um arquivo por caso, analisado uma vez para todos os casos. */
    private static ?string $casesDir = null;

    /** @var array<string, list<array{message: string, identifier: string|null}>>|null erros por arquivo */
    private static ?array $casesErrors = null;

    private static ?int $casesExitCode = null;

    /**
     * Linhas da tabela "Exemplos" de docs/phpstan.md e casos de "O que a base confere" e "Chamadas proibidas".
     * Cada corpo vira o corpo de uma função. Mensagens vazias: o caso passa.
     *
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string|null}>}>
     */
    private static function cases(): array
    {
        $shell = static fn(string $function): array => ["Calling {$function}() is forbidden, " . self::SHELL_REASON, 'disallowed.function'];
        $superglobal = static fn(string $name): array => ["Using {$name} is forbidden, " . self::SUPERGLOBAL_REASON, 'disallowed.variable'];

        return [
            'json_decode com JSON_THROW_ON_ERROR' => ['json_decode($texto, true, 512, JSON_THROW_ON_ERROR);', []],
            'json_decode com flags nomeado' => ['json_decode($texto, flags: JSON_THROW_ON_ERROR);', []],
            'json_decode com JSON_THROW_ON_ERROR entre outras flags' => [
                'json_decode($texto, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);',
                [],
            ],
            'json_decode sem flags' => ['json_decode($texto);', [[self::MSG_JSON_DECODE, 'disallowed.function']]],
            'json_decode sem JSON_THROW_ON_ERROR' => [
                'json_decode($texto, true, 512, JSON_BIGINT_AS_STRING);',
                [[self::MSG_JSON_DECODE, 'disallowed.function']],
            ],
            'json_encode com JSON_THROW_ON_ERROR' => ['json_encode($valor, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);', []],
            'json_encode sem flags' => ['json_encode($valor);', [[self::MSG_JSON_ENCODE, 'disallowed.function']]],
            'json_encode sem JSON_THROW_ON_ERROR' => [
                'json_encode($valor, JSON_PRETTY_PRINT);',
                [[self::MSG_JSON_ENCODE, 'disallowed.function']],
            ],
            'eval' => [
                'eval($codigo);',
                [['Calling eval() is forbidden, não execute código escrito num texto (regra 8).', 'disallowed.eval']],
            ],
            'exec' => ['exec($comando);', [$shell('exec')]],
            'shell_exec, system e passthru' => [
                "shell_exec(\$comando);\n    system(\$comando);\n    passthru(\$comando);",
                [$shell('shell_exec'), $shell('system'), $shell('passthru')],
            ],
            'proc_open com o comando num array' => ["proc_open(['php', '-l', \$arquivo], \$descritores, \$pipes);", []],
            'popen' => ["popen(\$comando, 'r');", []],
            'extract' => [
                'extract($dados);',
                [['Calling extract() is forbidden, não crie variáveis a partir de um array (regra 9).', 'disallowed.function']],
            ],
            '$_SERVER' => ["return \$_SERVER['argv'];", [$superglobal('$_SERVER')]],
            '$_ENV e $_GET' => ["return \$_ENV['X'] ?? \$_GET['x'] ?? null;", [$superglobal('$_ENV'), $superglobal('$_GET')]],
            '$GLOBALS' => ["return \$GLOBALS['x'];", [$superglobal('$GLOBALS')]],
            '$_POST' => ["return \$_POST['x'];", [$superglobal('$_POST')]],
            '$_FILES' => ["return \$_FILES['x'];", [$superglobal('$_FILES')]],
            '$_COOKIE' => ["return \$_COOKIE['x'];", [$superglobal('$_COOKIE')]],
            '$_SESSION' => ["return \$_SESSION['x'];", [$superglobal('$_SESSION')]],
            '$_REQUEST' => ["return \$_REQUEST['x'];", [$superglobal('$_REQUEST')]],
            'variável variável' => ['$$nome = 1;', [['Variable variables are not allowed.', null]]],
            'propriedade variável' => ['return $objeto->$propriedade;', [['Variable property access on %CLASSE%.', null]]],
            'crase' => ['return `ls`;', [['Backtick operator is not allowed. Use shell_exec() instead.', null]]],
            'create_function' => ["return create_function('', '');", [['Function create_function not found.', null]]],
            'método mágico com @property-read' => ['return $resumo->total;', []],
            'método mágico sem @property-read' => [
                'return $semDoc->total;',
                [['Access to an undefined property %SEMDOC%::$total.', null]],
            ],
        ];
    }

    private static function fileName(string $case): string
    {
        return 'caso' . substr(md5($case), 0, 10) . '.php';
    }

    private static function suffix(string $case): string
    {
        return substr(md5($case), 0, 10);
    }

    /** Arquivo PHP com uma função cujo corpo é $body, mais as classes que os corpos usam. */
    private static function caseFile(string $case, string $body): string
    {
        $s = self::suffix($case);
        $returns = str_starts_with($body, 'return');

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "final class Caixa{$s}\n{\n    public int \$x = 0;\n}\n\n"
            . "/** @property-read int \$total */\nfinal class Resumo{$s}\n{\n"
            . "    public function __get(string \$nome): int\n    {\n        return 0;\n    }\n}\n\n"
            . "final class SemDoc{$s}\n{\n"
            . "    public function __get(string \$nome): int\n    {\n        return 0;\n    }\n}\n\n"
            . "/**\n * @param array<int, list<string>> \$descritores\n * @param array<string, mixed> \$dados\n */\n"
            . "function caso{$s}(string \$texto, mixed \$valor, string \$codigo, string \$comando, string \$arquivo, "
            . "array \$descritores, array \$dados, string \$nome, Caixa{$s} \$objeto, string \$propriedade, "
            . "Resumo{$s} \$resumo, SemDoc{$s} \$semDoc): " . ($returns ? 'mixed' : 'void') . "\n{\n"
            . "    {$body}\n}\n";
    }

    private static function phpstanBinary(): string
    {
        return Expected::repoRoot() . '/vendor/bin/phpstan';
    }

    private static function basePath(): string
    {
        return Expected::repoRoot() . '/resources/phpstan.neon';
    }

    /** Conteúdo do arquivo do PHPStan de um projeto temporário, que inclui a base pelo caminho absoluto. */
    private static function projectConfig(string $dir, string $extra = ''): string
    {
        return "includes:\n  - " . self::basePath() . "\n\nparameters:\n  tmpDir: {$dir}/.phpstan-tmp\n"
            . "  paths:\n    - code\n" . $extra;
    }

    /**
     * Roda o PHPStan com a configuração de $dir/phpstan.neon e devolve o código de saída e os erros por arquivo
     * (caminho relativo a $dir/code).
     *
     * @return array{code: int, errors: array<string, list<array{message: string, identifier: string|null}>>, output: string}
     */
    private static function analyse(string $dir): array
    {
        self::assertFileExists(self::phpstanBinary(), 'o phpstan/phpstan precisa estar no require-dev deste repositório');
        self::assertFileExists(self::basePath());

        $result = Process::run(
            [PHP_BINARY, self::phpstanBinary(), 'analyse', '--no-progress', '--memory-limit=512M', '--error-format=json',
                '-c', $dir . '/phpstan.neon'],
            $dir,
        );
        $output = $result['stdout'] . $result['stderr'];

        $errors = [];
        $json = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json, $output);
        self::assertSame([], $json['errors'] ?? [], $output);
        foreach ($json['files'] ?? [] as $path => $file) {
            $relative = basename((string) $path);
            foreach ($file['messages'] as $message) {
                $errors[$relative][] = [
                    'message' => (string) $message['message'],
                    'identifier' => isset($message['identifier']) ? (string) $message['identifier'] : null,
                ];
            }
        }

        return ['code' => $result['code'], 'errors' => $errors, 'output' => $output];
    }

    /** @param array<string, string> $code arquivo em code/ => conteúdo */
    private function project(array $code, string $extraConfig = ''): string
    {
        $dir = $this->makeTempDir();
        foreach ($code as $file => $content) {
            $this->writeFile($dir . '/code/' . $file, $content);
        }
        $this->writeFile($dir . '/phpstan.neon', self::projectConfig($dir, $extraConfig));

        return $dir;
    }

    /** @return array<string, list<array{message: string, identifier: string|null}>> */
    private function casesErrors(): array
    {
        if (self::$casesErrors === null) {
            // A pasta dos casos vale para a classe toda: sai da lista do teste e é apagada no tearDownAfterClass.
            $dir = $this->makeTempDir();
            array_pop($this->tempDirectories);
            self::$casesDir = $dir;
            foreach (self::cases() as $case => [$body]) {
                $this->writeFile($dir . '/code/' . self::fileName($case), self::caseFile($case, $body));
            }
            $this->writeFile($dir . '/phpstan.neon', self::projectConfig($dir));
            $result = self::analyse($dir);
            self::$casesErrors = $result['errors'];
            self::$casesExitCode = $result['code'];
        }

        return self::$casesErrors;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$casesDir !== null) {
            self::removeRecursive(self::$casesDir);
        }
        self::$casesDir = null;
        self::$casesErrors = null;
        self::$casesExitCode = null;
    }

    /** @return iterable<string, array{0: string}> */
    public static function caseNames(): iterable
    {
        foreach (array_keys(self::cases()) as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('caseNames')]
    public function testExample(string $case): void
    {
        [$body, $expected] = self::cases()[$case];
        $s = self::suffix($case);
        $actual = $this->casesErrors()[self::fileName($case)] ?? [];
        $context = "{$body}\n" . json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($expected === []) {
            self::assertSame([], $actual, "deveria passar: {$context}");

            return;
        }

        self::assertNotSame([], $actual, "deveria falhar: {$context}");
        $messages = array_column($actual, 'message');
        foreach ($expected as [$message, $identifier]) {
            $message = str_replace(['%CLASSE%', '%SEMDOC%'], ["Caixa{$s}", "SemDoc{$s}"], $message);
            self::assertContains($message, $messages, $context);
            if ($identifier !== null) {
                self::assertContains(
                    ['message' => $message, 'identifier' => $identifier],
                    $actual,
                    "identificador {$identifier} esperado: {$context}",
                );
            }
        }
    }

    public function testRunWithForbiddenCallsExitsWithNonZero(): void
    {
        $this->casesErrors();

        self::assertNotSame(0, self::$casesExitCode);
    }

    public function testRunWithoutErrorsExitsWithZero(): void
    {
        $dir = $this->project([
            'Ok.php' => "<?php\n\ndeclare(strict_types=1);\n\nfunction ok(string \$texto): mixed\n{\n"
                . "    return json_decode(\$texto, true, 512, JSON_THROW_ON_ERROR);\n}\n",
        ]);

        $result = self::analyse($dir);

        self::assertSame([], $result['errors'], $result['output']);
        self::assertSame(0, $result['code'], $result['output']);
    }

    public function testBaseUsesLevelMax(): void
    {
        // Sem tipo no parâmetro, só o level max (9 ou mais) acusa o mixed implícito ao ler um item.
        $dir = $this->project([
            'Mixed.php' => "<?php\n\ndeclare(strict_types=1);\n\nfunction item(mixed \$x): int\n{\n    return \$x['a'];\n}\n",
        ]);

        $result = self::analyse($dir);

        self::assertNotSame(0, $result['code'], $result['output']);
    }

    private const CLI = "<?php\n\ndeclare(strict_types=1);\n\nfunction cli(): mixed\n{\n"
        . "    return [\$GLOBALS['_composer_autoload_path'] ?? null, \$_SERVER['argv']];\n}\n";

    private const OUTRO = "<?php\n\ndeclare(strict_types=1);\n\nfunction outro(): mixed\n{\n    return \$_SERVER['argv'];\n}\n";

    private const EXCEPTION = "  ignoreErrors:\n"
        . "    # O comando recebe do Composer o caminho do autoloader e os argumentos por superglobais.\n"
        . "    -\n"
        . "      message: '#^Using \\\$(GLOBALS|_SERVER) is forbidden, #'\n"
        . "      path: code/cli.php\n"
        . "      count: 2\n";

    public function testExceptionReleasesTheCallOnlyInThatFile(): void
    {
        $dir = $this->project(['cli.php' => self::CLI, 'outro.php' => self::OUTRO], self::EXCEPTION);

        $result = self::analyse($dir);

        self::assertArrayNotHasKey('cli.php', $result['errors'], $result['output']);
        self::assertSame(
            [['message' => 'Using $_SERVER is forbidden, ' . self::SUPERGLOBAL_REASON, 'identifier' => 'disallowed.variable']],
            $result['errors']['outro.php'] ?? [],
            $result['output'],
        );
        self::assertNotSame(0, $result['code']);
    }

    public function testExceptionAloneMakesTheFilePass(): void
    {
        $dir = $this->project(['cli.php' => self::CLI], self::EXCEPTION);

        $result = self::analyse($dir);

        self::assertSame([], $result['errors'], $result['output']);
        self::assertSame(0, $result['code'], $result['output']);
    }

    public function testWithoutTheExceptionTheFileFails(): void
    {
        $dir = $this->project(['cli.php' => self::CLI]);

        $result = self::analyse($dir);

        self::assertCount(2, $result['errors']['cli.php'] ?? [], $result['output']);
        self::assertNotSame(0, $result['code']);
    }

    public function testUnusedExceptionMakesTheAnalysisFail(): void
    {
        $dir = $this->project(['outro.php' => "<?php\n\ndeclare(strict_types=1);\n\nfunction outro(): int\n{\n    return 1;\n}\n",
            'cli.php' => "<?php\n\ndeclare(strict_types=1);\n\nfunction cli(): int\n{\n    return 1;\n}\n"], self::EXCEPTION);

        $result = Process::run(
            [PHP_BINARY, self::phpstanBinary(), 'analyse', '--no-progress', '--memory-limit=512M', '-c', $dir . '/phpstan.neon'],
            $dir,
        );

        self::assertNotSame(0, $result['code'], $result['stdout'] . $result['stderr']);
    }

    public function testProjectEntryAddsAProhibitionButDoesNotReleaseTheBase(): void
    {
        $dir = $this->project(
            ['a.php' => "<?php\n\ndeclare(strict_types=1);\n\nfunction a(string \$c): void\n{\n    var_dump(\$c);\n    exec(\$c);\n}\n"],
            "  disallowedFunctionCalls:\n    -\n      function: 'var_dump()'\n      message: 'proibido no projeto'\n",
        );

        $result = self::analyse($dir);
        $messages = array_column($result['errors']['a.php'] ?? [], 'message');

        self::assertContains('Calling var_dump() is forbidden, proibido no projeto.', $messages, $result['output']);
        self::assertContains('Calling exec() is forbidden, ' . self::SHELL_REASON, $messages, $result['output']);
    }
}
