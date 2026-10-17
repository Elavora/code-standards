<?php

declare(strict_types=1);

namespace Elavora\CodeStandards;

/**
 * Resultado da sincronização de um arquivo.
 */
enum Status
{
    case Created;
    case Updated;
    case Unchanged;
    case Skipped;
}
