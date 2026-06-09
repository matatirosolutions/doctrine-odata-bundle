<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Exception;

use RuntimeException;

/**
 * Thrown when an OData bundle service cannot obtain or validate the underlying
 * OData driver connection.
 *
 * Common causes:
 *   - The configured DBAL connection is not backed by ODataDriver
 *   - The connection could not be established (network, credentials, etc.)
 *   - The OData $metadata endpoint was unreachable during lazy initialisation
 */
class ConnectionException extends RuntimeException
{
}
