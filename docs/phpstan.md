# Base do PHPStan

[README](../README.md) · Anterior: [Lint de sintaxe PHP](lint.md) · Próximo: [Integração com o CI](integracao-ci.md)

## Visão geral

A partir da 1.2.0, o pacote traz uma base do PHPStan para os projetos da Elavora. O projeto inclui a base no próprio
arquivo de configuração do PHPStan e passa a ter as mesmas regras de análise que os outros.

A base liga três coisas:

- o nível mais alto do PHPStan (`level: max`);
- as regras extras do `phpstan/phpstan-strict-rules`;
- uma lista de chamadas proibidas, com o `spaze/phpstan-disallowed-calls`.

Ela aplica, até onde uma ferramenta consegue, as regras de código da Elavora (adaptadas do "Power of 10" da NASA).
A tabela de [O que a base confere](#o-que-a-base-confere) mostra o que cada regra cobre e o que fica para a revisão
do PR.

A base não diz quais pastas analisar. Isso continua no arquivo de cada projeto.

## O arquivo distribuído

O arquivo é `resources/phpstan.neon`, dentro do pacote. Num projeto que instalou o pacote, ele fica em
`vendor/elavora/code-standards/resources/phpstan.neon`.

O caminho faz parte do [contrato](integracao-ci.md#o-contrato). Mudar o caminho é uma mudança incompatível (versão
major).

Conteúdo de `resources/phpstan.neon`:

```neon
# Base do PHPStan da Elavora (elavora/code-standards). Veja docs/phpstan.md.
includes:
  - %rootDir%/../phpstan-strict-rules/rules.neon
  - %rootDir%/../../spaze/phpstan-disallowed-calls/extension.neon

parameters:
  level: max

  disallowedFunctionCalls:
    -
      function:
        - 'eval()'
        - 'create_function()'
      message: 'não execute código escrito num texto (regra 8)'
    -
      function:
        - 'exec()'
        - 'shell_exec()'
        - 'system()'
        - 'passthru()'
      message: 'não rode comandos pelo shell; use proc_open() com o comando num array (regra 8)'
    -
      function: 'extract()'
      message: 'não crie variáveis a partir de um array (regra 9)'
    -
      function: 'json_decode()'
      message: 'passe JSON_THROW_ON_ERROR em $flags (regra 7)'
      allowParamFlagsAnywhere:
        -
          position: 4
          name: 'flags'
          value: 4194304 # JSON_THROW_ON_ERROR
    -
      function: 'json_encode()'
      message: 'passe JSON_THROW_ON_ERROR em $flags (regra 7)'
      allowParamFlagsAnywhere:
        -
          position: 2
          name: 'flags'
          value: 4194304 # JSON_THROW_ON_ERROR

  disallowedSuperglobals:
    -
      superglobal:
        - '$GLOBALS'
        - '$_SERVER'
        - '$_GET'
        - '$_POST'
        - '$_FILES'
        - '$_COOKIE'
        - '$_SESSION'
        - '$_REQUEST'
        - '$_ENV'
      message: 'receba o valor por parâmetro (regra 6)'
```

Por que cada parte é assim:

- **`%rootDir%`** é a pasta do `phpstan/phpstan` instalado. As duas extensões ficam ao lado dele, no mesmo
  `vendor/`. Assim, o mesmo arquivo funciona num projeto (com o pacote dentro do `vendor/`) e neste repositório (com o
  arquivo em `resources/`). Um caminho relativo ao próprio arquivo só funcionaria em um dos dois casos.
- **As extensões são incluídas pela base.** O projeto não precisa do `phpstan/extension-installer`. Se ele já usa o
  instalador, veja [Projeto com o `phpstan/extension-installer`](#projeto-com-o-phpstanextension-installer).
- **`4194304`** é o valor de `JSON_THROW_ON_ERROR`. A documentação do `spaze/phpstan-disallowed-calls` recomenda o
  número, e não o nome da constante, porque o nome pode quebrar quando o PHPStan atualiza uma dependência interna.
- **A lista de chamadas é da Elavora.** A base não inclui os arquivos prontos do `spaze/phpstan-disallowed-calls`
  (como `disallowed-dangerous-calls.neon`). Eles proíbem mais coisas, como `var_dump()` e `putenv()`, que não
  foram combinadas.
- **Não há `paths`, `tmpDir` nem `ignoreErrors`.** Isso é de cada projeto.

## Uso num projeto

### 1. Instalar as dependências

As três ferramentas vão no `require-dev` do **projeto**, junto com o pacote:

```sh
composer require --dev elavora/code-standards:^1.2 phpstan/phpstan:^2.2 phpstan/phpstan-strict-rules:^2.0 spaze/phpstan-disallowed-calls:^4.0
```

| Pacote | Restrição | Por que essa versão mínima |
| --- | --- | --- |
| `phpstan/phpstan` | `^2.2` | a mesma que os projetos da Elavora já usam |
| `phpstan/phpstan-strict-rules` | `^2.0` | a primeira versão para o PHPStan 2 |
| `spaze/phpstan-disallowed-calls` | `^4.0` | a primeira versão para o PHPStan 2 |

A base foi conferida com as versões mínimas (2.2.0, 2.0.0 e 4.0.0) e com as mais novas de hoje (2.3.1, 2.1.0 e
4.15.0). O CI de qualidade instala as versões mínimas, então as duas pontas importam.

O pacote **não** traz essas ferramentas no `require`. Assim, um projeto que só usa a formatação e o lint não ganha
o PHPStan sem pedir. Para impedir uma versão antiga demais, o `composer.json` do pacote declara um `conflict`:

```json
"conflict": {
    "phpstan/phpstan": "<2.2",
    "phpstan/phpstan-strict-rules": "<2.0",
    "spaze/phpstan-disallowed-calls": "<4.0"
}
```

O `conflict` só vale se o projeto tiver o pacote instalado. Um projeto sem PHPStan não é afetado. Um projeto com
`phpstan/phpstan` 2.1, por exemplo, não consegue instalar o `elavora/code-standards` 1.2 até atualizar o PHPStan.

### 2. Incluir a base

No arquivo de configuração do PHPStan do projeto (`phpstan.neon`, `phpstan.neon.dist` ou `phpstan.dist.neon`, o
que o projeto já usa), a base entra em `includes`:

```neon
includes:
  - vendor/elavora/code-standards/resources/phpstan.neon

parameters:
  paths:
    - src
```

O caminho é relativo ao arquivo do projeto, que fica na raiz.

O projeto pode acrescentar parâmetros próprios, como `paths`, `excludePaths`, `tmpDir` e `bootstrapFiles`. Ele **não**
pode:

- baixar o nível (`level`);
- desligar regras do `phpstan-strict-rules` (o bloco `strictRules`);
- usar um baseline (`phpstan-baseline.neon`);
- acrescentar `ignoreErrors` fora das regras de [Exceções num projeto](#exceções-num-projeto).

O PHPStan junta as listas dos arquivos incluídos. Uma entrada nova em `disallowedFunctionCalls` no projeto
**acrescenta** uma proibição, mas não libera uma da base. Para liberar, veja [Exceções num projeto](#exceções-num-projeto).

### 3. Ligar o script `analyse`

O CI de qualidade do `Elavora/.github` roda `composer analyse` quando o script existe. O script recomendado é:

```json
"scripts": {
    "analyse": "phpstan analyse --no-progress --memory-limit=512M"
}
```

`composer analyse` sai com 0 quando o PHPStan não acha nenhum erro. Com qualquer erro, ele sai com um código
diferente de 0, e o CI falha. Não existe aviso que passa: zero erros é a única forma de passar (regra 10).

### Projeto com o `phpstan/extension-installer`

O instalador inclui as extensões sozinho. Com a base, elas seriam incluídas duas vezes. O projeto que usa o
instalador desliga a inclusão automática das duas no `composer.json`:

```json
"extra": {
    "phpstan/extension-installer": {
        "ignore": ["phpstan/phpstan-strict-rules", "spaze/phpstan-disallowed-calls"]
    }
}
```

Nenhum projeto da Elavora usa o instalador hoje. Este caso não é testado neste repositório.

## O que a base confere

| Regra | O que a base faz | O que fica para a revisão do PR |
| --- | --- | --- |
| 6. Escopo mínimo | proíbe as superglobais; o `strict-rules` proíbe reaproveitar uma variável no `foreach` e criar um array com `$x[] =` sem declarar `$x` | variáveis declaradas longe de onde são usadas |
| 7. Conferir retornos | o `level: max` obriga a tratar retornos como `string\|false` antes de usar; `json_decode()` e `json_encode()` precisam de `JSON_THROW_ON_ERROR`; o `strict-rules` exige booleanos em `if` e `while` | o retorno ignorado de uma função com efeito (por exemplo, `fopen($f, 'r');` sozinho na linha não é acusado) |
| 8. Sem `eval` e sem nomes montados em texto | proíbe `eval()`, `create_function()`, `exec()`, `shell_exec()`, `system()` e `passthru()`; o `strict-rules` proíbe `$obj->$metodo()` e `$obj->$propriedade` | `new $classe()`, `$classe::metodo()` e `$funcao()` com um tipo `class-string` ou `callable-string` |
| 9. Sem `$$x` e sem callables em texto | o `strict-rules` proíbe `$$x`; a base proíbe `extract()` | callables em texto, como `array_map('trim', $lista)` e `call_user_func('time')` |
| 10. Zero avisos | `level: max`, sem baseline; qualquer erro faz o `composer analyse` falhar | |

As regras 1 a 5 e o tamanho das funções não são conferidos pela base. Não há limite de linhas por função.

**Métodos mágicos são permitidos.** A base não proíbe `__get()`, `__set()`, `__call()` e os outros métodos mágicos,
porque eles são aceitos em objetos pequenos. No `level: max`, o PHPStan acusa uma propriedade ou um método que não
existe na classe. Por isso, a classe declara o que os métodos mágicos expõem, com `@property`, `@property-read` ou
`@method` no PHPDoc:

```php
/** @property-read int $total */
final class Resumo
{
    public function __get(string $nome): int
    {
        return 0;
    }
}
```

Sem o `@property-read`, `$resumo->total` dá o erro `Access to an undefined property Resumo::$total.`.

## Chamadas proibidas

Cada chamada proibida gera um erro com o texto
`Calling <função>() is forbidden, <motivo>.` (ou `Using <superglobal> is forbidden, <motivo>.`). O começo do texto é
do `spaze/phpstan-disallowed-calls`, em inglês. O motivo vem da base, em português.

| Proibido | Motivo na mensagem | Identificador do erro |
| --- | --- | --- |
| `eval()`, `create_function()` | `não execute código escrito num texto (regra 8)` | `disallowed.eval` para o `eval`, `disallowed.function` para o resto |
| `exec()`, `shell_exec()`, `system()`, `passthru()` | `não rode comandos pelo shell; use proc_open() com o comando num array (regra 8)` | `disallowed.function` |
| `extract()` | `não crie variáveis a partir de um array (regra 9)` | `disallowed.function` |
| `json_decode()` sem `JSON_THROW_ON_ERROR` em `$flags` (4º parâmetro) | `passe JSON_THROW_ON_ERROR em $flags (regra 7)` | `disallowed.function` |
| `json_encode()` sem `JSON_THROW_ON_ERROR` em `$flags` (2º parâmetro) | `passe JSON_THROW_ON_ERROR em $flags (regra 7)` | `disallowed.function` |
| `$GLOBALS`, `$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, `$_SESSION`, `$_REQUEST`, `$_ENV` | `receba o valor por parâmetro (regra 6)` | `disallowed.variable` |

Não são proibidos:

- **`proc_open()` e `popen()`.** O lint do pacote usa `proc_open()` com o comando num array, que não passa pelo
  shell. É a saída recomendada quando um projeto precisa rodar outro programa.
- **`$argv` e `$argc`.** Não são superglobais: só existem no escopo global do script.
- **Os métodos mágicos.** Veja [O que a base confere](#o-que-a-base-confere).

O operador de crase (`` `ls` ``) também é proibido, pelo `strict-rules`, com a mensagem
`Backtick operator is not allowed. Use shell_exec() instead.`. Como o `shell_exec()` também é proibido, a crase não
tem saída: o código usa `proc_open()`.

O `create_function()` não existe no PHP 8. Nele, o PHPStan acusa `Function create_function not found.` antes da
regra da base. A entrada continua na base para deixar a proibição escrita.

### Exemplos

Cada linha é o corpo de uma função num arquivo analisado com a base.

| Código | Resultado |
| --- | --- |
| `json_decode($texto, true, 512, JSON_THROW_ON_ERROR);` | passa |
| `json_decode($texto, flags: JSON_THROW_ON_ERROR);` | passa |
| `json_decode($texto, true, 512, JSON_BIGINT_AS_STRING \| JSON_THROW_ON_ERROR);` | passa (o bit de `JSON_THROW_ON_ERROR` está ligado) |
| `json_decode($texto);` | falha: `Calling json_decode() is forbidden, passe JSON_THROW_ON_ERROR em $flags (regra 7).` |
| `json_decode($texto, true, 512, JSON_BIGINT_AS_STRING);` | falha, com a mesma mensagem |
| `json_encode($valor, JSON_PRETTY_PRINT \| JSON_THROW_ON_ERROR);` | passa |
| `json_encode($valor);` | falha: `Calling json_encode() is forbidden, passe JSON_THROW_ON_ERROR em $flags (regra 7).` |
| `json_encode($valor, JSON_PRETTY_PRINT);` | falha, com a mesma mensagem |
| `eval($codigo);` | falha: `Calling eval() is forbidden, não execute código escrito num texto (regra 8).` |
| `exec($comando);` | falha: `Calling exec() is forbidden, não rode comandos pelo shell; use proc_open() com o comando num array (regra 8).` |
| `shell_exec($comando);`, `system($comando);`, `passthru($comando);` | falha, uma mensagem para cada, no mesmo formato do `exec()` |
| `proc_open(['php', '-l', $arquivo], $descritores, $pipes);` | passa (não é proibido) |
| `extract($dados);` | falha: `Calling extract() is forbidden, não crie variáveis a partir de um array (regra 9).` |
| `return $_SERVER['argv'];` | falha: `Using $_SERVER is forbidden, receba o valor por parâmetro (regra 6).` |
| `return $_ENV['X'] ?? $_GET['x'] ?? null;` | falha duas vezes: uma para `$_ENV` e uma para `$_GET` |
| `$$nome = 1;` | falha: `Variable variables are not allowed.` (do `strict-rules`) |
| `return $objeto->$propriedade;` | falha: `Variable property access on <Classe>.` (do `strict-rules`) |
| `` return `ls`; `` | falha: `Backtick operator is not allowed. Use shell_exec() instead.` (do `strict-rules`) |

## Exceções num projeto

Às vezes um projeto precisa mesmo de uma chamada proibida. O caso típico é um script de linha de comando que lê
`$_SERVER['argv']`. A exceção fica no arquivo do PHPStan do projeto, em `ignoreErrors`, e segue três regras:

1. **Uma entrada por exceção, presa a um arquivo.** A entrada tem `message` (uma expressão regular com o começo da
   mensagem), `path` (o arquivo) e `count` (quantas vezes o erro aparece nele).
2. **Um comentário logo acima diz o porquê.** O comentário é a lista de permitidos do projeto: quem lê o arquivo do
   PHPStan vê todas as exceções e os motivos.
3. **Sem baseline e sem `@phpstan-ignore` para chamadas proibidas.** A exceção fica sempre no arquivo do PHPStan, e
   não espalhada no código.

Exemplo:

```neon
parameters:
  ignoreErrors:
    # O comando recebe do Composer o caminho do autoloader e os argumentos por superglobais.
    -
      message: '#^Using \$(GLOBALS|_SERVER) is forbidden, #'
      path: bin/elavora-standards
      count: 2
```

O PHPStan avisa quando uma exceção não é mais usada (`reportUnmatchedIgnoredErrors`, ligado por padrão). Assim, uma
exceção que sobrou depois de uma refatoração faz o `composer analyse` falhar até ser apagada.

**Outros erros do PHPStan.** Um erro que não é de chamada proibida se corrige no código. Quando o erro vem de uma
definição errada do próprio PHPStan (um falso positivo), vale um `@phpstan-ignore` na linha, com o identificador e o
motivo entre parênteses:

```php
// @phpstan-ignore argument.type (o PHPStan não conhece o descritor ['redirect', 1] do proc_open)
$processo = proc_open($comando, $descritores, $pipes);
```

## Referência

| Item | Valor |
| --- | --- |
| Arquivo | `resources/phpstan.neon` no pacote; `vendor/elavora/code-standards/resources/phpstan.neon` no projeto |
| Inclusão no projeto | `includes: [vendor/elavora/code-standards/resources/phpstan.neon]` no arquivo do PHPStan do projeto |
| Dependências (no `require-dev` do projeto) | `phpstan/phpstan` `^2.2`, `phpstan/phpstan-strict-rules` `^2.0`, `spaze/phpstan-disallowed-calls` `^4.0` |
| `conflict` do pacote | `phpstan/phpstan` `<2.2`, `phpstan/phpstan-strict-rules` `<2.0`, `spaze/phpstan-disallowed-calls` `<4.0` |
| Script do projeto | `"analyse": "phpstan analyse --no-progress --memory-limit=512M"` |
| Código de saída | 0 sem erros; diferente de 0 com qualquer erro ou com a configuração inválida |
| Versão do pacote | a partir da 1.2.0 |

---

[README](../README.md) · Anterior: [Lint de sintaxe PHP](lint.md) · Próximo: [Integração com o CI](integracao-ci.md)
