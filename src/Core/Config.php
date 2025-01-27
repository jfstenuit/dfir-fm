<?php

// src/Core/Config.php
namespace Core;

class Config
{
    public static function load($envFile)
    {
        if (!file_exists($envFile)) {
            throw new \Exception("Environment file not found: $envFile");
        }

        $config = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Skip comments
            }

            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }

        return $config;
    }
}
?>
