<?php
// src/Core/PasswordPolicy.php
namespace Core;

/**
 * The one password rule, for the invitation form and the profile dialog
 * (D26): at least MIN_LENGTH characters, no complexity rule. Passwords are
 * never trimmed, here or at login: a space is a character like any other.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** Why $password is refused, or null when it is acceptable */
    public static function problem(mixed $password): ?string
    {
        if (!is_string($password) || $password === '') {
            return 'Password cannot be empty.';
        }
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            return 'The password must have at least ' . self::MIN_LENGTH . ' characters.';
        }
        return null;
    }
}
