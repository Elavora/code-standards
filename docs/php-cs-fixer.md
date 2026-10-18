# Configuração do PHP-CS-Fixer

[README](../README.md) · Anterior: [Adoção num projeto](adocao.md) · Próximo: [EditorConfig e o comando `sync`](editorconfig.md)

## Visão geral

O PHP-CS-Fixer procura a configuração num arquivo PHP na raiz do projeto (`.php-cs-fixer.php` e, se ele não
existir, `.php-cs-fixer.dist.php`). Esse arquivo devolve um objeto de configuração com as regras e a lista de
arquivos a formatar.

Em vez de cada projeto manter uma cópia das regras, o `.php-cs-fixer.dist.php` de cada projeto é um **stub** de
duas linhas que pede a configuração ao pacote:

```php
<?php

require_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';

return Elavora\CodeStandards\PhpCsFixer::config(__DIR__);
```

Quando as regras mudam, basta atualizar o pacote: o stub continua igual.

A configuração devolvida:

- usa o conjunto de regras `@PER-CS`, e só ele;
- não liga regras *risky* (as que podem mudar o comportamento do código, como trocar `==` por `===`);
- não grava cache;
- formata todos os arquivos `.php` do projeto, menos `vendor/`, os arquivos e pastas que começam com ponto e o que o
  `.gitignore` ignora.

## O stub `.php-cs-fixer.dist.php`

