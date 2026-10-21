<?php
namespace Tests\Unit\Backends;

use Backends\MailEngineFactory;
use Backends\MailEngineNone;
use Backends\MailEnginePHPMailer;
use Backends\MailEnginePHPMailerSMTP;
use Backends\MailEngineSendGrid;
use Backends\MailText;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Views\InvitationView;

final class MailEngineTest extends TestCase
{
    private const FROM = ['default_mail_from' => 'noreply@dfir.test', 'default_mail_from_name' => 'DFIR FM Test'];

    private static function smtpMessage(array $config): PHPMailer
    {
        $engine = MailEngineFactory::create(self::FROM + $config + ['mail_engine' => 'smtp']);
        return $engine->createMessage('alice@example.org', 'Subject', '<p>Hi</p>');
    }

    public static function engineCases(): array
    {
        return [
            'default' => [[], MailEnginePHPMailer::class],
            'mail' => [['mail_engine' => 'mail'], MailEnginePHPMailer::class],
            'case-insensitive' => [['mail_engine' => ' SMTP ', 'smtp_host' => 'mx.test'], MailEnginePHPMailerSMTP::class],
            // Flat keys, as Config produces them (the factory used to read a nonexistent 'mail_settings' array)
            'smtp' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_user' => 'u', 'smtp_password' => 'p', 'smtp_port' => '587'], MailEnginePHPMailerSMTP::class],
            // The class used to be referenced as MailEngineSendGrid but declared in MailEngineSendgrid.php
            'sendgrid' => [['mail_engine' => 'sendgrid', 'sendgrid_api_key' => 'SG.x'], MailEngineSendGrid::class],
            'none' => [['mail_engine' => 'None'], MailEngineNone::class],
        ];
    }

    #[DataProvider('engineCases')]
    public function testEngineSelection(array $config, string $class): void
    {
        $this->assertInstanceOf($class, MailEngineFactory::create(self::FROM + $config));
    }

    public function testNoneNeedsNoSenderAndNeverSends(): void
    {
        $config = ['mail_engine' => ' none '];
        $this->assertTrue(MailEngineFactory::isManual($config));
        $engine = MailEngineFactory::create($config);
        $this->assertInstanceOf(MailEngineNone::class, $engine);
        $this->assertFalse($engine->send('alice@example.org', 'Subject', '<p>Hi</p>'));
        $this->assertStringContainsString('mail_engine=none', $engine->lastError());

        foreach ([[], ['mail_engine' => 'mail'], ['mail_engine' => 'smtp']] as $other) {
            $this->assertFalse(MailEngineFactory::isManual($other));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'no sender' => [['mail_engine' => 'mail', 'default_mail_from' => ''], 'default_mail_from is missing'],
            'invalid sender' => [['mail_engine' => 'mail', 'default_mail_from' => 'not-an-address'], 'default_mail_from is missing'],
            'unknown engine' => [['mail_engine' => 'pigeon'], "Invalid mail engine specified in configuration: 'pigeon'"],
            'smtp without host' => [['mail_engine' => 'smtp'], 'smtp_host is required'],
            'smtp bad secure' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_secure' => 'maybe'], "Invalid smtp_secure 'maybe'"],
            'smtp bad port' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_port' => '70000'], "Invalid smtp_port '70000'"],
            'smtp non-numeric port' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_port' => 'abc'], "Invalid smtp_port 'abc'"],
            'smtp bad timeout' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_timeout' => '0'], "Invalid smtp_timeout '0'"],
            'smtp user without password' => [['mail_engine' => 'smtp', 'smtp_host' => 'mx.test', 'smtp_user' => 'u'], 'smtp_password is required'],
            'sendgrid without key' => [['mail_engine' => 'sendgrid'], 'sendgrid_api_key is required'],
        ];
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationRefused(array $config, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        MailEngineFactory::create($config + self::FROM);
    }

    public static function smtpSecurityCases(): array
    {
        // [settings, expected SMTPSecure, SMTPAutoTLS, Port]
        return [
            'default: plain SMTP on 25' => [[], '', false, 25],
            'default on another port: still plain' => [['smtp_port' => '587'], '', false, 587],
            'starttls defaults to 587' => [['smtp_secure' => 'starttls'], 'tls', true, 587],
            'port 465 implies SMTPS' => [['smtp_port' => '465'], 'ssl', true, 465],
            'smtps defaults to 465' => [['smtp_secure' => 'smtps'], 'ssl', true, 465],
            'ssl alias' => [['smtp_secure' => 'SSL', 'smtp_port' => '2465'], 'ssl', true, 2465],
            'starttls on 25' => [['smtp_secure' => 'starttls', 'smtp_port' => '25'], 'tls', true, 25],
            'tls alias' => [['smtp_secure' => 'TLS'], 'tls', true, 587],
            'none: no TLS at all' => [['smtp_secure' => 'none'], '', false, 25],
        ];
    }

    #[DataProvider('smtpSecurityCases')]
    public function testSmtpEncryptionAndPort(array $settings, string $secure, bool $autoTls, int $port): void
    {
        $mail = self::smtpMessage(['smtp_host' => 'mx.test'] + $settings);
        $this->assertSame('smtp', $mail->Mailer);
        $this->assertSame('mx.test', $mail->Host);
        $this->assertSame($secure, $mail->SMTPSecure);
        $this->assertSame($autoTls, $mail->SMTPAutoTLS);
        $this->assertSame($port, $mail->Port);
        $this->assertSame(0, $mail->SMTPDebug);
    }

    public function testSmtpAuthenticationOnlyWithUser(): void
    {
        $anonymous = self::smtpMessage(['smtp_host' => 'mx.test']);
        $this->assertFalse($anonymous->SMTPAuth);

        $authenticated = self::smtpMessage(['smtp_host' => 'mx.test', 'smtp_user' => 'mailer', 'smtp_password' => 's3cret', 'smtp_timeout' => '12']);
        $this->assertTrue($authenticated->SMTPAuth);
        $this->assertSame('mailer', $authenticated->Username);
        $this->assertSame('s3cret', $authenticated->Password);
        $this->assertSame(12, $authenticated->Timeout);
    }

    public function testMessageUsesConfiguredSenderUtf8AndPlainTextAlternative(): void
    {
        $engine = MailEngineFactory::create(self::FROM);
        $html = InvitationView::render('alice@example.org', 'abc123', '/case-1', 'https://dfir.test/fm');
        $mail = $engine->createMessage('alice@example.org', 'Invitation', $html);

        $this->assertSame('mail', $mail->Mailer);
        $this->assertSame('noreply@dfir.test', $mail->From);
        $this->assertSame('DFIR FM Test', $mail->FromName);
        $this->assertSame('noreply@dfir.test', $mail->Sender, 'envelope sender (-f) follows From');
        $this->assertSame('utf-8', $mail->CharSet);
        $this->assertSame('text/html', $mail->ContentType);
        $this->assertSame($html, $mail->Body);
        $this->assertStringContainsString('https://dfir.test/fm/login?email=alice%40example.org&token=abc123&directory=%2Fcase-1', $mail->AltBody);

        $mail->preSend();
        $mime = $mail->getSentMIMEMessage();
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('From: DFIR FM Test <noreply@dfir.test>', $mime);
    }

    public function testNonAsciiSubjectIsEncoded(): void
    {
        $mail = MailEngineFactory::create(self::FROM)->createMessage('alice@example.org', 'Invitation – dossier Ø', '<p>x</p>');
        $mail->preSend();
        $this->assertMatchesRegularExpression('/^Subject: =\?utf-8\?/m', $mail->getSentMIMEMessage());
    }

    public function testHeaderInjectionThroughSubjectIsNeutralised(): void
    {
        $mail = MailEngineFactory::create(self::FROM)->createMessage('alice@example.org', "Hello\r\nBcc: victim@example.org", '<p>x</p>');
        $mail->preSend();
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mail->getSentMIMEMessage());
    }

