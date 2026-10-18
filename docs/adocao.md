# Adoção num projeto

[README](../README.md) · Próximo: [Configuração do PHP-CS-Fixer](php-cs-fixer.md)

## Visão geral

Um projeto PHP da Elavora (um pacote `api-*` ou uma aplicação criada a partir do `api-skeleton`) adota as regras
em três passos: instala o pacote no `require-dev`, liga os scripts de sincronização no `composer.json` e commita os
dois arquivos que o pacote gera na raiz. Um quarto passo, opcional, liga o lint de sintaxe do pacote no script `lint`.

Depois disso, o projeto fica assim:

| Arquivo na raiz | Quem escreve | Versionado | Para que serve |
| --- | --- | --- | --- |
| `.php-cs-fixer.dist.php` | o pacote cria, uma vez; depois é do projeto | sim | carrega a configuração do PHP-CS-Fixer do pacote |
| `.editorconfig` | o pacote, a cada `composer install`/`update` (só o bloco da Elavora) | sim | regras de indentação, fim de linha e espaços |
| `.php-cs-fixer.php` | quem desenvolve, se quiser | não (no `.gitignore`) | override pessoal, só na máquina da pessoa |

## Passo a passo

### 1. Instalar o pacote

```sh
composer require --dev elavora/code-standards
```