O conteúdo acima é exato: é o que o comando `sync` cria quando o arquivo não existe (veja
[EditorConfig e o comando `sync`](editorconfig.md#o-stub-do-php-cs-fixer)). Em bytes, são estas cinco linhas, com fim
de linha LF e uma quebra de linha no final:

```
<?php
(linha vazia)
require_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';
(linha vazia)
return Elavora\CodeStandards\PhpCsFixer::config(__DIR__);
```

Por que ele é assim:

- **`__DIR__`, e não o diretório atual.** O stub passa a pasta onde ele mesmo está, que é a raiz do projeto. A
  configuração funciona igual se o `php-cs-fixer` for chamado da raiz, de uma subpasta ou com
  `--config=/caminho/.php-cs-fixer.dist.php`.
- **O arquivo da classe, e não o `vendor/autoload.php`.** O PHP-CS-Fixer da imagem é um PHAR que traz as próprias
  dependências (Symfony Console, Finder, Process e outras) sem trocar o namespace delas. O autoloader do Composer de
  um projeto se registra **na frente** dos outros. Se o stub carregasse o `vendor/autoload.php` e o projeto tivesse,
  por exemplo, o `symfony/finder` numa versão diferente, o PHP-CS-Fixer passaria a usar classes do projeto misturadas
  com as dele. Carregando só o arquivo da classe, nada do `vendor/` do projeto entra no processo do PHP-CS-Fixer.
  Por isso a classe `Elavora\CodeStandards\PhpCsFixer` é autocontida: o arquivo não depende de nenhuma outra classe
  do pacote.
- **`require_once`.** Se a classe já tiver sido carregada pelo autoloader (num teste, por exemplo), o arquivo não é
  carregado de novo.
- O stub supõe a pasta `vendor` padrão do Composer. Um projeto com outro `vendor-dir` ajusta o caminho do
  `require_once` à mão.

## Por que o pacote não depende do PHP-CS-Fixer

O `composer.json` do pacote não tem `friendsofphp/php-cs-fixer` em `require`. O binário vem da imagem
`ghcr.io/elavora/php-dev` (o PHAR da versão `3.95.27` em `/usr/local/bin/php-cs-fixer`, a partir da `8.3-0.2.0`),
para que o editor, o terminal e o CI usem exatamente a mesma versão. Se o pacote exigisse o PHP-CS-Fixer, cada
projeto teria uma segunda cópia no `vendor/`, possivelmente em outra versão, e a formatação poderia mudar conforme
quem rodasse.

**Por que as classes `PhpCsFixer\*` existem quando a configuração é carregada.** A classe do pacote usa
`PhpCsFixer\Config` e `PhpCsFixer\Finder`, que não estão no `vendor/` do projeto. Elas vêm do próprio PHAR:

1. `php-cs-fixer fix` inicia o PHAR, que registra o autoloader das classes dele (`PhpCsFixer\*` e as dependências).
2. Só depois disso o PHP-CS-Fixer carrega o `.php-cs-fixer.dist.php` do projeto.
3. O stub carrega o arquivo da classe `Elavora\CodeStandards\PhpCsFixer` e chama `config()`.
4. Dentro de `config()`, `PhpCsFixer\Config` e `PhpCsFixer\Finder` são resolvidas pelo autoloader do PHAR, que já
   está ativo.

As *workers* da execução paralela do PHP-CS-Fixer repetem o mesmo caminho e também encontram as classes.

**Fora da imagem.**

- Com outro PHP-CS-Fixer 3.x (um PHAR baixado, `composer global require`, ou o pacote no `vendor/` de outro
  projeto), a configuração funciona igual, porque só usa a API pública `PhpCsFixer\Config` e `PhpCsFixer\Finder`.
  O resultado pode diferir do CI se a versão for outra, porque a definição de `@PER-CS` muda entre versões. A versão
  de referência é a da imagem.
- Carregar a configuração **sem** o PHP-CS-Fixer (por exemplo `php .php-cs-fixer.dist.php`, ou chamar
  `PhpCsFixer::config()` num script ou teste qualquer) lança `RuntimeException` com uma mensagem explicando que a
  configuração precisa ser carregada pelo `php-cs-fixer` da imagem `ghcr.io/elavora/php-dev`. Sem essa checagem, o
  erro seria um `Class "PhpCsFixer\Config" not found` sem contexto.
- O pacote não funciona com o PHP-CS-Fixer 2.x, que não tem `PhpCsFixer\Finder::create()` com a mesma API e usa
  outros nomes de arquivo de configuração.

## Quais arquivos são formatados

O `Finder` parte do diretório passado a `config()` (a raiz do projeto, no stub) e pega todos os arquivos com
extensão `.php`, em qualquer profundidade, menos:

| Excluído | Exemplo | Por quê |
| --- | --- | --- |
| toda pasta chamada `vendor`, em qualquer nível | `vendor/`, `tests/fixtures/vendor/` | código de terceiros |
| arquivos e pastas que começam com ponto | `.php-cs-fixer.dist.php`, `.github/`, `.devcontainer/`, `.elavora/` | configuração e automação, não código do projeto; a pasta `.elavora/` é onde o CI da organização baixa os próprios scripts |
| pastas de controle de versão | `.git/` | |
| o que os arquivos `.gitignore` do projeto ignoram | `build/`, `coverage/` | arquivos gerados. Funciona lendo os `.gitignore`, sem chamar o `git`, e por isso vale também fora de um repositório |

Exemplo: num projeto com `src/A.php`, `tests/ATest.php`, `bin/console` (sem extensão), `build/B.php` (com `build/`
no `.gitignore`), `.hidden/C.php` e `vendor/x/D.php`, os arquivos formatados são `src/A.php` e `tests/ATest.php`.

Para conferir a lista num projeto:

```sh
php-cs-fixer list-files
```

## Ajustar as regras num projeto

O ponto de partida é sempre a configuração do pacote. Um projeto que precisa mudar algo edita o próprio
`.php-cs-fixer.dist.php` e altera o objeto devolvido, em vez de reescrever as regras:

```php
<?php

require_once __DIR__ . '/vendor/elavora/code-standards/src/PhpCsFixer.php';

$config = Elavora\CodeStandards\PhpCsFixer::config(__DIR__);
$config->getFinder()->notPath('tests/fixtures');

return $config->setRules([...$config->getRules(), 'some_rule' => false]);
```

- `setRules` substitui todas as regras, por isso o exemplo parte de `getRules()`.
- `getFinder()` devolve o `PhpCsFixer\Finder` da configuração, que aceita os métodos do Symfony Finder
  (`notPath`, `exclude`, `append`...).
- Um ajuste assim fica só nesse projeto. Se ele valer para todos, o lugar é este pacote.

O override pessoal, em `.php-cs-fixer.php` (fora do git), parte da configuração do projeto:

```php
<?php

$config = require __DIR__ . '/.php-cs-fixer.dist.php';

return $config->setRules([...$config->getRules(), 'some_rule' => false]);
```

O PHP-CS-Fixer, a extensão do VS Code e o CI usam o `.php-cs-fixer.php` quando ele existe. Como ele nunca é
commitado, o CI usa sempre o `.php-cs-fixer.dist.php`.

---

## Referência: `Elavora\CodeStandards\PhpCsFixer`

Arquivo `src/PhpCsFixer.php`. Classe `final`, com construtor privado e um único método público.

```php
namespace Elavora\CodeStandards;

final class PhpCsFixer
{
    public static function config(string $dir): \PhpCsFixer\ConfigInterface;
}
```

### `config(string $dir): \PhpCsFixer\ConfigInterface`

Devolve uma configuração nova a cada chamada (duas chamadas devolvem objetos diferentes, e alterar um não altera o
outro).

**Validação, nesta ordem:**

1. `$dir` precisa ser um diretório existente (`is_dir`). Caminho vazio, inexistente ou que aponta para um arquivo
   lança `\InvalidArgumentException`, com uma mensagem que contém o valor recebido. Essa checagem vem antes da
   próxima, para que funcione mesmo sem o PHP-CS-Fixer carregado.
2. As classes `PhpCsFixer\Config` e `PhpCsFixer\Finder` precisam existir (com autoload). Se não existirem, lança
   `\RuntimeException` com uma mensagem que contém `php-cs-fixer` e `ghcr.io/elavora/php-dev`.

Nenhuma das duas checagens cria, altera ou apaga arquivos.

**Objeto devolvido**, uma instância de `\PhpCsFixer\Config`:

| Método | Valor |
| --- | --- |
| `getName()` | `'elavora/code-standards'` |
| `getRules()` | `['@PER-CS' => true]`, exatamente |
| `getRiskyAllowed()` | `false` |
| `getUsingCache()` | `false` |
| `getFinder()` | instância de `\PhpCsFixer\Finder` com as regras de [Quais arquivos são formatados](#quais-arquivos-são-formatados) |

Os outros valores (indentação de 4 espaços, fim de linha `"\n"`, execução paralela detectada pelo próprio
PHP-CS-Fixer) ficam no padrão do PHP-CS-Fixer e não são definidos pelo pacote.

O `Finder` equivale a:

```php
\PhpCsFixer\Finder::create()
    ->in($dir)
    ->exclude('vendor')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->ignoreVCSIgnored(true);
```

`ignoreDotFiles(true)` é explícito porque o padrão do `PhpCsFixer\Finder` muda no modo futuro do PHP-CS-Fixer 4.

**O resultado não depende do diretório atual.** Chamar `config('/projeto')` com o diretório atual em `/` ou em
`/projeto/src` gera a mesma lista de arquivos.

### Comportamento observável com o `php-cs-fixer` da imagem

Num projeto de exemplo com o stub na raiz e `src/A.php` contendo `<?php`, quebra de linha, `if(true){echo 1;}` e
quebra de linha:

| Comando (na imagem `ghcr.io/elavora/php-dev:8.3-0.2.0`) | Resultado |
| --- | --- |
| `php-cs-fixer check` na raiz | sai com **8** e cita `src/A.php`; o arquivo não muda |
| `php-cs-fixer fix` na raiz | sai com 0 e `src/A.php` passa a seguir o PER-CS |
| `php-cs-fixer check --config=<raiz>/.php-cs-fixer.dist.php` a partir de `/` | o mesmo resultado do `check` na raiz |
| `php-cs-fixer list-files` na raiz | lista só os arquivos de [Quais arquivos são formatados](#quais-arquivos-são-formatados) |
| qualquer um dos acima | nenhum `.php-cs-fixer.cache` é criado |
| a saída de `php-cs-fixer check -v` | contém `Loaded config elavora/code-standards` |

---

[README](../README.md) · Anterior: [Adoção num projeto](adocao.md) · Próximo: [EditorConfig e o comando `sync`](editorconfig.md)
