<?php
namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\NameRules;

final class NameRulesTest extends TestCase
{
    public static function names(): array
    {
        // name => [valid file name, valid folder name]
        return [
            'plain' => ['disk.E01', true, true],
            'spaces and accents' => ['Évidence 1', true, true],
            'leading dot' => ['.bash_history', true, false],
            'staging name' => ['.uploads', true, false],
            'dot' => ['.', false, false],
            'dot dot' => ['..', false, false],
            'empty' => ['', false, false],
            'slash' => ['a/b', false, false],
            'backslash' => ['a\\b', false, false],
            'control' => ["a\tb", false, false],
            'URL-encoded' => ['a%41b', false, true],
            'ampersand' => ['a&b', true, false],
            'angle bracket' => ['<a>', true, false],
            'invalid UTF-8' => ["a\xffb", false, false],
            '256 bytes' => [str_repeat('a', 256), false, true],
        ];
    }

    #[DataProvider('names')]
    public function testRules(string $name, bool $file, bool $folder): void
    {
        $this->assertSame($file, NameRules::validFileName($name), 'file');
        $this->assertSame($folder, NameRules::validFolderName($name), 'folder');
    }
}
