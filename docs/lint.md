# Lint de sintaxe PHP

[README](../README.md) · Anterior: [EditorConfig e o comando `sync`](editorconfig.md) · Próximo: [Base do PHPStan](phpstan.md)

## Visão geral

O lint confere se os arquivos PHP do projeto **compilam**: ele roda `php -l` em cada arquivo e aponta os que têm
erro de sintaxe. Não é análise estática (isso é o PHPStan) nem formatação (isso é o PHP-CS-Fixer). Ele pega, por
exemplo, uma chave sem fechar ou uma sintaxe de uma versão do PHP mais nova do que a da imagem.

A partir da versão **1.1.0**, o lint é um subcomando do pacote:

```sh
vendor/bin/elavora-standards lint
```

e o projeto o liga no script `lint` do `composer.json`, que é o que o CI de qualidade chama:

```json
"scripts": {
    "lint": "elavora-standards lint"
}
```

Antes disso, cada projeto tinha uma cópia própria de `tools/lint.php`: o `api-skeleton` copiava o arquivo em cada
projeto novo e o `api-framework` tinha outra versão, com outras pastas. Uma cópia não recebe correções. Com o lint no
pacote, o projeto guarda só uma linha no `composer.json`, e as melhorias chegam pelo PR do Dependabot que atualiza o
`elavora/code-standards`, como as regras de formatação.

## Uso num projeto

1. O pacote precisa estar no `require-dev` com **`^1.1`** (e não `^1.0`):

   ```json
   "require-dev": {
       "elavora/code-standards": "^1.1"
   }
   ```

   O CI de qualidade roda o `composer lint` com as dependências nas **versões mínimas** permitidas pelo
   `composer.json`. Com `^1.0`, a versão mínima é a 1.0.0, que não tem o subcomando `lint`, e o CI falha com o uso do
   comando.

