<?php
// src/Core/App.php
namespace Core;

class App {
    private static $config;
    private static $db;
    private static $logger;

    public static function setConfig(array $cfg) {
        self::$config = $cfg;
    }

    public static function getConfig(): array {
        return self::$config;
    }

    public static function setDb(\PDO $pdo) {
        self::$db = $pdo;
    }

    public static function getDb(): \PDO {
        return self::$db;
    }

    public static function setLogger(\Backends\LogEngineInterface $logger) {
        self::$logger = $logger;
    }

    public static function getLogger(): \Backends\LogEngineInterface {
        return self::$logger;
    }
}
?>