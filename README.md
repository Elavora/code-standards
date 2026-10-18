# code-standards

Regras de formatação e estilo compartilhadas pelos projetos PHP da Elavora, distribuídas como o pacote Composer
`elavora/code-standards`.

O pacote traz três coisas:

- a **configuração do PHP-CS-Fixer**, no padrão [PER-CS](https://www.php-fig.org/per/coding-style/), sem regras
  *risky*;
- o **`.editorconfig` base** (indentação, fim de linha, espaços no fim da linha...), que um comando do pacote copia
  para a raiz do projeto;
- o **lint de sintaxe PHP** (`elavora-standards lint`, a partir da 1.1.0), que roda `php -l` nos arquivos do projeto
  e substitui o `tools/lint.php` que cada projeto copiava.

## Por que um pacote

As regras mudam pouco, mas mudam. Como elas são uma dependência Composer comum, o Dependabot abre um PR em cada
projeto quando sai uma versão nova, do mesmo jeito que faz com o PHPUnit ou com qualquer outra dependência. Ninguém
precisa lembrar de copiar arquivos de um repositório para outro.

O pacote entrega as **regras** e o lint, que só precisa do próprio PHP. As **ferramentas** de formatação (o
`php-cs-fixer` e o `editorconfig-checker`) vêm na imagem
`ghcr.io/elavora/php-dev`, usada no devcontainer e no CI, para que o editor e o CI rodem a mesma versão
(veja [Elavora/devcontainers](https://github.com/Elavora/devcontainers/blob/main/docs/ferramentas-de-qualidade.md)).

```
Elavora/code-standards   → regras (este pacote, no require-dev de cada projeto)
Elavora/devcontainers    → imagem php-dev com php-cs-fixer 3.95.27 e editorconfig-checker 4.0.2
Elavora/.github          → workflow de qualidade do CI, que usa as regras e as ferramentas
```

## Uso em poucos passos

1. Instale o pacote como dependência de desenvolvimento:

   ```sh
   composer require --dev elavora/code-standards
   ```

2. Acrescente ao `composer.json` do projeto os scripts que mantêm o `.editorconfig` em dia:

   ```json
   "scripts": {
       "post-install-cmd": ["Elavora\\CodeStandards\\Composer::sync"],
       "post-update-cmd": ["Elavora\\CodeStandards\\Composer::sync"]
   }
   ```

3. Rode `composer install`. O pacote cria o `.editorconfig` e o `.php-cs-fixer.dist.php` na raiz do projeto.
   Commite os dois.

4. Opcional: ligue o lint de sintaxe no script `lint`, que o CI de qualidade roda (o pacote precisa estar em `^1.1`):

   ```json
   "scripts": {
       "lint": "elavora-standards lint"
   }
   ```

Depois disso, `php-cs-fixer fix` (no devcontainer) formata o projeto com as regras da Elavora, e `composer lint`
confere a sintaxe de todos os arquivos PHP. O passo a passo
completo, com o que fazer quando o projeto já tem esses arquivos, está em [docs/adocao.md](docs/adocao.md).

## Documentação

Leia nesta ordem:

1. [Adoção num projeto](docs/adocao.md): instalação, os scripts do `composer.json`, o que commitar e como as
   atualizações chegam.
2. [Configuração do PHP-CS-Fixer](docs/php-cs-fixer.md): as regras, quais arquivos são formatados, por que o pacote
   não depende do `friendsofphp/php-cs-fixer` e como um projeto ajusta uma regra.
3. [EditorConfig e o comando `sync`](docs/editorconfig.md): o `.editorconfig` base, o bloco gerado, o que acontece
   quando o projeto já tem um `.editorconfig` e a referência do comando `vendor/bin/elavora-standards`.
4. [Lint de sintaxe PHP](docs/lint.md): o subcomando `lint`, quais arquivos ele confere, como mudar as pastas, a
   saída, os códigos de saída e a migração do `tools/lint.php`.
5. [Integração com o CI](docs/integracao-ci.md): o contrato que o workflow de qualidade do `Elavora/.github` usa.
6. [Desenvolvimento deste repositório](docs/desenvolvimento.md): estrutura, testes, CI, release, Dependabot e a
   publicação no Packagist.

## Licença

MIT. Veja [LICENSE](LICENSE).
