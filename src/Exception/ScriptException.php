<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Exception;

use RuntimeException;

/**
 * Thrown when a FileMaker script returns a non-zero result code.
 *
 * FileMaker scripts communicate success/failure via an integer code returned
 * by the Exit Script step. Code 0 means success; any other value is an error
 * whose meaning is defined by the script author.
 */
class ScriptException extends RuntimeException
{
    public function __construct(
        public readonly string $scriptName,
        public readonly int    $scriptCode,
        public readonly string $resultParameter,
    ) {
        parent::__construct(
            sprintf(
                'FileMaker script "%s" returned error code %d%s',
                $scriptName,
                $scriptCode,
                $resultParameter !== '' ? ': ' . $resultParameter : '',
            ),
            $scriptCode,
        );
    }
}
