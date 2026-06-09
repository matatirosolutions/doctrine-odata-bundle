<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use JsonException;
use Matatirosoln\DoctrineOdataBundle\Exception\ScriptException;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Throwable;

/**
 * Executes FileMaker scripts via the OData Script system table.
 *
 * FileMaker OData exposes a virtual entity set named `Script` whose "records"
 * are the scripts in your solution. A POST to `Script.{name}` runs the script
 * server-side and returns its result.
 *
 * Constraints imposed by FileMaker's OData implementation:
 *   - Script names must not contain @, &, or /
 *   - Script names must not begin with a digit
 *   - Scripts must run without user interaction (they execute on the server)
 */
class ScriptService
{
    public function __construct(
        private readonly Connection $connection
    ) { }

    /**
     * Runs a named FileMaker script and returns its result.
     *
     * @param string                 $scriptName The FileMaker script name.
     * @param string|array<mixed>|null $parameter
     *        Value passed as the script's parameter:
     *        - string: used as-is
     *        - array:  JSON-encoded automatically
     *        - null:   no parameter (empty POST body)
     *
     * @return array{code: int, resultParameter: string}
     *         The parsed scriptResult from FileMaker's response.
     *
     * @throws InvalidArgumentException if the script name contains characters
     *         that FileMaker OData does not support.
     * @throws ScriptException           if the script returns a non-zero code.
     * @throws ODataDriverException|JsonException      on HTTP or connectivity errors.
     */
    public function run(string $scriptName, string|array|null $parameter = null): array
    {
        $this->validateScriptName($scriptName);

        $parameterJson = match (true) {
            $parameter === null  => null,
            is_array($parameter) => json_encode($parameter, JSON_THROW_ON_ERROR),
            default              => $parameter,
        };

        $response     = $this->odataConnection()->getClient()->runScript($scriptName, $parameterJson);
        $scriptResult = $response['scriptResult'] ?? ['code' => -1, 'resultParameter' => ''];
        $code         = (int) ($scriptResult['code'] ?? -1);
        $result       = (string) ($scriptResult['resultParameter'] ?? '');

        if ($code !== 0) {
            throw new ScriptException($scriptName, $code, $result);
        }

        return ['code' => $code, 'resultParameter' => $result];
    }

    /**
     * Validates that a script name can be used in an OData URL.
     *
     * @throws InvalidArgumentException
     */
    private function validateScriptName(string $name): void
    {
        if (preg_match('/[@&\/]/', $name)) {
            throw new InvalidArgumentException(
                "Script name \"$name\" contains characters not supported by FileMaker OData (@, &, /).",
            );
        }

        if (preg_match('/^\d/', $name)) {
            throw new InvalidArgumentException(
                "Script name \"$name\" begins with a digit, which is not supported by FileMaker OData.",
            );
        }
    }

    private function odataConnection(): ODataConnection
    {
        try {
            $native = $this->connection->getNativeConnection();
        } catch (Throwable $e) {
            throw new ConnectionException(
                'ScriptService could not obtain the OData connection: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!$native instanceof ODataConnection) {
            throw new ConnectionException(
                'ScriptService requires a DBAL connection backed by ODataDriver. ' .
                'Check the "connection" setting in doctrine_odata.yaml.',
            );
        }

        return $native;
    }

}
