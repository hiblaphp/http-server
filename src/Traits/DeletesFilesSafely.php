<?php

declare(strict_types=1);

namespace Hibla\HttpServer\Traits;

trait DeletesFilesSafely
{
    /**
     * Safely deletes a file only if it exists, without relying on error suppression.
     */
    protected static function deleteFileSafely(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}