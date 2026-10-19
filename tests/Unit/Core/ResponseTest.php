<?php
namespace Tests\Unit\Core;

use Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public static function contentDispositionCases(): array
    {
        return [
            'plain' => ['report.pdf', 'attachment; filename="report.pdf"; filename*=UTF-8\'\'report.pdf'],
            'quote' => ['a".exe', 'attachment; filename="a_.exe"; filename*=UTF-8\'\'a%22.exe'],
            'parameter injection' => ['x"; filename="evil.html', 'attachment; filename="x__ filename=_evil.html"; filename*=UTF-8\'\'x%22%3B%20filename%3D%22evil.html'],
            'backslash' => ['a\\b.txt', 'attachment; filename="a_b.txt"; filename*=UTF-8\'\'a%5Cb.txt'],
            'UTF-8' => ['Rapport été.docx', 'attachment; filename="Rapport __t__.docx"; filename*=UTF-8\'\'Rapport%20%C3%A9t%C3%A9.docx'],
            'CR/LF' => ["a\r\nSet-Cookie: x=1", 'attachment; filename="a__Set-Cookie: x=1"; filename*=UTF-8\'\'a%0D%0ASet-Cookie%3A%20x%3D1'],
            'percent' => ['100%.txt', 'attachment; filename="100_.txt"; filename*=UTF-8\'\'100%25.txt'],
            'non-Latin' => ['日本', 'attachment; filename="______"; filename*=UTF-8\'\'%E6%97%A5%E6%9C%AC'],
        ];
    }

    #[DataProvider('contentDispositionCases')]
    public function testContentDisposition(string $filename, string $expected): void
    {
        $header = Response::contentDisposition($filename);
        $this->assertSame($expected, $header);
        $this->assertSame(2, substr_count($header, ';'), 'exactly three parameters');
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $header);
    }
}
