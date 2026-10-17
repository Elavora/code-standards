# Desenvolvimento deste repositório

[README](../README.md) · Anterior: [Integração com o CI](integracao-ci.md)

## Visão geral

Este repositório é o próprio pacote: o código PHP fica em `src/`, a base do EditorConfig em `resources/` e o comando
em `bin/`. Ele segue as regras que distribui (o `.editorconfig` dele é gerado pelo próprio `sync`, e o PHP-CS-Fixer
confere o código dele com a própria configuração). Nada é instalado na máquina: os testes rodam na imagem
`ghcr.io/elavora/php-dev:8.3-0.2.0`, a mesma do CI.

## Estrutura

| Caminho | Conteúdo | Vai no pacote |
| --- | --- | --- |
| `src/PhpCsFixer.php` | `Elavora\CodeStandards\PhpCsFixer` ([referência](php-cs-fixer.md#referência-elavoracodestandardsphpcsfixer)) | sim |
| `src/EditorConfig.php`, `src/Sync.php`, `src/Status.php`, `src/Composer.php`, `src/Exception/` | a sincronização ([referência](editorconfig.md#referência)) | sim |
| `src/Cli.php` (ou outro nome interno) | a lógica do comando, se o dev quiser separá-la do `bin/` | sim |
| `resources/editorconfig` | o `.editorconfig` base | sim |
| `bin/elavora-standards` | o comando `sync` | sim |
| `tests/` | testes PHPUnit | não |
| `docs/`, `README.md` | esta documentação | não (`docs/`) |
| `.github/` | workflows e `dependabot.yml` | não |
| `.editorconfig` | gerado pelo `sync` deste repositório, commitado | não |
| `.php-cs-fixer.dist.php` | configuração do PHP-CS-Fixer deste repositório | não |
| `phpunit.xml.dist`, `.gitignore`, `.gitattributes` | configuração | não |
| `LICENSE` | MIT, `Copyright (c) 2026 Elavora`, o mesmo texto do `api-framework` | sim |

"Vai no pacote" quer dizer que o arquivo está no zip que o Composer baixa. O que não vai é marcado com
`export-ignore` no `.gitattributes`. A primeira linha força LF na cópia de trabalho, inclusive num Windows com
`core.autocrlf=true`, para que o `editorconfig-checker` (`end_of_line = lf`) e os testes deem o mesmo resultado
dentro e fora do CI:

```
* text=auto eol=lf
/.editorconfig export-ignore
/.gitattributes export-ignore
/.github export-ignore
/.gitignore export-ignore
/.php-cs-fixer.dist.php export-ignore
/docs export-ignore
/phpunit.xml.dist export-ignore
/tests export-ignore
```

`src/`, `resources/`, `bin/`, `composer.json`, `README.md` e `LICENSE` nunca podem ter `export-ignore`: o pacote
instalado precisa deles.

`.gitignore`:

```
.php-cs-fixer.php
.phpunit.cache/
composer.lock
vendor/
```

O `composer.lock` fica fora do git, como nos outros pacotes da organização: um pacote não fixa as versões das
dependências de quem o instala.

### `composer.json`

```json
{
    "name": "elavora/code-standards",
    "description": "Regras de formatação e estilo da Elavora: PHP-CS-Fixer (PER-CS) e EditorConfig",
    "type": "library",
    "license": "MIT",
    "keywords": ["coding-standard", "editorconfig", "php-cs-fixer", "per-cs"],
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.0"
    },
    "autoload": {
        "psr-4": {
            "Elavora\\CodeStandards\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Elavora\\CodeStandards\\Tests\\": "tests/"
        }
    },
    "bin": ["bin/elavora-standards"],
    "scripts": {
        "post-install-cmd": ["Elavora\\CodeStandards\\Composer::sync"],
        "post-update-cmd": ["Elavora\\CodeStandards\\Composer::sync"],
        "test": "phpunit"
    },
    "config": {
        "sort-packages": true
    }
}
```

- `require` tem só o PHP. Em especial, **não** tem `friendsofphp/php-cs-fixer` nem `composer/composer` (veja
  [Por que o pacote não depende do PHP-CS-Fixer](php-cs-fixer.md#por-que-o-pacote-não-depende-do-php-cs-fixer)).
- Os scripts `post-install-cmd` e `post-update-cmd` são os mesmos que os projetos usam: aqui o pacote sincroniza o
  próprio `.editorconfig`.
- `composer validate --strict --no-check-lock` passa sem avisos.

### Configuração do PHP-CS-Fixer deste repositório

O caminho dentro do `vendor/` não existe aqui, então o `.php-cs-fixer.dist.php` carrega a classe direto de `src/`:

```php
<?php

require_once __DIR__ . '/src/PhpCsFixer.php';

return Elavora\CodeStandards\PhpCsFixer::config(__DIR__);
```

O `sync` nunca reescreve esse arquivo, porque ele já existe.

## Testes

### Como rodar

Na raiz do repositório, no PowerShell (no Git Bash, a conversão de caminhos quebra o `docker run`):

```powershell
docker run --rm -v "${PWD}:/work" -w /work ghcr.io/elavora/php-dev:8.3-0.2.0 sh -c "composer install --no-interaction && composer test"
```

No devcontainer, basta `composer install` e `composer test`.

### O que precisa ser coberto

Os testes usam PHPUnit, ficam em `tests/` e partem desta documentação:

- `EditorConfig::base()`, `managedBlock()` e `merge()`, com os exemplos da [tabela de `merge`](editorconfig.md#elavoracodestandardseditorconfig).
- `Sync::run()` em pastas temporárias (em `sys_get_temp_dir()`, apagadas no fim), com cada linha das tabelas de
  [A sincronização](editorconfig.md#a-sincronização), incluindo "nada é escrito" (conteúdo e data de modificação
  iguais).
- O comando `bin/elavora-standards`, como processo (`php bin/elavora-standards ...`), conferindo código de saída,
  saída padrão e saída de erro de cada linha da [tabela do comando](editorconfig.md#comando-vendorbinelavora-standards).
- `Composer::sync()` com um objeto falso no lugar do `Composer\Script\Event` (com `isDevMode()` e `getIO()`), já que
  o tipo do parâmetro é `object`.
- A integração com o Composer de verdade (o da imagem), num projeto temporário que instala este repositório por um
  repositório `path`, sem acesso à rede:

  ```json
  {
      "name": "elavora/projeto-de-teste",
      "require-dev": { "elavora/code-standards": "*@dev" },
      "repositories": [
          { "type": "path", "url": "<caminho deste repositório>" },
          { "packagist.org": false }
      ],
      "scripts": {
          "post-install-cmd": ["Elavora\\CodeStandards\\Composer::sync"],
          "post-update-cmd": ["Elavora\\CodeStandards\\Composer::sync"]
      }
  }
  ```

  Casos: `composer update` cria os dois arquivos e sai com 0; `composer install --no-dev` (a partir do lock gerado,
  numa cópia sem `vendor/` e sem os dois arquivos) sai com 0, não cria nenhum dos dois e mostra o aviso
  `is not autoloadable`; `composer -d <projeto> install` a partir de outra pasta sincroniza o projeto, e não a pasta
  atual.
- `PhpCsFixer::config()` com o `php-cs-fixer` da imagem, conforme
  [Comportamento observável](php-cs-fixer.md#comportamento-observável-com-o-php-cs-fixer-da-imagem), num projeto
  temporário com o stub.

**Carregar as classes do PHP-CS-Fixer num teste.** Fora do `php-cs-fixer`, as classes `PhpCsFixer\*` não existem.
Para inspecionar o objeto de `config()` (regras, cache, lista de arquivos), o teste roda um processo PHP separado
que carrega o autoloader de dentro do PHAR da imagem:

```php
Phar::loadPhar('/usr/local/bin/php-cs-fixer', 'php-cs-fixer.phar');
require 'phar://php-cs-fixer.phar/vendor/autoload.php';
```

O nome `php-cs-fixer.phar` é o alias: o arquivo da imagem não tem a extensão `.phar`, e sem o alias o PHP não o abre
como PHAR. Isso precisa ficar num **processo separado**, e não no processo do PHPUnit: o PHAR traz pacotes que o
PHPUnit também usa (como `sebastian/diff`), sem trocar o namespace, e os dois autoloaders no mesmo processo podem
misturar versões. No processo do PHPUnit, sem o PHAR, `config()` serve para testar as exceções
(`InvalidArgumentException` e `RuntimeException`).

**Fixtures.** O próprio repositório passa pelo `php-cs-fixer check` e pelo `editorconfig-checker` no CI. Conteúdo
com formatação proposital (TAB, CRLF, PHP fora do PER-CS) fica em strings dentro dos testes, escrito em pastas
temporárias durante o teste, e não em arquivos versionados.

### CI

Arquivo `.github/workflows/test.yml`:

```yaml
name: Testes

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read

jobs:
  test:
    name: PHPUnit, formatação e actionlint
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Testes, formatação e EditorConfig
        run: |
          docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
            -v "$PWD:/work" -w /work ghcr.io/elavora/php-dev:8.3-0.2.0 sh -c '
              set -e
              composer validate --strict --no-check-lock
              composer install --no-interaction --no-progress
              git diff --exit-code -- .editorconfig .php-cs-fixer.dist.php
              composer test
              php-cs-fixer check --show-progress=none
              git ls-files -z | xargs -0 editorconfig-checker
            '

      - name: actionlint
        run: docker run --rm -v "$PWD:/work:ro" -w /work rhysd/actionlint:1.7.12 -color
```

- O `--user` com o usuário do runner deixa o container escrever o `vendor/` na pasta do checkout, e o `git` dentro
  dele não reclama do dono da pasta.
- O `git diff --exit-code`, logo depois do `composer install`, garante que o `.editorconfig` commitado é o que o
  `sync` gera com o código da própria branch: quem muda `resources/editorconfig` ou o bloco gerado precisa commitar
  o `.editorconfig` atualizado.
- A imagem fica numa versão fixa. O Dependabot não atualiza imagens usadas em `docker run`; a troca é manual, junto
  com a dos outros repositórios.

Na versão 1.0.0, este repositório não usa o workflow de qualidade central (`php-quality.yml`), porque esse workflow
depende deste pacote já publicado no Packagist. A adoção fica para um PR depois da primeira publicação.

## Automação do repositório

Os chamadores usam o `Elavora/.github` **1.1.0**, que descobre sozinho a própria versão (veja
[Versão da automação](https://github.com/Elavora/.github/blob/main/docs/versao-da-automacao.md)): a versão aparece uma
vez só, no `uses`, sem `automation_ref`, e o job chamador concede `id-token: write`. Assim, o Dependabot consegue
manter os chamadores atualizados sozinho, mexendo só no `uses`.

> **Versão do `.github`: conforme `.github` 1.1.0.** Enquanto esta documentação foi escrita, a tag `1.1.0` ainda
> não existia. Os chamadores usam `@1.1.0` e este repositório só pode ser integrado depois que essa tag sair. Se a
> versão estável que sair tiver outro número, troque-o nos dois chamadores.

### Release

Arquivo `.github/workflows/release.yml`, o chamador dos pacotes descrito no
[`Elavora/.github`](https://github.com/Elavora/.github/blob/main/docs/release.md#pacotes-api-), com o nome deste
pacote:

```yaml
name: Release

on:
  pull_request:
    types: [closed]
    branches: [main]

permissions:
  contents: read

jobs:
  release:
    if: github.event.pull_request.merged == true
    permissions:
      contents: write
      pull-requests: read
      id-token: write
    uses: Elavora/.github/.github/workflows/release.yml@1.1.0
    with:
      package_name: elavora/code-standards
    secrets:
      PACKAGIST_WEBHOOK_URL: ${{ secrets.PACKAGIST_WEBHOOK_URL }}
```

- Sem a entrada `automation_ref`: o workflow descobre a versão pelo token OIDC, e por isso o job concede
  `id-token: write`.
- `packagist` fica no padrão (`true`): a cada release, o Packagist é avisado para ler a tag nova. A partir da 1.1.0,
  o `.github` aceita o repositório `Elavora/code-standards` na notificação, além dos `Elavora/api-*` (veja
  [Notificação do Packagist](integracao-ci.md#notificação-do-packagist)).

### Labels

Arquivo `.github/workflows/sync-labels.yml`, o chamador padrão do
[`Elavora/.github`](https://github.com/Elavora/.github/blob/main/docs/labels.md#workflow-chamador-em-cada-repositório):

```yaml
name: Sincronizar labels

on:
  schedule:
    - cron: '17 3 * * 1'
  workflow_dispatch:

permissions:
  contents: read

jobs:
  sync:
    permissions:
      contents: read
      issues: write
      id-token: write
    uses: Elavora/.github/.github/workflows/sync-labels.yml@1.1.0
```

### Dependabot

Arquivo `.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /
    schedule:
      interval: weekly
    labels: [dependencies]
    commit-message:
      prefix: chore
      prefix-development: chore
      include: scope

  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
    labels: [dependencies]
    commit-message:
      prefix: ci
```

- `labels: [dependencies]` evita que o Dependabot crie as labels de ecossistema (`php`, `github_actions`), que a
  sincronização de labels apagaria.
- O prefixo segue o Conventional Commits (`chore(deps): ...`, `chore(deps-dev): ...`, `ci: ...`). O resto da mensagem
  é escrito pelo Dependabot, em inglês ("bump X from A to B"), e não é configurável.
- O ecossistema `github-actions` atualiza o `actions/checkout` e a versão dos workflows do `.github` no `uses`
  (`@1.1.0`). Como os chamadores não têm `automation_ref`, não sobra nada para atualizar à mão.

## Versões

### O que muda a versão

As versões saem automaticamente das labels do PR (veja
[Regras de versão](https://github.com/Elavora/.github/blob/main/docs/versionamento.md)). Para este pacote:

| Mudança | Label | Exemplo |
| --- | --- | --- |
| algo do [contrato](integracao-ci.md#o-contrato) muda de forma incompatível | `upgrade` (major) | trocar o caminho do stub, os marcadores, o nome do callback, a assinatura de `config()` |
| a formatação de código já formatado muda | `enhancement` (minor) | uma regra nova no PHP-CS-Fixer, uma seção nova ou um valor novo no `.editorconfig` |
| funcionalidade nova compatível | `enhancement` (minor) | uma opção nova no comando |
| correção sem mudar a formatação | `bug` (patch) | uma mensagem errada, um caso de borda do `merge` |
| só documentação, testes, CI | `documentation`, `tests`... (patch) | |

Uma mudança que altera a formatação vira minor, e não patch, para que um projeto possa fixar `~1.2.0` se quiser
evitar commits `style:` inesperados. O Dependabot propõe as versões novas dentro de `^1.0` normalmente.

### Primeira versão

O repositório não tem tags. O PR que cria o pacote leva as labels `upgrade` e `release` e, no merge, gera a
`1.0.0` (sem tag anterior, a base é `0.0.0`; `upgrade` leva a `1.0.0` e `release` a torna estável).

### Publicação no Packagist (ação manual, uma vez)

O Packagist só conhece o pacote depois que alguém o cadastra. Depois do merge do primeiro PR e da criação da tag
`1.0.0`:

1. Entre em <https://packagist.org> com a conta que mantém os pacotes `elavora/*`.
2. Em **Submit**, informe `https://github.com/Elavora/code-standards` e confirme. O Packagist lê o `composer.json` e
   importa as tags existentes; a `1.0.0` aparece como versão estável.
3. Confira que o pacote está como `elavora/code-standards` em <https://packagist.org/packages/elavora/code-standards>.
4. No GitHub, em **Organization settings > Secrets and variables > Actions**, confira se o secret
   `PACKAGIST_WEBHOOK_URL` dá acesso ao repositório `code-standards` (quando o acesso é por repositórios
   selecionados, ele precisa ser acrescentado).
5. Ignore a falha do job `packagist` na release `1.0.0`, se acontecer: o merge cria a tag antes do cadastro, e o
   Packagist ainda não conhece o pacote nesse momento. O cadastro do passo 2 já importa a tag. Da versão seguinte
   em diante, o job `packagist` avisa o Packagist sozinho.

Até o cadastro, `composer require --dev elavora/code-standards` falha nos projetos com "could not find package".

---

[README](../README.md) · Anterior: [Integração com o CI](integracao-ci.md)
