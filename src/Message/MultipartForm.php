<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Message;

final class MultipartForm
{
    /**
     * RFC 7578 section 5.2: "Form parts with identical field names MUST NOT be
     * coalesced." Every submitted value is retained, in submission order.
     *
     * @var array<string, list<string>>
     */
    private array $fields = [];

    /**
     * RFC 7578 section 4.3: multiple files for one form field MUST be sent as
     * separate parts sharing the same "name" parameter (no "[]" required).
     * Stored uniformly as a list per name so single- and multi-file fields
     * share the same code path.
     *
     * @var array<string, list<UploadedFile>>
     */
    private array $files = [];

    public function addField(string $name, string $value): void
    {
        $this->fields[$name][] = $value;
    }

    public function addFile(string $name, UploadedFile $file): void
    {
        // Some widely deployed clients still use the "name[]" convention;
        // fold it into the same bucket as the bracket-less RFC 7578 form.
        $key = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;

        $this->files[$key][] = $file;
    }

    /**
     * Returns the first value submitted for this field, or null if absent.
     *
     * Values are returned as raw, undecoded bytes exactly as received on
     * the wire. This library does not interpret RFC 7578 section 4.5/section 4.6 charset
     * hints (the per-part "charset" Content-Type parameter or a "_charset_"
     * field) -- no mainstream browser or server implementation does, since
     * modern browsers submit forms as UTF-8 regardless of page encoding.
     * If a caller expects a non-UTF-8 charset, decode explicitly, e.g.:
     *
     * mb_convert_encoding($form->get('field1'), 'UTF-8', 'ISO-8859-1');
     */
    public function get(string $name): ?string
    {
        return $this->fields[$name][0] ?? null;
    }

    /**
     * Returns every value submitted for this field, in order. Empty array
     * if the field was not submitted.
     *
     * Same raw-bytes contract as get() -- see that method's docblock for
     * charset handling notes.
     *
     * @return list<string>
     */
    public function getAll(string $name): array
    {
        return $this->fields[$name] ?? [];
    }

    /**
     * Returns the first uploaded file for this field, or null if absent.
     */
    public function getFile(string $name): ?UploadedFile
    {
        return $this->files[$name][0] ?? null;
    }

    /**
     * Returns every uploaded file for this field, in order. Empty array
     * if the field was not submitted.
     *
     * @return list<UploadedFile>
     */
    public function getFiles(string $name): array
    {
        return $this->files[$name] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->fields;
    }
}