O pacote entra no `require-dev` com a restrição `^1.1` (a do `composer require` de hoje). Ele não deve ir para o
`require`: as regras só servem para desenvolver e para o CI, e a instalação de produção (`composer install --no-dev`)
não precisa dele. Um projeto que ainda está com `^1.0` funciona para a formatação e o `.editorconfig`, mas precisa
passar para `^1.1` antes de usar o lint (veja o [passo 4](#4-ligar-o-lint-opcional)).

O pacote não traz o PHP-CS-Fixer. Não acrescente `friendsofphp/php-cs-fixer` ao `require-dev`: o binário vem da
imagem `ghcr.io/elavora/php-dev`, na mesma versão para todo mundo (veja
[Por que o pacote não depende do PHP-CS-Fixer](php-cs-fixer.md#por-que-o-pacote-não-depende-do-php-cs-fixer)).

### 2. Ligar a sincronização no `composer.json`

Acrescente exatamente estas duas entradas em `scripts`. Se o projeto já tiver `post-install-cmd` ou
`post-update-cmd`, acrescente o item à lista que já existe:

```json
"scripts": {
    "post-install-cmd": ["Elavora\\CodeStandards\\Composer::sync"],
    "post-update-cmd": ["Elavora\\CodeStandards\\Composer::sync"]
}
```

A cada `composer install` ou `composer update`, o Composer chama o método `sync` do pacote, que atualiza o bloco da
Elavora no `.editorconfig` e cria o `.php-cs-fixer.dist.php` se ele ainda não existir. O comportamento completo está
em [EditorConfig e o comando `sync`](editorconfig.md).

**Por que um método PHP, e não `vendor/bin/elavora-standards sync`.** A forma acima é a única recomendada, porque é
a única que não quebra a instalação de produção e funciona igual no Linux e no Windows:

- Com `composer install --no-dev` (por exemplo, no `Dockerfile` de produção do `api-skeleton`), o pacote não é
  instalado, mas o Composer roda o `post-install-cmd` mesmo assim. Um comando como `vendor/bin/elavora-standards
  sync` não existe nesse momento, e a instalação falha. Com o método PHP, o Composer só avisa que a classe não está
  disponível e **segue com código de saída 0**:

  ```
  Class Elavora\CodeStandards\Composer is not autoloadable, can not call post-install-cmd script
  ```

  Essa linha aparece na instalação de produção e é esperada.
- Se o pacote estiver instalado e a instalação for `--no-dev` (por exemplo, alguém colocou o pacote no `require`
  por engano), o método sai sem fazer nada e sem escrever nada.
- O método roda dentro do próprio processo do Composer, sem shell, sem caminho com `/` ou `\` e sem depender de
  `vendor/bin` estar no `PATH`. Por isso o mesmo `composer.json` funciona no devcontainer, no CI e num Composer
  instalado direto no Windows.
- Um problema na sincronização (por exemplo, o `.editorconfig` com o bloco quebrado) nunca faz o `composer install`
  falhar. O método mostra o erro e deixa o arquivo como está. O comando `vendor/bin/elavora-standards sync`, usado
  à mão, é que sai com erro nesses casos.

`composer install --no-scripts` pula a sincronização, como qualquer outro script.

### 3. Rodar `composer install` e commitar

```sh
composer install
```

Na primeira vez, num projeto sem nenhum dos dois arquivos, a saída inclui:

```
> Elavora\CodeStandards\Composer::sync
elavora/code-standards: .editorconfig criado
elavora/code-standards: .php-cs-fixer.dist.php criado
```

Commite os dois arquivos. Acrescente ao `.gitignore`:

```
.php-cs-fixer.php
```

O `.php-cs-fixer.php` é o override pessoal (veja [Ajustar as regras](php-cs-fixer.md#ajustar-as-regras-num-projeto)).
A configuração do pacote não usa cache, então não existe `.php-cs-fixer.cache` para ignorar.

### 4. Ligar o lint (opcional)

Para conferir a sintaxe de todos os arquivos PHP do projeto com `composer lint` (que o CI de qualidade roda quando o
script existe), acrescente em `scripts`:

```json
"scripts": {
    "lint": "elavora-standards lint"
}
```

Sem argumentos, o lint confere o projeto inteiro, menos `vendor/` e o que começa com ponto. Se o projeto tinha um
`tools/lint.php`, apague-o. Pastas, exclusões e a migração estão em [Lint de sintaxe PHP](lint.md).

### Quando o projeto já tem os arquivos

- **`.php-cs-fixer.dist.php` já existe:** o pacote nunca mexe nele. Troque o conteúdo à mão pelo
  [stub](php-cs-fixer.md#o-stub-php-cs-fixerdistphp), mantendo os ajustes que o projeto precisar.
- **`.editorconfig` já existe, sem o bloco da Elavora:** o pacote não sobrescreve o arquivo e avisa:

  ```
  elavora/code-standards: .editorconfig mantido: o arquivo não tem o bloco do elavora/code-standards. Rode "vendor/bin/elavora-standards sync --force" para substituí-lo pela base.
  ```

  Rode o comando sugerido uma vez, confira o resultado no `git diff` e, se o projeto tinha seções próprias, coloque
  essas seções depois da linha `# <<< elavora/code-standards <<<` (veja
  [Seções próprias do projeto](editorconfig.md#seções-próprias-do-projeto)).

## No dia a dia

Dentro do devcontainer (imagem `ghcr.io/elavora/php-dev:8.3-0.2.0` ou mais nova), na raiz do projeto:

```sh
php-cs-fixer fix                                  # formata com a configuração do pacote
php-cs-fixer check                                # só confere, sem alterar (sai com 8 se houver o que corrigir)
git ls-files -z | xargs -0 editorconfig-checker   # confere os arquivos versionados contra o .editorconfig
composer lint                                     # confere a sintaxe dos arquivos PHP (com o passo 4)
```

No VS Code do devcontainer, a extensão do PHP-CS-Fixer encontra o `.php-cs-fixer.dist.php` na raiz e formata ao
salvar.

## Como as atualizações chegam

1. Sai uma versão nova do `elavora/code-standards` (por exemplo, `1.1.0`, com uma seção nova no `.editorconfig`).
2. O Dependabot abre um PR no projeto atualizando o pacote. O Dependabot roda o Composer sem scripts, então o PR
   muda só o `composer.json`/`composer.lock`, e não o `.editorconfig`.
3. No CI desse PR, o `composer install` roda a sincronização e o `.editorconfig` muda na árvore de trabalho. O
   workflow de qualidade trata essa diferença (veja [Integração com o CI](integracao-ci.md#o-editorconfig-muda-no-ci)).
4. Quem integra o PR roda `composer install` na branch e commita o `.editorconfig` atualizado, se o CI não tiver
   commitado.

Uma melhoria no lint também chega pelo mesmo PR do Dependabot, sem mudar nenhum arquivo do projeto: o script `lint`
só chama o comando do pacote.

Uma versão nova das regras do PHP-CS-Fixer (uma regra a mais, por exemplo) não muda nenhum arquivo do projeto: o
`.php-cs-fixer.dist.php` só carrega a configuração do pacote. O efeito aparece na próxima formatação, que pode gerar
um commit `style:`.

As versões do pacote seguem o versionamento da organização: uma mudança que altera a formatação de código já
formatado é, no mínimo, minor (veja [Desenvolvimento](desenvolvimento.md#o-que-muda-a-versão)).

---

[README](../README.md) · Próximo: [Configuração do PHP-CS-Fixer](php-cs-fixer.md)
