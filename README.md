# doctrine-odata-bundle

Symfony bundle for [`matatirosoln/doctrine-odata-driver`](https://github.com/matatirosolutions/doctrine-odata-driver).

Integrates the OData DBAL driver into a Symfony application with automatic metadata caching and a set of FileMaker-specific services for scripts, container fields, and value lists.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.4+ |
| Symfony | 7.x or 8.x |
| `matatirosoln/doctrine-odata-driver` | ^0.0.1 |
| `doctrine/doctrine-bundle` | ^2.12 |

---

## Installation

```bash
composer require matatirosoln/doctrine-odata-bundle
```

Symfony Flex will register the bundle automatically. Without Flex, add it to `config/bundles.php`:

```php
Matatirosoln\DoctrineOdataBundle\DoctrineOdataBundle::class => ['all' => true],
```

---

## Configuration

### 1. DBAL connection — `config/packages/doctrine.yaml`

```yaml
doctrine:
    dbal:
        driver_class: Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver
        host: '%env(ODATA_HOST)%'
        dbname: '%env(ODATA_DATABASE)%'
        user: '%env(ODATA_USER)%'
        password: '%env(ODATA_PASSWORD)%'
        options:
            ssl: true
            quote_guids: true   # required for FileMaker — keeps GUID literals quoted in $filter
            metadata_ttl: 3600  # should match the TTL in doctrine_odata.yaml
```

Set the corresponding environment variables in `.env.local`:

```dotenv
ODATA_HOST=your-filemaker-server.example.com
ODATA_DATABASE=YourDatabaseName
ODATA_USER=your-odata-user
ODATA_PASSWORD=your-password
```

### 2. Bundle configuration — `config/packages/doctrine_odata.yaml`

```yaml
doctrine_odata:
    connection: default         # name of the Doctrine DBAL connection using ODataDriver
    metadata_cache:
        pool: cache.app         # any Symfony PSR-6 cache pool service ID
        ttl: 3600               # seconds; 0 = no expiry
```

All keys are optional — the defaults shown above apply if omitted:

```yaml
doctrine_odata: ~
```

---

## Metadata caching

The OData `$metadata` endpoint describes the schema of the database and is required for every request. Without caching it would be fetched on every page load. The bundle wires a [DBAL middleware](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/architecture.html#middlewares) that persists the parsed metadata to the configured Symfony cache pool so that it is only fetched from the server when the cache entry expires.

The middleware is a no-op for any non-OData DBAL connection, so it is safe to use in applications with multiple connections.

---

## Services

The bundle registers the following services, all of which are autowireable by their class name.

### ScriptService

Runs a FileMaker script via the OData `Script.{name}` endpoint.

```php
use Matatirosoln\DoctrineOdataBundle\Service\ScriptService;

class MyController
{
    public function __construct(private readonly ScriptService $scripts) {}

    public function run(): void
    {
        // Plain string parameter
        $result = $this->scripts->run('SendWelcomeEmail', 'user@example.com');

        // Array parameter — automatically JSON-encoded
        $result = $this->scripts->run('ProcessOrder', [
            'orderId' => 'abc-123',
            'notify'  => true,
        ]);

        // No parameter
        $result = $this->scripts->run('NightlyCleanup');
    }
}
```

`run()` returns the parsed JSON response body as an array. It throws a `ScriptException` if FileMaker returns a non-zero script result code:

```php
use Matatirosoln\DoctrineOdataBundle\Exception\ScriptException;

try {
    $this->scripts->run('ValidateRecord', $id);
} catch (ScriptException $e) {
    // $e->scriptName    — the script that was called
    // $e->scriptCode    — the non-zero result code returned by FileMaker
    // $e->resultParameter — the raw result parameter string from FileMaker
}
```

### ContainerService

Uploads and downloads binary data to/from FileMaker container fields.

In OData responses, a container field's value is a URL pointing to the binary content. Pass that URL directly to `download()` or `downloadToStream()`.

```php
use Matatirosoln\DoctrineOdataBundle\Service\ContainerService;
use Matatirosoln\SqlToOdata\Support\KeyValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/document')]
class DocumentController extends AbstractController
{
    public function __construct(private readonly ContainerService $containers) {}

    // Download the raw bytes of a container field and return them as a response
    #[Route('/{id}/download', name: 'document_download')]
    public function download(string $id, Document $document): Response
    {
        $content = $this->containers->download($document->attachment);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="document.pdf"',
        ]);
    }

    // Stream a large file directly to the browser without loading it into memory
    #[Route('/{id}/stream', name: 'document_stream')]
    public function stream(string $id, Document $document): StreamedResponse
    {
        return new StreamedResponse(function () use ($document) {
            $this->containers->downloadToStream($document->attachment, fopen('php://output', 'wb'));
        }, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document.pdf"',
        ]);
    }

    // Upload binary content from a request to a container field
    #[Route('/{id}/upload', name: 'document_upload', methods: ['POST'])]
    public function upload(string $id, Request $request): Response
    {
        $content  = $request->getContent();
        $keyValue = new KeyValue($id, quoted: true);

        $this->containers->upload('Document', $keyValue, 'Attachment', $content, 'application/pdf');

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // Upload directly from a file path (MIME type auto-detected if omitted)
    #[Route('/{id}/upload-file', name: 'document_upload_file', methods: ['POST'])]
    public function uploadFile(string $id, string $filePath): Response
    {
        $keyValue = new KeyValue($id, quoted: true);
        $this->containers->uploadFile('Document', $keyValue, 'Attachment', $filePath);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
```

### ValueListService

Fetches FileMaker value lists and returns them in the `['Display Label' => 'stored_value']` format expected by Symfony's `ChoiceType`.

> **Requires FileMaker 22 or later.** The `FileMaker_ValueList_{name}` OData endpoints used by this service were introduced in FileMaker 22. Earlier versions expose value list names in `$metadata` but do not serve the actual entries via OData.

```php
use Matatirosoln\DoctrineOdataBundle\Service\ValueListService;

class MyFormType extends AbstractType
{
    public function __construct(private readonly ValueListService $valueLists) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('status', ChoiceType::class, [
            'choices' => $this->valueLists->get('Status'),
            // returns e.g. ['Active' => 'Active', 'Inactive' => 'Inactive']
        ]);

        $builder->add('assignee', ChoiceType::class, [
            'choices' => $this->valueLists->choices('Users'),
            // dynamic list: ['Alice Smith' => 'uuid-...', 'Bob Jones' => 'uuid-...']
        ]);
    }
}
```

#### Available methods

| Method | Description |
|---|---|
| `names(): list<string>` | All value list names from `$metadata` — no HTTP request |
| `get(string $name): array` | Entries for a single list |
| `choices(string $name): array` | Alias for `get()` |
| `all(): array` | All lists keyed by name |
| `clearCache(?string $name): void` | Invalidate one list or all lists |

#### Caching

Value lists are cached at two layers to minimise OData requests:

1. **Request cache** — an in-memory array on the service instance. Within a single request, a list is never fetched more than once regardless of how many times it is accessed.
2. **Session cache** — when a Symfony session is active, fetched lists are stored in the session and reused across page loads for the lifetime of that session. This avoids N × OData calls on every form reload without requiring a shared application cache that is difficult to invalidate.

The session cache degrades gracefully to request-only caching for API requests and CLI commands that have no session.

Calling `clearCache()` removes the named list (or all lists) from both layers simultaneously. The session cache is also automatically cleared when the user's session is destroyed (e.g. on logout).

### EntityGeneratorService

Generates Doctrine entity and repository PHP source files from the OData `$metadata` schema. Intended primarily for use via the CLI command below, but available as an injectable service for programmatic use.

```php
use Matatirosoln\DoctrineOdataBundle\Service\EntityGeneratorService;

$plan = $this->generator->plan('Users', 'Users\User');

// Inspect the plan before writing
echo $plan->entityClass;   // App\Entity\Users\User
echo $plan->entityCode;    // rendered PHP source

// Write files to disk
$this->generator->writeFile($plan->entityFile, $plan->entityCode);
$this->generator->writeFile($plan->repositoryFile, $plan->repositoryCode);
```

---

## Console commands

### `doctrine:odata:entity:generate`

Generates a Doctrine entity class and its repository from an OData entity set.

```bash
bin/console doctrine:odata:entity:generate <entity-set> <class-name>
```

| Argument | Description |
|---|---|
| `entity-set` | The OData entity set name (as it appears in `$metadata`) |
| `class-name` | PHP class name, optionally with sub-namespace using backslashes |

**Examples:**

```bash
# Generate App\Entity\User and App\Repository\UserRepository
bin/console doctrine:odata:entity:generate User User

# Generate App\Entity\Users\User in a sub-namespace
bin/console doctrine:odata:entity:generate Users "Users\User"
```

The command:

- Displays a summary of what will be generated and asks for confirmation before writing
- Prompts before overwriting any file that already exists
- Creates `src/Entity/` and `src/Repository/` subdirectories as needed
- Infers the base namespace (`App\` or similar) from the project's `composer.json` PSR-4 autoload mapping
- Maps all OData EDM types to the correct PHP types and `Doctrine\DBAL\Types\Types` constants
- Generates properties using PHP 8.4 property hooks with `trim()` applied to string setters
- Marks the primary key `public private(set)` with constructor injection
- Marks `Edm.Stream`/`Edm.Binary` (container) fields with a doc comment pointing to `ContainerService`

**Example output** for a `User` entity set with fields `__pk_UserID`, `Name`, `City`:

```php
#[ORM\Table(name: 'User')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User
{
    #[ORM\Id]
    #[ORM\Column(name: '__pk_UserID', type: Types::GUID)]
    public private(set) string $id {
        get => $this->id;
        set => $this->id = $value;
    }

    #[ORM\Column(name: 'Name', type: Types::STRING, length: 255)]
    public string $name {
        get => $this->name;
        set => $this->name = trim($value);
    }

    #[ORM\Column(name: 'City', type: Types::STRING, length: 255)]
    public string $city {
        get => $this->city;
        set => $this->city = trim($value);
    }

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
```

---

## Exceptions

All bundle exceptions extend `RuntimeException` and carry structured context:

| Exception | Thrown by | Extra properties |
|---|---|---|
| `ConnectionException` | All services | — |
| `ScriptException` | `ScriptService` | `$scriptName`, `$scriptCode`, `$resultParameter` |

`ConnectionException` is thrown when a service cannot resolve a DBAL connection backed by `ODataDriver`. This typically means the `connection` key in `doctrine_odata.yaml` does not match the name of the configured OData DBAL connection.

---

## Licence

MIT

## Contact

Steve Winter — Matatiro Solutions Ltd — [steve@msdev.nz](mailto:steve@msdev.nz)
