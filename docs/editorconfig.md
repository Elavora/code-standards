# EditorConfig e o comando `sync`

[README](../README.md) · Anterior: [Configuração do PHP-CS-Fixer](php-cs-fixer.md) · Próximo: [Lint de sintaxe PHP](lint.md)

## Visão geral

O [EditorConfig](https://editorconfig.org) define regras simples de formatação para todos os tipos de arquivo:
indentação, fim de linha, codificação, espaços no fim da linha e quebra de linha no final do arquivo. O editor
aplica essas regras enquanto se edita, e o `editorconfig-checker` confere os arquivos no CI.

O formato tem uma limitação: um `.editorconfig` não consegue incluir outro arquivo. Por isso o pacote não consegue
ser "lido" de dentro do `vendor/` como acontece com a configuração do PHP-CS-Fixer. A solução é **copiar** a base
para a raiz do projeto, e manter essa cópia em dia automaticamente:

- a base fica no pacote, em `resources/editorconfig`;
- a cópia na raiz do projeto fica entre duas linhas marcadoras, o **bloco gerado**;
- a cada `composer install`/`update`, a sincronização reescreve só o bloco gerado. O que o projeto escreveu fora do
  bloco fica intacto.

Exemplo de `.editorconfig` de um projeto, com uma seção própria depois do bloco:

```ini
# >>> elavora/code-standards >>>
# Bloco gerado pelo elavora/code-standards. Não edite: ele é reescrito a cada composer install e composer update.
# Seções próprias do projeto vão depois da linha "# <<< elavora/code-standards <<<".
root = true

[*]
charset = utf-8
...
# <<< elavora/code-standards <<<

[*.sql]
indent_size = 2
```

## O `.editorconfig` base

Conteúdo de `resources/editorconfig`, exatamente (fim de linha LF, com quebra de linha no final):

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_style = space
indent_size = 4
insert_final_newline = true
trim_trailing_whitespace = true

[*.{yml,yaml,json,neon}]
indent_size = 2

[*.md]
indent_size = unset
trim_trailing_whitespace = false

[Makefile]
indent_style = tab
```

- **`root = true`:** o editor para de procurar `.editorconfig` nas pastas acima do projeto.
- **PHP e o resto com 4 espaços**, como o PER-CS.
- **YAML, JSON e NEON com 2 espaços.** Um JSON indentado com 4 espaços (como o `composer.json` que o próprio Composer
  escreve) continua válido no `editorconfig-checker`, porque 4 é múltiplo de 2.
- **Markdown:** o tamanho da indentação não é conferido (listas numeradas usam 3 espaços) e os espaços no fim da
  linha são permitidos (dois espaços no fim são uma quebra de linha forçada).
- **`Makefile` com TAB**, que o `make` exige.

O arquivo fica sem o ponto no nome (`editorconfig`, e não `.editorconfig`) para não valer como configuração da pasta
`resources/`.

### Seções próprias do projeto

Um projeto que precisa de regras a mais escreve as seções **depois** da linha `# <<< elavora/code-standards <<<`. No
EditorConfig, a última seção que casa com o arquivo vence, então uma seção do projeto depois do bloco também pode
mudar uma regra da base para alguns arquivos:

```ini
# <<< elavora/code-standards <<<

[tests/fixtures/**]
trim_trailing_whitespace = false
```

Antes da linha `# >>> elavora/code-standards >>>` só cabem comentários e linhas vazias: o `root = true`, dentro do
bloco, precisa vir antes de qualquer seção.

### Por que o pacote não traz `.editorconfig-checker.json`

O `editorconfig-checker` 4 lê opções de um `.editorconfig-checker.json` na raiz (pastas a ignorar, verificações a
desligar). A versão 1.0.0 do pacote **não** traz esse arquivo:

- o CI confere só os arquivos versionados (`git ls-files -z | xargs -0 editorconfig-checker`), então `vendor/` e os
  arquivos gerados já ficam de fora;
- os padrões do próprio `editorconfig-checker` já ignoram os arquivos de lock (`composer.lock`) e os binários
  comuns;
- o que sobra para ignorar (fixtures de teste com formatação proposital, por exemplo) é diferente em cada projeto. O
  formato JSON também não tem `include`, então um arquivo copiado pelo pacote impediria esses ajustes.

Um projeto que precise do arquivo cria o seu, à mão. Se aparecer uma necessidade comum a todos os projetos, ele
entra no pacote numa versão minor.

## A sincronização

A mesma lógica roda de duas formas:

| Forma | Quem usa | Diferença |
| --- | --- | --- |
| `Elavora\CodeStandards\Composer::sync`, no `post-install-cmd` e no `post-update-cmd` | o `composer.json` de cada projeto (a forma recomendada, veja [Adoção](adocao.md#2-ligar-a-sincronização-no-composerjson)) | nunca faz o Composer falhar; não faz nada com `--no-dev` |
| `vendor/bin/elavora-standards sync [--force] [--dir=<pasta>]` | uma pessoa no terminal, ou o CI | sai com erro quando algo dá errado; aceita `--force` |

Cada execução trata dois arquivos na raiz do projeto: o `.editorconfig` e o `.php-cs-fixer.dist.php`.

### O `.editorconfig`

| Situação do `.editorconfig` na raiz | O que acontece | Mensagem |
| --- | --- | --- |
| não existe | é criado só com o bloco gerado | `.editorconfig criado` |
| existe, vazio ou só com espaços e quebras de linha | é reescrito só com o bloco gerado | `.editorconfig atualizado` |
| tem o bloco, igual ao atual | nada é escrito (a data de modificação não muda) | `.editorconfig sem mudanças` |
| tem o bloco, diferente do atual (o pacote foi atualizado, ou alguém editou o bloco) | o bloco é substituído; o que está antes e depois dele fica como estava | `.editorconfig atualizado` |
| existe, tem conteúdo e não tem nenhuma das duas linhas marcadoras | **nada é escrito**; aviso | `.editorconfig mantido: ...` (veja abaixo) |
| marcadores quebrados: só uma das duas linhas, alguma delas repetida, ou a de fim antes da de início | **nada é escrito**; erro | `erro: ...` |
| qualquer um dos casos acima, com `--force` (só no comando) | o arquivo inteiro é substituído só pelo bloco gerado; o conteúdo antigo se perde | `.editorconfig criado` (se não existia), `.editorconfig sem mudanças` (se já era igual) ou `.editorconfig atualizado` |

Um `.editorconfig` escrito à mão, ou uma cópia antiga da base sem os marcadores, cai no caso "sem marcadores". A
sincronização não sobrescreve porque o arquivo pode ter regras que o projeto quer manter. A adoção, nesse caso, é
rodar `vendor/bin/elavora-standards sync --force` uma vez e devolver as seções próprias para depois do bloco.

O arquivo escrito sempre usa fim de linha LF: os trechos preservados, antes e depois do bloco, têm `\r\n` e `\r`
convertidos para `\n`, e o arquivo termina com uma quebra de linha.

### O stub do PHP-CS-Fixer

| Situação do `.php-cs-fixer.dist.php` na raiz | O que acontece | Mensagem |
| --- | --- | --- |
| não existe | é criado com o conteúdo exato do [stub](php-cs-fixer.md#o-stub-php-cs-fixerdistphp) | `.php-cs-fixer.dist.php criado` |
| existe (com qualquer conteúdo, inclusive vazio) | nada é escrito, nem com `--force` | `.php-cs-fixer.dist.php já existe, mantido` |

O stub é do projeto depois de criado: ele pode ter ajustes (veja
[Ajustar as regras](php-cs-fixer.md#ajustar-as-regras-num-projeto)), e o pacote nunca o reescreve. A existência de
um `.php-cs-fixer.php` (override pessoal) não muda nada nessa regra.

O `.editorconfig` é tratado antes do stub. Se o `.editorconfig` der erro, o stub não é tratado.

---

## Referência

### Mensagens

Todas as linhas começam com `elavora/code-standards: ` e terminam com quebra de linha. As de resultado vão para a
saída padrão; o aviso e os erros vão para a saída de erro.

| Situação | Linha completa | Saída |
| --- | --- | --- |
| `.editorconfig` criado | `elavora/code-standards: .editorconfig criado` | padrão |
| `.editorconfig` reescrito | `elavora/code-standards: .editorconfig atualizado` | padrão |
| `.editorconfig` igual | `elavora/code-standards: .editorconfig sem mudanças` | padrão |
| `.editorconfig` sem marcadores | `elavora/code-standards: .editorconfig mantido: o arquivo não tem o bloco do elavora/code-standards. Rode "vendor/bin/elavora-standards sync --force" para substituí-lo pela base.` | erro |
| stub criado | `elavora/code-standards: .php-cs-fixer.dist.php criado` | padrão |
| stub existente | `elavora/code-standards: .php-cs-fixer.dist.php já existe, mantido` | padrão |
| erro | `elavora/code-standards: erro: <mensagem da exceção>` | erro |

### Comando `vendor/bin/elavora-standards`

Arquivo `bin/elavora-standards`, script PHP com `#!/usr/bin/env php`, declarado em `bin` no `composer.json` do pacote.
Ele funciona chamado como `vendor/bin/elavora-standards` ou `php vendor/bin/elavora-standards`, no Linux e no Windows,
e carrega o autoloader do Composer usando o primeiro destes que existir:

1. `$GLOBALS['_composer_autoload_path']`, que o proxy do Composer 2.2+ em `vendor/bin/` define;
2. `__DIR__ . '/../../../autoload.php'`, o `vendor/autoload.php` do projeto quando o pacote está instalado em
   `vendor/elavora/code-standards/`;
3. `__DIR__ . '/../vendor/autoload.php'`, no repositório do próprio pacote.

Se nenhum existir, escreve `elavora/code-standards: erro: ...` na saída de erro, dizendo que falta rodar
`composer install`, e sai com 1.

```
elavora-standards sync [--force] [--dir=<pasta>]
elavora-standards lint [--dir=<pasta>] [--exclude=<caminho>]... [<caminho>...]
elavora-standards help | --help | -h
```

O subcomando `lint` (a partir da 1.1.0) está descrito em
[Lint de sintaxe PHP](lint.md#subcomando-elavora-standards-lint). A tabela abaixo é a do `sync` e do uso geral, que
não mudou.

| Chamada | Efeito | Código de saída |
| --- | --- | --- |
| `sync` | sincroniza a pasta atual | 0, inclusive quando o `.editorconfig` foi mantido com aviso |
| `sync --dir=<pasta>` | sincroniza `<pasta>`; caminho relativo vale a partir da pasta atual | 0 |
| `sync --force` | sincroniza substituindo o `.editorconfig` inteiro pelo bloco gerado | 0 |
| `sync` com marcadores quebrados, sem `--force` | erro, nada é escrito | 1 |
| `sync --dir=<pasta>` com `<pasta>` inexistente ou que é um arquivo | erro, nada é escrito | 1 |
| `sync` sem permissão de escrita | erro | 1 |
| `help`, `--help`, `-h` | mostra o uso na saída padrão | 0 |
| sem argumentos | mostra o uso na saída de erro | 2 |
| comando desconhecido (`foo`), opção desconhecida (`sync --bar`), `--dir` sem valor (`--dir` ou `--dir=`), ou argumento a mais (`sync x`) | mostra o uso na saída de erro, nada é escrito | 2 |

`--force` e `--dir` podem vir em qualquer ordem. O texto de uso contém a linha
`elavora-standards sync [--force] [--dir=<pasta>]` e, a partir da 1.1.0, também a linha
`elavora-standards lint [--dir=<pasta>] [--exclude=<caminho>]... [<caminho>...]`.

### Callback do Composer `Elavora\CodeStandards\Composer::sync`

Arquivo `src/Composer.php`.

```php
namespace Elavora\CodeStandards;

final class Composer
{
    public static function sync(object $event): void;
}
```

`$event` é o `Composer\Script\Event` que o Composer passa aos scripts. O pacote não depende do `composer/composer`,
por isso o tipo é `object` e o método usa só:

- `$event->isDevMode(): bool`;
- `$event->getIO()->write(string $message)` para as linhas da saída padrão;
- `$event->getIO()->writeError(string $message)` para o aviso e os erros.

As mensagens passadas a `write`/`writeError` são as linhas da tabela de mensagens, sem a quebra de linha final.

Comportamento:

1. `isDevMode()` é `false` → retorna sem escrever arquivo nem mensagem.
2. Senão, sincroniza o diretório atual (`getcwd()`, que o Composer coloca na raiz do projeto, mesmo com
   `composer -d <pasta>`), sem `--force`.
3. **Nunca lança exceção.** Qualquer erro (marcadores quebrados, falta de permissão, base ausente) vira uma linha
   `elavora/code-standards: erro: ...` em `writeError`, e o método retorna normalmente. O `composer install` segue
   com código 0.

Com `composer install --no-dev`, o pacote não está instalado e o Composer não encontra a classe. Ele escreve
`Class Elavora\CodeStandards\Composer is not autoloadable, can not call post-install-cmd script` e segue com código
0. Esse é um comportamento do Composer 2, e não do pacote.

### Classes de apoio

Para que a lógica seja testável sem processo nem Composer, ela fica em duas classes públicas, que o comando e o
callback usam.

#### `Elavora\CodeStandards\EditorConfig`

Arquivo `src/EditorConfig.php`. Classe `final`, só com membros estáticos.

```php
public const BEGIN_MARKER = '# >>> elavora/code-standards >>>';
public const END_MARKER = '# <<< elavora/code-standards <<<';

public static function base(): string;
public static function managedBlock(): string;
public static function merge(?string $current, bool $force = false): string;
```

- `base()`: o conteúdo de `resources/editorconfig`, exatamente como na seção [O `.editorconfig` base](#o-editorconfig-base).
  Se o arquivo não existir ou não puder ser lido, lança `\RuntimeException`.
- `managedBlock()`: o bloco gerado, exatamente estas linhas, unidas por `\n` e com `\n` no final:

  ```
  # >>> elavora/code-standards >>>
  # Bloco gerado pelo elavora/code-standards. Não edite: ele é reescrito a cada composer install e composer update.
  # Seções próprias do projeto vão depois da linha "# <<< elavora/code-standards <<<".
  <as linhas de base(), sem a quebra de linha final>
  # <<< elavora/code-standards <<<
  ```

  Ele não traz a versão do pacote, para que o arquivo do projeto só mude quando as regras mudam.
- `merge($current, $force)`: devolve o conteúdo novo do `.editorconfig`, a partir do atual. É uma função pura: não
  lê nem escreve arquivos (além de `base()`).
  1. Com `$force = true`, devolve `managedBlock()`, qualquer que seja `$current`.
  2. `null` (o arquivo não existe), `''`, ou um texto só com espaços, TABs e quebras de linha: devolve
     `managedBlock()`.
  3. Converte `\r\n` e `\r` para `\n` e separa as linhas. Uma linha é marcadora quando, sem os espaços e TABs do
     fim, é igual a `BEGIN_MARKER` ou a `END_MARKER`. Espaço no começo da linha faz com que ela não seja marcadora.
  4. Nenhuma marcadora: lança `Elavora\CodeStandards\Exception\UnmanagedFileException`.
  5. Exatamente uma de cada, com a de início antes da de fim: devolve as linhas antes da de início, seguidas de
     `managedBlock()`, seguidas das linhas depois da de fim, como estavam (já com `\n`). Se o texto depois da linha
     de fim não terminar com quebra de linha, `merge` acrescenta uma.
  6. Qualquer outro caso (só uma das duas, alguma repetida, a de fim antes da de início): lança
     `Elavora\CodeStandards\Exception\MalformedMarkersException`, com uma mensagem que diz qual é o problema.

Exemplos (`B` é o valor de `managedBlock()`):

| `$current` | `$force` | Resultado |
| --- | --- | --- |
| `null` | `false` | `B` |
| `"\n\n"` | `false` | `B` |
| `B` | `false` | `B` |
| `B . "\n[*.sql]\nindent_size = 2\n"` | `false` | o mesmo texto |
| `"# projeto\n" . <bloco antigo, com base diferente> . "[*.sql]\nindent_size = 2"` | `false` | `"# projeto\n" . B . "[*.sql]\nindent_size = 2\n"` |
| `B`, com `\r\n` no lugar de `\n` | `false` | `B` |
| `"root = true\n[*]\nindent_style = tab\n"` | `false` | lança `UnmanagedFileException` |
| `"root = true\n[*]\nindent_style = tab\n"` | `true` | `B` |
| `"# >>> elavora/code-standards >>>\nroot = true\n"` | `false` | lança `MalformedMarkersException` (falta o fim) |
| `B . B` | `false` | lança `MalformedMarkersException` (marcadores repetidos) |
| `END_MARKER . "\n" . BEGIN_MARKER . "\n"` | `false` | lança `MalformedMarkersException` (fim antes do início) |
| qualquer texto com marcadores quebrados | `true` | `B` |

#### `Elavora\CodeStandards\Sync`

Arquivo `src/Sync.php`. Classe `final`, só com membros estáticos.

```php
public const STUB = "<?php\n\nrequire_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';\n\nreturn Elavora\\CodeStandards\\PhpCsFixer::config(__DIR__);\n";

/** @return array{editorconfig: Status, stub: Status} */
public static function run(string $dir, bool $force = false): array;
```

`Elavora\CodeStandards\Status` (arquivo `src/Status.php`) é um enum com os casos `Created`, `Updated`, `Unchanged` e
`Skipped`.

`run` aplica as regras de [A sincronização](#a-sincronização) em `$dir`:

- `$dir` que não é um diretório existente: lança `\InvalidArgumentException` antes de qualquer escrita.
- `editorconfig`: `Created` (não existia), `Updated` (o conteúdo mudou), `Unchanged` (o conteúdo de `merge` é igual
  ao do arquivo, byte a byte; nada é escrito) ou `Skipped` (`UnmanagedFileException`; nada é escrito).
- `MalformedMarkersException` é propagada, e o stub não é tratado.
- `stub`: `Created` (o arquivo não existia e foi escrito com `STUB`) ou `Unchanged` (já existia; nada é escrito).
  Nunca `Updated` nem `Skipped`.
- Falha de leitura ou de escrita: lança `\RuntimeException` com o caminho do arquivo na mensagem.
- Os arquivos são escritos em modo binário, para que o LF seja mantido também no Windows.

#### Exceções

Ficam no namespace `Elavora\CodeStandards\Exception`, ambas `final` e filhas de `\RuntimeException`:

- `UnmanagedFileException`: o `.editorconfig` tem conteúdo e nenhuma linha marcadora.
- `MalformedMarkersException`: as linhas marcadoras estão quebradas.

---

[README](../README.md) · Anterior: [Configuração do PHP-CS-Fixer](php-cs-fixer.md) · Próximo: [Lint de sintaxe PHP](lint.md)
