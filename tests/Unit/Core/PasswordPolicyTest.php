<?php
namespace Tests\Unit\Core;

use Core\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testRule(): void
    {
        $this->assertNull(PasswordPolicy::problem('twelve-chars'));
        $this->assertNull(PasswordPolicy::problem('  spaces count'));
        $this->assertNull(PasswordPolicy::problem('éééééééééééé'), 'characters, not bytes');
        $this->assertStringContainsString('at least 12', PasswordPolicy::problem('ééééééééééé'));
        $this->assertStringContainsString('at least 12', PasswordPolicy::problem('eleven-char'));
        $this->assertSame('Password cannot be empty.', PasswordPolicy::problem(''));
        $this->assertSame('Password cannot be empty.', PasswordPolicy::problem(null));
        $this->assertSame('Password cannot be empty.', PasswordPolicy::problem(['twelve-chars']));
    }
}
