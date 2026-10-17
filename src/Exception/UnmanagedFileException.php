<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Exception;

/**
 * O `.editorconfig` tem conteúdo e nenhuma linha marcadora.
 */
final class UnmanagedFileException extends \RuntimeException {}