    public function testInvalidRecipientFailsWithoutSending(): void
    {
        $engine = MailEngineFactory::create(self::FROM);
        $this->assertFalse($engine->send("alice@example.org\r\nBcc: victim@example.org", 'Subject', '<p>x</p>'));
        $this->assertStringContainsString('Invalid address', $engine->lastError());
    }

    public function testSendGridMessage(): void
    {
        $engine = MailEngineFactory::create(self::FROM + ['mail_engine' => 'sendgrid', 'sendgrid_api_key' => 'SG.x']);
        $payload = json_decode(json_encode($engine->createMessage('alice@example.org', 'Invitation', '<p>Hello &amp; welcome</p>')), true);

        $this->assertEquals(['email' => 'noreply@dfir.test', 'name' => 'DFIR FM Test'], $payload['from']);
        $this->assertSame('Invitation', $payload['subject']);
        $this->assertSame('alice@example.org', $payload['personalizations'][0]['to'][0]['email']);
        $this->assertSame([
            ['type' => 'text/plain', 'value' => 'Hello & welcome'],
            ['type' => 'text/html', 'value' => '<p>Hello &amp; welcome</p>'],
        ], $payload['content']);
    }

    public function testPlainTextDropsMarkupAndStyles(): void
    {
        $text = MailText::fromHtml(InvitationView::render('alice@example.org', 'abc123', '/case-1', 'https://dfir.test/fm'));

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('font-family', $text);
        $this->assertStringContainsString('the folder /case-1 of the DFIR FM file manager on dfir.test', $text);
        $this->assertStringContainsString('token=abc123', $text);
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $text);
    }

    public function testInvitationWording(): void
    {
        $html = InvitationView::render('alice@example.org', 'abc123', '/case-1', 'https://dfir.test/fm', '2026-10-21 14:05');
        $text = MailText::fromHtml($html);
        $this->assertStringContainsString('with the account alice@example.org', $text);
        $this->assertStringContainsString('at least 12 characters', $text);
        $this->assertStringContainsString('expires at 2026-10-21 14:05 UTC', $text);
        $this->assertStringContainsString('Do not forward this message', $text);
        $this->assertSame('Invitation to contribute to /case-1 on dfir.test', InvitationView::subject('/case-1', 'https://dfir.test/fm'));

        // A resend: no folder, and the new password replaces the old one
        $resend = MailText::fromHtml(InvitationView::render('alice@example.org', 'abc123', '/', 'https://dfir.test/fm'));
        $this->assertStringContainsString('sent you a new link for your account alice@example.org', $resend);
        $this->assertStringContainsString('the new one replaces it', $resend);
        $this->assertStringContainsString('directory=%2F', $resend);
        $this->assertSame('Your new DFIR FM link for dfir.test', InvitationView::subject('/', 'https://dfir.test/fm'));

        $hostile = InvitationView::render('alice@example.org', 'abc123', '/<b>case</b>', 'https://dfir.test/fm');
        $this->assertStringNotContainsString('<b>case</b>', $hostile, 'folder names are escaped');
    }

    public function testPlainTextEntitiesDecodeToUtf8(): void
    {
        // PHPMailer's default CharSet would turn &copy; into the Latin-1 byte 0xA9
        $this->assertSame('© 2026 – café', MailText::fromHtml('<p>&copy; 2026 &ndash; caf&eacute;</p>'));
    }
}