2. O script `lint` chama o subcomando:

   ```json
   "scripts": {
       "lint": "elavora-standards lint"
   }
   ```

   Sem argumentos, o lint confere o projeto inteiro (veja [Quais arquivos são conferidos](#quais-arquivos-são-conferidos)).
   Esse é o caso comum, e não precisa de configuração nenhuma.

3. No dia a dia, dentro do devcontainer:

   ```sh
   composer lint
   ```

### Ajustar as pastas

A configuração fica na própria linha do script, como argumentos do comando. Não há arquivo de configuração nem
chave em `extra`.

| Necessidade | Script `lint` |
| --- | --- |
| o projeto inteiro (padrão) | `elavora-standards lint` |
| só algumas pastas e arquivos | `elavora-standards lint src tests bin/console` |
| o projeto inteiro menos uma pasta | `elavora-standards lint --exclude=tests/fixtures` |
| o projeto inteiro mais um arquivo sem extensão `.php` | `elavora-standards lint . bin/console` |

Quando há caminhos na linha, eles **substituem** o padrão: `elavora-standards lint src` não confere `tests/`. Para
somar um arquivo ao projeto inteiro, passe `.` junto, como na última linha.

`composer lint -- <argumentos>` acrescenta argumentos aos do script, numa execução só. Por exemplo,
`composer lint -- --exclude=tests/legado`.

### Por que um subcomando, e não um método estático no `composer.json`

O `sync` é um método PHP (`Elavora\CodeStandards\Composer::sync`) porque roda no `post-install-cmd`, inclusive na
instalação de produção com `--no-dev`, em que o pacote não existe (veja
[Adoção](adocao.md#2-ligar-a-sincronização-no-composerjson)). O lint é diferente: ninguém o chama numa instalação de
produção, e ele precisa de um **código de saída** próprio, que o CI lê.

- Um método chamado pelo Composer não tem como sair com 1 sem lançar exceção, e o Composer mostra a exceção como
  falha do script, misturada com a saída do lint.
- Um comando funciona também fora do Composer (`vendor/bin/elavora-standards lint src`), num terminal, num hook de
  git ou no editor.
- O pacote já tem um comando, `elavora-standards`, que sabe encontrar o autoloader do Composer no Linux e no
  Windows. O lint é um subcomando dele, e não um segundo binário em `vendor/bin`.

O Composer coloca a pasta `vendor/bin` na frente do `PATH` quando roda um script, por isso o script pode chamar
`elavora-standards` sem o caminho. No Windows, o Composer cria o `vendor/bin/elavora-standards.bat`, e a mesma linha
funciona.

**Depois de `composer install --no-dev`**, o comando não existe e o `composer lint` falha (no Linux, com
`elavora-standards: not found`). Isso é esperado: o lint faz parte do desenvolvimento e do CI, não do build de
produção. O `Dockerfile` do `api-skeleton` não roda o lint.

## Quais arquivos são conferidos

### Sem caminhos na linha (o padrão)

O lint percorre a pasta do projeto (a pasta atual, ou a de `--dir`) em qualquer profundidade e confere todo arquivo
cuja extensão é `.php`, sem diferenciar maiúsculas de minúsculas (`A.php` e `B.PHP` entram), **menos**:

| Excluído | Exemplo | Por quê |
| --- | --- | --- |
| toda pasta chamada `vendor`, em qualquer nível | `vendor/`, `tests/fixtures/vendor/` | código de terceiros |
| todo arquivo e toda pasta cujo nome começa com ponto | `.php-cs-fixer.dist.php`, `.git/`, `.github/`, `.phpstan.cache/`, `.elavora/` | configuração, cache e automação, não código do projeto |
| o que for passado em `--exclude` | `--exclude=tests/fixtures` | escolha do projeto |
| links simbólicos para pastas | | o lint não entra neles, para não andar em círculo |

Um link simbólico para um arquivo `.php` é conferido como um arquivo comum.

São as mesmas regras do PHP-CS-Fixer (veja [Quais arquivos são formatados](php-cs-fixer.md#quais-arquivos-são-formatados)),
com uma diferença: o lint **não lê o `.gitignore`**. Ele roda sem o `git` e sem o Symfony Finder, e um arquivo
ignorado pelo git que não compila ainda é um problema. Uma pasta gerada com PHP dentro (um cache, por exemplo) que
não comece com ponto fica de fora com `--exclude`.

Exemplo: num projeto com `src/A.php`, `src/B.PHP`, `tests/ATest.php`, `worker.php`, `bin/console` (sem extensão),
`.php-cs-fixer.dist.php`, `.phpstan.cache/x.php`, `vendor/x/D.php` e `tests/fixtures/vendor/E.php`, o lint confere,
nesta ordem:

```
src/A.php
src/B.PHP
tests/ATest.php
worker.php
```

### Com caminhos na linha

Cada caminho é relativo à pasta do projeto (ou absoluto) e pode ser:

- **uma pasta:** é percorrida com as mesmas regras do padrão (só `.php`, sem `vendor`, sem nomes com ponto, sem
  links para pastas). As regras valem para o que está **dentro** dela: `elavora-standards lint .github/scripts`
  confere os `.php` de `.github/scripts/`, porque a pasta foi pedida pelo nome;
- **um arquivo:** é conferido, qualquer que seja a extensão (`bin/console` entra). Também vale para um arquivo com
  ponto no começo do nome, se ele for pedido pelo nome.

Um caminho que não existe é erro: nada é conferido, e o comando sai com 1 (veja [Referência](#referência)).

`.` é a pasta do projeto: `elavora-standards lint .` confere o mesmo que `elavora-standards lint`.

### `--exclude`

`--exclude=<caminho>` tira um arquivo ou uma pasta (com tudo o que está dentro) do resultado. Pode ser repetido.

- O caminho é relativo à pasta do projeto, ou absoluto. `./` no começo e `/` no fim são ignorados:
  `--exclude=tests/fixtures`, `--exclude=./tests/fixtures` e `--exclude=tests/fixtures/` são iguais.
- A comparação é por pasta inteira: `--exclude=tests/fix` não tira `tests/fixtures/A.php`.
- Vale também para os caminhos da linha: `elavora-standards lint src --exclude=src/Legado` confere `src/` menos
  `src/Legado/`, e `elavora-standards lint src/A.php --exclude=src` não confere nada.
- Um `--exclude` que não existe é ignorado, sem aviso.
- Não há curingas (`*`, `**`): o caminho é literal.

### Ordem e repetição

Os arquivos aparecem pelo caminho mostrado, em ordem de bytes (a ordem de `sort()` do PHP com `SORT_STRING`). Um
arquivo que aparece mais de uma vez (por exemplo, `elavora-standards lint src src/A.php`) é conferido uma vez só.

**Caminho mostrado:** relativo à pasta do projeto, com `/` como separador e sem `./` no começo (`src/A.php`). Um
arquivo fora da pasta do projeto, pedido por caminho absoluto ou com `..`, aparece com o caminho absoluto dele.

## O que aparece na saída

Todas as linhas começam com `elavora/code-standards: `, como as do `sync`.

**Nenhum erro** (saída padrão, código 0):

```
elavora/code-standards: lint ok: 42 arquivo(s) sem erro de sintaxe
```

Com zero arquivos (uma pasta sem PHP, ou tudo excluído), a linha é a mesma, com `0 arquivo(s)`, e o código é 0.

**Algum erro** (saída de erro, código 1). Para cada arquivo com erro, na ordem dos arquivos, uma linha de título e
as linhas da mensagem do `php -l`, com dois espaços na frente; no fim, o resumo:

```
elavora/code-standards: erro de sintaxe em src/Quebrado.php
  Parse error: Unclosed '{' on line 3 in src/Quebrado.php on line 4
elavora/code-standards: lint com erro: 1 de 42 arquivo(s) com erro de sintaxe
```

O texto da mensagem é do PHP e muda entre versões; o que o pacote garante é o título, os dois espaços, o resumo e
que a mensagem contém o caminho mostrado e o número da linha (`on line <n>`). Nada vai para a saída padrão quando há
erro. Os arquivos sem erro não aparecem.

## Referência

### Subcomando `elavora-standards lint`

Faz parte do comando `bin/elavora-standards` (veja a
[referência do comando](editorconfig.md#comando-vendorbinelavora-standards), que vale também para o modo de
encontrar o autoloader).

```
elavora-standards lint [--dir=<pasta>] [--exclude=<caminho>]... [<caminho>...]
```

- `--dir=<pasta>`: a pasta do projeto. Padrão: a pasta atual. Um caminho relativo vale a partir da pasta atual. O
  Composer roda os scripts na raiz do projeto, também com `composer -d <pasta> lint`, então o script não precisa de
  `--dir`.
- `--exclude=<caminho>`: veja [`--exclude`](#--exclude). Pode ser repetido.
- `<caminho>...`: veja [Com caminhos na linha](#com-caminhos-na-linha).
- As opções e os caminhos podem vir em qualquer ordem. Um argumento que começa com `-` é sempre lido como opção.

| Chamada | Efeito | Código de saída |
| --- | --- | --- |
| `lint`, todos os arquivos sem erro (ou zero arquivos) | `lint ok: ...` na saída padrão | 0 |
| `lint`, algum arquivo com erro de sintaxe | os blocos de erro e o resumo na saída de erro | 1 |
| `lint --dir=<pasta>` com `<pasta>` inexistente ou que é um arquivo | `elavora/code-standards: erro: ...` na saída de erro, com o caminho; nada é conferido | 1 |
| `lint <caminho>` com `<caminho>` inexistente | `elavora/code-standards: erro: ...` na saída de erro, com o caminho; nada é conferido | 1 |
| opção desconhecida (`lint --force`, `lint --bar`), `--dir` ou `--exclude` sem valor (`--dir`, `--dir=`, `--exclude`, `--exclude=`), `--dir` repetido | mostra o uso na saída de erro; nada é conferido | 2 |

O texto de uso (de `help` e dos erros de uso) passa a ter também a linha
`elavora-standards lint [--dir=<pasta>] [--exclude=<caminho>]... [<caminho>...]`. Todo o resto do comando continua
como na 1.0.0.

### Como cada arquivo é conferido

Para cada arquivo, o lint roda um processo separado, sem shell:

```
<PHP_BINARY> -d display_errors=1 -d log_errors=0 -l <caminho mostrado>
```

- `PHP_BINARY` é o PHP que está rodando o comando. Na imagem `ghcr.io/elavora/php-dev:8.3-*`, o PHP 8.3, que é a
  versão que o lint garante.
- O processo roda com a pasta do projeto como pasta atual, para que a mensagem do PHP traga o caminho mostrado.
- `display_errors=1` e `log_errors=0` fazem a mensagem sair uma vez só. Na imagem, os dois estão ligados, e sem isso
  o PHP escreve o mesmo erro na saída padrão e na de erro.
- O arquivo tem erro quando o processo sai com código diferente de 0. A mensagem é o que o processo escreveu (nas
  duas saídas), sem as linhas vazias e sem a linha que começa com `Errors parsing `.
- Se o processo sair com erro sem escrever nada, a mensagem é `o php -l saiu com o código <n>`. Se o processo não
  puder ser iniciado, a mensagem é `não foi possível iniciar o php -l`. Nos dois casos, o arquivo conta como erro.
- Um aviso do PHP com código 0 não é erro e não aparece. Exemplo: `<?php function f($a = 1, $b) {}` gera, no PHP
  8.3, `Deprecated: Optional parameter $a declared before required parameter $b ...`, mas o `php -l` sai com 0.

Os arquivos são conferidos um de cada vez, na ordem mostrada.

### Classe `Elavora\CodeStandards\Lint`

Arquivo `src/Lint.php`. Classe `final`, só com membros estáticos. O subcomando usa as duas funções; elas são
públicas para que a lógica seja testável sem processo.

```php
namespace Elavora\CodeStandards;

final class Lint
{
    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @return list<string>
     */
    public static function files(string $dir, array $paths = [], array $exclude = []): array;

    public static function check(string $dir, string $file): ?string;
}
```

**`files($dir, $paths, $exclude)`:** a lista de arquivos a conferir, com os caminhos mostrados, na ordem e sem
repetição, conforme [Quais arquivos são conferidos](#quais-arquivos-são-conferidos). `$paths` vazio é o padrão (a
pasta inteira). Não escreve nada nem roda processo.

- `$dir` que não é um diretório existente: lança `\InvalidArgumentException` com o valor recebido na mensagem.
- Um item de `$paths` que não existe: lança `\InvalidArgumentException` com o item na mensagem.
- O resultado não depende do diretório atual quando `$dir` é absoluto.

Exemplos, numa pasta `$dir` com `src/A.php`, `src/Legado/B.php`, `tests/ATest.php`, `bin/console` e
`vendor/x/D.php`:

| `$paths` | `$exclude` | Resultado |
| --- | --- | --- |
| `[]` | `[]` | `['src/A.php', 'src/Legado/B.php', 'tests/ATest.php']` |
| `['.']` | `[]` | o mesmo |
| `['src']` | `[]` | `['src/A.php', 'src/Legado/B.php']` |
| `['./src/']` | `[]` | o mesmo |
| `[]` | `['src/Legado']` | `['src/A.php', 'tests/ATest.php']` |
| `[]` | `['src/Leg']` | `['src/A.php', 'src/Legado/B.php', 'tests/ATest.php']` |
| `['.', 'bin/console']` | `[]` | `['bin/console', 'src/A.php', 'src/Legado/B.php', 'tests/ATest.php']` |
| `['src', 'src/A.php']` | `[]` | `['src/A.php', 'src/Legado/B.php']` |
| `['src/A.php']` | `['src']` | `[]` |
| `['vendor/x']` | `[]` | `['vendor/x/D.php']` |
| `[]` | `['nao-existe']` | `['src/A.php', 'src/Legado/B.php', 'tests/ATest.php']` |
| `['nao-existe']` | `[]` | lança `\InvalidArgumentException` |

**`check($dir, $file)`:** confere um arquivo, como em [Como cada arquivo é conferido](#como-cada-arquivo-é-conferido),
com `$dir` como pasta atual do processo e `$file` como o caminho passado ao `php -l`. Devolve `null` quando o arquivo
não tem erro; senão, a mensagem (as linhas unidas por `\n`, sem quebra de linha no final e sem os dois espaços, que
são da saída do comando).

## Migrar do `tools/lint.php`

Estas mudanças vão nos PRs de cada repositório, depois da publicação da 1.1.0.

### `api-framework`

1. Apagar `tools/lint.php` (e a pasta `tools/`, se ficar vazia).
2. No `composer.json`:
   - `require-dev`: `"elavora/code-standards": "^1.1"` (era `^1.0`);
   - `scripts`: `"lint": "elavora-standards lint"` (era `"php tools/lint.php"`). O `check`, que chama `@lint`, não
     muda.
3. Rodar `composer update elavora/code-standards` e `composer lint`, e commitar.

O que muda no comportamento:

| | `tools/lint.php` antigo | `elavora-standards lint` |
| --- | --- | --- |
| Pastas | `src/` e `tests/`; falha com exceção se uma delas não existir | o projeto inteiro, menos `vendor`, nomes com ponto (como `.phpstan.cache/`) e `--exclude` |
| Saída | uma linha `No syntax errors detected in ...` por arquivo | uma linha só quando não há erro; só os arquivos com erro quando há |
| Extensão | `.php`, diferenciando maiúsculas | `.php`, sem diferenciar |

Hoje o `api-framework` só tem PHP em `src/` e `tests/` (fora das pastas com ponto), então a lista de arquivos é a
mesma, sem `--exclude`.

### `api-skeleton`

1. Apagar `tools/lint.php` (e a pasta `tools/`, se ficar vazia).
2. No `composer.json`:
   - `require-dev`: `"elavora/code-standards": "^1.1"` (acrescentar, se ainda não houver, ou trocar o `^1.0`);
   - `scripts`: `"lint": "elavora-standards lint"` (era `"@php tools/lint.php"`). O `check` não muda.
3. Na documentação do skeleton (`docs/ambiente-e-ci.md`), a linha "Lint portátil" da tabela de atualizações passa
   a dizer que o lint vem do `elavora/code-standards` e é atualizado pelo **Dependabot**, como as regras do
   PHP-CS-Fixer. O `tools/lint.php` sai da lista de arquivos copiados.

O que muda no comportamento:

| | `tools/lint.php` antigo | `elavora-standards lint` |
| --- | --- | --- |
| Pastas | `app/`, `core/`, `public/`, `tests/` (as que existirem) e `worker.php` | o projeto inteiro, menos `vendor`, nomes com ponto e `--exclude` |
| Saída sem erro | `Lint aprovado em N arquivo(s).` | `elavora/code-standards: lint ok: N arquivo(s) sem erro de sintaxe` |
| Saída com erro | `<caminho absoluto>:` e a mensagem, na saída de erro | o título com o caminho relativo, a mensagem com dois espaços e o resumo |

Num projeto criado pelo skeleton, o resultado é o mesmo conjunto de arquivos, a menos que o projeto tenha criado
PHP em outra pasta. Nesse caso, a pasta nova passa a ser conferida, o que é o esperado; se não for, `--exclude`.

Projetos que já foram criados a partir do skeleton fazem a mesma troca, num PR próprio.

---

[README](../README.md) · Anterior: [EditorConfig e o comando `sync`](editorconfig.md) · Próximo: [Base do PHPStan](phpstan.md)
