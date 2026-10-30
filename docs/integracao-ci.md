# Integração com o CI

[README](../README.md) · Anterior: [Base do PHPStan](phpstan.md) · Próximo: [Desenvolvimento deste repositório](desenvolvimento.md)

## Visão geral

O workflow de qualidade central do `Elavora/.github` (`php-quality.yml`, a partir da versão 1.1.0) formata e
confere os projetos PHP em cada PR. Ele não guarda regras próprias: usa as deste pacote, instaladas no `vendor/` do
projeto, e as ferramentas da imagem `ghcr.io/elavora/php-dev`.

```
PR no projeto ──► php-quality.yml (Elavora/.github)
                    ├─ imagem ghcr.io/elavora/php-dev:8.3-latest → php-cs-fixer e editorconfig-checker
                    ├─ composer install                         → instala elavora/code-standards e roda o sync
                    ├─ php-cs-fixer                             → lê .php-cs-fixer.dist.php → config do pacote
                    └─ editorconfig-checker                     → lê .editorconfig (bloco gerado pelo pacote)
```

Esta página é o contrato entre os dois repositórios. Uma mudança em qualquer item abaixo é uma mudança incompatível
do pacote (versão major, label `upgrade`) e precisa ser combinada com o `Elavora/.github`.

## O contrato

| Item | Valor |
| --- | --- |
| Nome do pacote | `elavora/code-standards`, no Packagist, instalado no `require-dev` com `^1.0` |
| Arquivo de configuração do PHP-CS-Fixer | `.php-cs-fixer.dist.php` na raiz do projeto, com o [stub](php-cs-fixer.md#o-stub-php-cs-fixerdistphp) |
| Classe e método da configuração | `Elavora\CodeStandards\PhpCsFixer::config(string $dir): PhpCsFixer\ConfigInterface`, em `vendor/elavora/code-standards/src/PhpCsFixer.php` |
| Arquivo do EditorConfig | `.editorconfig` na raiz do projeto, com o bloco entre `# >>> elavora/code-standards >>>` e `# <<< elavora/code-standards <<<` |
| Sincronização no `composer.json` do projeto | `"post-install-cmd": ["Elavora\\CodeStandards\\Composer::sync"]` e o mesmo em `"post-update-cmd"` |
| Comando de sincronização | `vendor/bin/elavora-standards sync` (códigos de saída 0, 1 e 2, veja a [referência](editorconfig.md#comando-vendorbinelavora-standards)) |
| Arquivos que a sincronização pode criar ou alterar | só `.editorconfig` e `.php-cs-fixer.dist.php`, na raiz |
| Configuração do `editorconfig-checker` | o pacote não traz `.editorconfig-checker.json` |
| Versão do PHP-CS-Fixer de referência | a da imagem `ghcr.io/elavora/php-dev:8.3-latest`, que o workflow usa (as versões das ferramentas estão no [Elavora/devcontainers](https://github.com/Elavora/devcontainers/blob/main/docs/ferramentas-de-qualidade.md)) |
| Lint de sintaxe (a partir da 1.1.0) | o script `lint` do projeto, `"lint": "elavora-standards lint"`, que o workflow roda como `composer lint` quando o script existe; códigos de saída 0, 1 e 2 (veja a [referência](lint.md#referência)); exige o pacote em `^1.1` |
| Base do PHPStan (a partir da 1.2.0) | `vendor/elavora/code-standards/resources/phpstan.neon`, incluída pelo arquivo do PHPStan do projeto; o workflow roda `composer analyse` quando o script existe; exige o pacote em `^1.2` (veja a [referência](phpstan.md#referência)) |
| Pastas que a configuração do PHP-CS-Fixer ignora | `vendor/` (qualquer nível), tudo que começa com ponto (inclusive `.elavora/`), o que o `.gitignore` ignora |

## O que o workflow precisa respeitar

1. **`composer install` antes do PHP-CS-Fixer.** O stub carrega um arquivo de dentro de
   `vendor/elavora/code-standards/`. Sem o `vendor/`, o `php-cs-fixer` falha ao carregar a configuração. A ordem é:
   checkout, `composer install` (que já roda a sincronização), PHP-CS-Fixer, `editorconfig-checker`, o resto.
2. **Rodar a partir da raiz do projeto, ou passar `--config`.** A configuração usa a pasta do stub, então
   `php-cs-fixer fix --config=<raiz>/.php-cs-fixer.dist.php` funciona de qualquer pasta. O PHP-CS-Fixer só encontra o
   arquivo sozinho quando roda na raiz.
3. **Não passar `--rules`, `--allow-risky` nem `--using-cache` na linha de comando.** Eles substituem o que a
   configuração do pacote define.
4. **Projeto que ainda não adotou o pacote.** Sem `vendor/elavora/code-standards/` depois do `composer install`, não
   existe regra base central para cair: o workflow decide se pula a formatação com um aviso ou se usa `--rules=@PER-CS`
   só naquela execução. A base antiga em `Elavora/.github/standards/` deixa de existir.
5. **Instalar com scripts.** `composer install --no-scripts` pula a sincronização. Se o workflow precisar disso por
   outro motivo, ele roda `vendor/bin/elavora-standards sync` logo depois.
6. **O lint é do projeto.** O workflow continua rodando `composer lint` só quando o script existe, e não chama
   `elavora-standards lint` direto: as pastas e as exclusões ficam na linha do script de cada projeto. Como o lint
   roda com as dependências nas versões mínimas, o projeto precisa do pacote em `^1.1` (veja
   [Uso num projeto](lint.md#uso-num-projeto)).
7. **O PHPStan também é do projeto.** O workflow roda `composer analyse` só quando o script existe, como já faz
   hoje. Ele não passa `--level` nem `--configuration` na linha de comando: o nível vem da base, e o arquivo é o do
   projeto. Nada muda no `php-quality.yml` para a base funcionar. A base foi conferida com as versões mínimas das três
   dependências, que é como o workflow instala.

### O `.editorconfig` muda no CI

Num PR em que o pacote foi atualizado (o caso típico é o PR do Dependabot, que roda o Composer sem scripts), o
`composer install` do CI reescreve o bloco do `.editorconfig` na árvore de trabalho. A sincronização também cria o
`.php-cs-fixer.dist.php` e o `.editorconfig` num projeto que ainda não tinha os arquivos. Os dois arquivos não estão
no `.gitignore`, então a mudança aparece no `git status`.

Recomendação para o `php-quality.yml`: tratar esses dois arquivos como parte da correção automática. Onde o workflow
commita a formatação (`style: organiza o código`), ele inclui o `.editorconfig` e o `.php-cs-fixer.dist.php`
alterados no mesmo commit. Onde ele só avisa (fork, Dependabot, `main`), o aviso cita os dois arquivos. O
`editorconfig-checker` roda sobre a árvore já sincronizada, para conferir com as regras da versão instalada do
pacote.

### Notificação do Packagist

Na `1.0.0` do `.github`, o job do Packagist (`notifyPackagist`) só aceitava repositórios `Elavora/api-*`. A partir
da `1.1.0`, ele aceita também `Elavora/code-standards`. Este repositório chama o release da `1.1.0` com
`packagist: true` (o padrão), e cada release avisa o Packagist (veja [Desenvolvimento](desenvolvimento.md#release)).

---

[README](../README.md) · Anterior: [Base do PHPStan](phpstan.md) · Próximo: [Desenvolvimento deste repositório](desenvolvimento.md)
