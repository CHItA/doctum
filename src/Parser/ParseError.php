<?php

/*
 * This file is part of the Doctum utility.
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Doctum\Parser;

use CodeLts\CliTools\Error;

final class ParseError extends Error
{

    public function __toString()
    {
        return sprintf('%s in %s:%d', $this->getMessage(), $this->getFile() ?? '', $this->getLine());
    }
}