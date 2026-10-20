<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

/**
 * Configuração do PHP-CS-Fixer da Elavora.
 *
 * Este arquivo é carregado direto pelo stub `.php-cs-fixer.dist.php`, sem o autoloader do Composer, e por isso não
 * pode depender de nenhuma outra classe do pacote.
 */
final class PhpCsFixer
{
    private function __construct() {}

    public static function config(string $dir): \PhpCsFixer\ConfigInterface
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException(\sprintf('O diretório "%s" não existe ou não é um diretório.', $dir));
        }

        if (!class_exists(\PhpCsFixer\Config::class) || !class_exists(\PhpCsFixer\Finder::class)) {
            throw new \RuntimeException(
                'As classes do PHP-CS-Fixer não estão carregadas. A configuração do elavora/code-standards precisa '
                . 'ser carregada pelo php-cs-fixer da imagem ghcr.io/elavora/php-dev (por exemplo, "php-cs-fixer fix").',
            );
        }

        // Os métodos do Finder alteram o próprio objeto. Eles são chamados um a um porque, no PHAR do php-cs-fixer, não
        // têm tipo de retorno e o PHPStan não sabe que devolvem o mesmo Finder.
        $finder = new \PhpCsFixer\Finder();
        $finder->in($dir);
        $finder->exclude('vendor');
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->ignoreVCSIgnored(true);

        return (new \PhpCsFixer\Config('elavora/code-standards'))
            ->setRules(['@PER-CS' => true])
            ->setRiskyAllowed(false)
            ->setUsingCache(false)
            ->setFinder($finder);
    }
}
