<?php
// src/Services/NameRules.php
namespace Services;

/**
 * Names the application accepts for files and folders, wherever they come
 * from: an upload, the "New folder" form, or a refresh from storage.
 */
final class NameRules
{
    /**
     * A file name: no control characters or slashes, no "%" followed by two
     * hex digits (the storage refuses URL-encoded sequences), at most 255
     * bytes. The reserved staging name is the caller's concern.
     */
    public static function validFileName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255
            && mb_check_encoding($name, 'UTF-8')
            && !preg_match('#[\x00-\x1F\x7F/\\\\]#', $name)
            && !preg_match('/%[0-9a-fA-F]{2}/', $name);
    }

    /**
     * A folder name, as the "New folder" form allows it. No leading dot:
     * '.', '..', and the storage's staging directory '.uploads'.
     */
    public static function validFolderName(string $name): bool
    {
        return $name !== ''
            && mb_check_encoding($name, 'UTF-8')
            && !preg_match('/[<>\/\\\\&]/u', $name)
            && !preg_match('/[\x00-\x1F\x7F]/', $name)
            && !str_starts_with($name, '.');
    }
}
?>
