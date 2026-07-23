<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Service;

use Doctrine\DBAL\Connection;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\SqlToOdata\Support\KeyValue;
use RuntimeException;
use Throwable;

/**
 * Uploads and downloads binary data to/from FileMaker container fields via OData.
 *
 * Container fields in FileMaker store binary data (images, PDFs, etc.).
 * In OData responses, a container field's value is a URL to the binary content.
 * This service handles both directions:
 *
 *   download — GET the URL with authentication → raw bytes
 *   upload   — PATCH /EntitySet('key')/FieldName with binary body
 */
readonly class ContainerService
{
    public function __construct(
        private Connection $connection
    ) { }

    /**
     * Downloads the binary content of a container field.
     *
     * Pass the URL from the entity response's container field value directly.
     * Authentication is handled automatically using the connection credentials.
     *
     * @param string $containerUrl The URL returned by FileMaker for the container field.
     * @return string              Raw binary content.
     *
     * @throws ODataDriverException on HTTP or connectivity errors.
     */
    public function download(string $containerUrl): string
    {
        return $this->odataConnection()->getClient()->fetchUrl($containerUrl);
    }

    /**
     * Streams the binary content of a container field into a writable resource.
     *
     * Prefer this over download() for large files — content is written to the
     * target resource in chunks without being loaded fully into memory.
     *
     * Example — stream directly to a temporary file:
     *
     *   $tmp = tmpfile();
     *   $containerService->downloadToStream($entity->attachment, $tmp);
     *   rewind($tmp);
     *   // read from $tmp or pass to a StreamedResponse
     *
     * @param string   $containerUrl The URL from the entity's container field value.
     * @param resource $target       A writable PHP resource.
     *
     * @throws ODataDriverException on HTTP or connectivity errors.
     */
    public function downloadToStream(string $containerUrl, mixed $target): void
    {
        $this->odataConnection()->getClient()->fetchUrlToStream($containerUrl, $target);
    }

    /**
     * Uploads binary content to a container field on an existing record.
     *
     * The record must already exist. To upload a file while creating a new
     * record, first create the record (persist + flush) then call this method.
     *
     * @param string   $entitySet   OData entity set name, e.g. 'Document'.
     * @param KeyValue $keyValue    The entity's primary key.
     * @param string   $field       Container field name, e.g. 'Attachment'.
     * @param string   $content     Raw binary content of the file to upload.
     * @param string   $contentType MIME type, e.g. 'image/jpeg', 'application/pdf'.
     *
     * @throws ODataDriverException on HTTP or connectivity errors.
     */
    public function upload(
        string   $entitySet,
        KeyValue $keyValue,
        string   $field,
        string   $content,
        string   $contentType,
    ): void {
        $this->odataConnection()->getClient()->patchBinary(
            $entitySet,
            $keyValue,
            $field,
            $content,
            $contentType,
            $this->uploadTimeout(strlen($content)),
        );
    }

    /**
     * Calculates a generous timeout for binary uploads based on content size.
     *
     * Assumes a minimum effective throughput of ~33 KB/s (30 seconds per MB),
     * with a 60-second floor to allow for server-side processing time.
     *
     * Examples: 1 MB → 60 s, 9 MB → 270 s, 50 MB → 1 500 s.
     */
    private function uploadTimeout(int $bytes): int
    {
        return max(60, (int) ceil($bytes / (1024 * 1024)) * 30);
    }

    /**
     * Convenience wrapper for uploading from a local file path.
     *
     * @param string   $entitySet   OData entity set name, e.g. 'Document'.
     * @param KeyValue $keyValue    The entity's primary key.
     * @param string   $field       Container field name, e.g. 'Attachment'.
     * @param string   $filePath    Absolute path to the file on disk.
     * @param string   $contentType MIME type. If empty, guessed via mime_content_type().
     *
     * @throws RuntimeException    if the file cannot be read.
     * @throws ODataDriverException on HTTP or connectivity errors.
     */
    public function uploadFile(
        string   $entitySet,
        KeyValue $keyValue,
        string   $field,
        string   $filePath,
        string   $contentType = '',
    ): void {
        if (!is_readable($filePath)) {
            throw new RuntimeException("Cannot read file: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        if ($contentType === '') {
            $contentType = mime_content_type($filePath) ?: 'application/octet-stream';
        }

        $this->upload($entitySet, $keyValue, $field, $content, $contentType);
    }

    private function odataConnection(): ODataConnection
    {
        try {
            $native = $this->connection->getNativeConnection();
        } catch (Throwable $e) {
            throw new ConnectionException(
                'ContainerService could not obtain the OData connection: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!$native instanceof ODataConnection) {
            throw new ConnectionException(
                'ContainerService requires a DBAL connection backed by ODataDriver. ' .
                'Check the "connection" setting in doctrine_odata.yaml.',
            );
        }

        return $native;
    }
}
