<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Throwable;

/**
 * Fetches FileMaker value lists via OData with two-layer caching.
 *
 * FileMaker exposes value list names in the $metadata endpoint (as EnumType
 * elements), but the actual entries are served via dedicated entity-set
 * endpoints named FileMaker_ValueList_{name}.
 *
 * Each value list entry has:
 *   FirstValues  — the stored value (always present)
 *   SecondValues — the display label (present for dynamic/related lists only)
 *
 * The methods return associative arrays in the format expected by Symfony's
 * ChoiceType: ['Display Label' => 'stored_value'].
 *
 * Caching strategy
 * ----------------
 * 1. Request-scoped in-memory cache — always active. A list fetched once
 *    within a PHP request is never re-fetched in that same request.
 *
 * 2. Session cache — when a Symfony session is active, fetched lists are
 *    stored in the session and reused across page loads for the lifetime of
 *    that session. This avoids N × OData calls on every form reload without
 *    requiring a shared application cache that is hard to invalidate.
 *    Falls back gracefully when no session exists (API requests, CLI).
 *
 * Invalidation
 * ------------
 * Call clearCache() to remove one or all lists from both caches. The user
 * can also clear by logging out (session destruction). For application-wide
 * invalidation when a FileMaker admin changes a value list, call clearCache()
 * in whatever hook or event your application uses for that purpose.
 */
class ValueListService
{
    private const string SESSION_KEY_PREFIX = '_odata_vl_';

    /**
     * Request-scoped in-memory cache.
     * @var array<string, array<string, string>>
     */
    private array $requestCache = [];

    public function __construct(
        private readonly Connection   $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Returns the names of all value lists defined in the database.
     *
     * Sourced from the cached $metadata — no additional HTTP request is made.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->odataConnection()->getValueLists();
    }

    /**
     * Fetches all value lists and returns their entries.
     *
     * Results are cached per list — only lists not already in cache will
     * trigger an HTTP request.
     *
     * @return array<string, array<string, string>>
     *         ['ListName' => ['Display Label' => 'stored_value', ...], ...]
     *
     * @throws ODataDriverException on HTTP errors.
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->names() as $name) {
            $result[$name] = $this->get($name);
        }
        return $result;
    }

    /**
     * Returns the entries for a single named value list.
     *
     * Checks the request cache first, then the session cache, then fetches
     * from the OData server. Results are stored in both caches on a miss.
     *
     * Suitable for direct use as Symfony ChoiceType `choices`:
     *
     *   $builder->add('assignee', ChoiceType::class, [
     *       'choices' => $valueLists->get('Users'),
     *   ]);
     *
     * @return array<string, string>  ['Display Label' => 'stored_value', ...]
     *
     * @throws InvalidArgumentException if the list name is not in $metadata.
     * @throws ODataDriverException      on HTTP errors.
     */
    public function get(string $name): array
    {
        // 1. Request-scoped cache
        if (isset($this->requestCache[$name])) {
            return $this->requestCache[$name];
        }

        // 2. Session cache
        $session = $this->session();
        if ($session !== null) {
            $sessionKey = self::SESSION_KEY_PREFIX . $name;
            $cached     = $session->get($sessionKey);
            if (is_array($cached)) {
                $this->requestCache[$name] = $cached;
                return $cached;
            }
        }

        // 3. Fetch from OData
        $result = $this->fetchFromOData($name);

        // Populate caches
        $this->requestCache[$name] = $result;
        $session?->set(self::SESSION_KEY_PREFIX . $name, $result);

        return $result;
    }

    /**
     * Alias for get() — for readability when passing directly to ChoiceType.
     *
     * @return array<string, string>  ['Display Label' => 'stored_value', ...]
     * @throws ODataDriverException
     */
    public function choices(string $name): array
    {
        return $this->get($name);
    }

    /**
     * Removes cached value list data.
     *
     * @param string|null $name  List name to clear, or null to clear all lists.
     */
    public function clearCache(?string $name = null): void
    {
        $session = $this->session();

        if ($name !== null) {
            unset($this->requestCache[$name]);
            $session?->remove(self::SESSION_KEY_PREFIX . $name);
            return;
        }

        // Clear all — remove every known list from both caches
        foreach (array_keys($this->requestCache) as $key) {
            $session?->remove(self::SESSION_KEY_PREFIX . $key);
        }
        $this->requestCache = [];

        // Also sweep the session for any lists not in the request cache
        if ($session !== null) {
            foreach (array_keys($session->all()) as $key) {
                if (str_starts_with((string) $key, self::SESSION_KEY_PREFIX)) {
                    $session->remove($key);
                }
            }
        }
    }

    /**
     * Fetches a single value list from the OData server, bypassing all caches.
     *
     * @return array<string, string>
     * @throws InvalidArgumentException if the list name is not in $metadata.
     * @throws ODataDriverException      on HTTP errors.
     */
    private function fetchFromOData(string $name): array
    {
        $names = $this->names();
        if (!in_array($name, $names, true)) {
            throw new InvalidArgumentException(
                "Value list \"$name\" not found in OData \$metadata. " .
                'Available lists: ' . (empty($names) ? '(none)' : implode(', ', $names)),
            );
        }

        $entitySet = 'FileMaker_ValueList_' . $name;
        $response  = $this->odataConnection()->getClient()->get($entitySet);

        return $this->parseEntries($response['value'] ?? []);
    }

    /**
     * Converts raw OData value-list rows into a ['Display' => 'Stored'] map.
     *
     * Row format from FileMaker:
     *   - Static list:  {'FirstValues': 'One'}
     *   - Dynamic list: {'FirstValues': 'uuid-123', 'SecondValues': 'Alice Smith'}
     *
     * SecondValues is the human-readable label; FirstValues is the stored value.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function parseEntries(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $stored  = isset($row['FirstValues'])  ? (string) $row['FirstValues']  : null;
            $display = isset($row['SecondValues']) ? (string) $row['SecondValues'] : null;

            if ($stored === null) {
                continue;
            }

            $result[$display ?? $stored] = $stored;
        }

        return $result;
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        return $request->getSession();
    }

    private function odataConnection(): ODataConnection
    {
        try {
            $native = $this->connection->getNativeConnection();
        } catch (Throwable $e) {
            throw new ConnectionException(
                'ValueListService could not obtain the OData connection: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!$native instanceof ODataConnection) {
            throw new ConnectionException(
                'ValueListService requires a DBAL connection backed by ODataDriver. ' .
                'Check the "connection" setting in doctrine_odata.yaml.',
            );
        }

        return $native;
    }
}
