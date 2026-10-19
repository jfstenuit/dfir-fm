<?php
namespace Tests\Unit\Controllers;

use Controllers\DownloadController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloadRangeTest extends TestCase
{
    public static function rangeCases(): array
    {
        return [
            'no header' => [null, 100, null],
            'whole file' => ['bytes=0-99', 100, [0, 99]],
            'first byte' => ['bytes=0-0', 100, [0, 0]],
            'open end' => ['bytes=10-', 100, [10, 99]],
            'end clamped' => ['bytes=10-1000', 100, [10, 99]],
            'suffix' => ['bytes=-10', 100, [90, 99]],
            'suffix larger than file' => ['bytes=-1000', 100, [0, 99]],
            'zero suffix' => ['bytes=-0', 100, false],
            'start at size' => ['bytes=100-', 100, false],
            'empty file' => ['bytes=0-', 0, false],
            'suffix on empty file' => ['bytes=-5', 0, false],
            'end before start: ignored' => ['bytes=50-10', 100, null],
            'multi-range: ignored' => ['bytes=0-1,5-6', 100, null],
            'other unit: ignored' => ['items=0-10', 100, null],
            'no positions: ignored' => ['bytes=-', 100, null],
            'case and spaces' => ['BYTES = 5 - 9', 100, [5, 9]],
        ];
    }

    #[DataProvider('rangeCases')]
    public function testParseRange(?string $header, int $size, array|null|false $expected): void
    {
        $this->assertSame($expected, DownloadController::parseRange($header, $size));
    }
}
