<?php
namespace Tests\Feature;

use Tests\Support\FakeSmtpServer;
use Tests\Support\FeatureTestCase;
use Tests\Support\HttpResponse;
use Tests\Support\TestServer;

/**
 * Invitation e-mails through each local transport, failure reporting, and
 * bin/send-test-mail.php. SMTP goes to FakeSmtpServer; mail() goes to
 * fake-sendmail.php (see TestServer). Nothing is ever delivered.
 */
final class MailTest extends FeatureTestCase
{
    private const INVITEE = 'carol@example.org';
    private const SENDER = ['default_mail_from' => 'noreply@dfir.test', 'default_mail_from_name' => 'DFIR FM Test'];

    private ?FakeSmtpServer $smtp = null;

    protected function serverEnv(): array
    {
        return self::SENDER + ['mail_engine' => 'mail'];
    }

    protected function tearDown(): void
    {
        $this->smtp?->stop();
        parent::tearDown();
    }

    /** Restart the app with mail_engine=smtp pointing at a fresh fake SMTP server */
    private function withSmtp(array $extra = []): FakeSmtpServer
    {
        $this->smtp = new FakeSmtpServer();
        $this->restartWith(self::SENDER + $extra + [
            'mail_engine' => 'smtp',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => (string)$this->smtp->port,
            'smtp_secure' => 'none',
            'smtp_timeout' => '5',
        ]);
        return $this->smtp;
    }

    private function invite(): HttpResponse
    {
        $this->server->db()->exec("INSERT INTO directories (name, path, parent_id)
                                   SELECT 'case-1', '/case-1', 1 WHERE NOT EXISTS (SELECT 1 FROM directories WHERE path = '/case-1')");
        return $this->adminClient()->postWithCsrf('admin', [
            'a' => 'invite', 'cwd' => '/case-1', 'email' => self::INVITEE, 'addToDefaultGroup' => 'true',
        ]);
    }

    private function invitationToken(): string
    {
        $stmt = $this->server->db()->prepare('SELECT invitation_token FROM users WHERE username = :u');
        $stmt->execute([':u' => self::INVITEE]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * A MIME message with each part's transfer encoding undone (PHPMailer
     * picks 8bit, quoted-printable or base64 per part); every part must be UTF-8
     */
    private function decoded(string $mime): string
    {
        $parts = preg_split('/^--\S+\r?$/m', $mime);
        foreach ($parts as &$part) {
            [$headers, $body] = array_pad(preg_split('/\r?\n\r?\n/', $part, 2), 2, '');
            $body = match (true) {
                (bool)preg_match('/^Content-Transfer-Encoding: quoted-printable/mi', $headers) => quoted_printable_decode($body),
                (bool)preg_match('/^Content-Transfer-Encoding: base64/mi', $headers) => base64_decode($body),
                default => $body,
            };
            $this->assertTrue(mb_check_encoding($body, 'UTF-8'), 'MIME part is valid UTF-8');
            $part = "$headers\n\n$body";
        }
        return implode("\n", $parts);
    }

    public function testInvitationOverSmtpWithAuthentication(): void
    {
        $smtp = $this->withSmtp(['smtp_user' => 'mailer', 'smtp_password' => 's3cret']);

        $response = $this->invite();
        $this->assertSame(['success' => true, 'message' => 'Invitation sent'], $response->json());

        $messages = $smtp->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame("\0mailer\0s3cret", $message['auth']);
        $this->assertSame('FROM:<noreply@dfir.test>', $message['from']);
        $this->assertSame(['TO:<' . self::INVITEE . '>'], $message['rcpt']);

        $data = $this->decoded($message['data']);
        $this->assertStringContainsString('From: DFIR FM Test <noreply@dfir.test>', $data);
        $this->assertStringContainsString('Subject: You have been invited to contribute on ' . $this->server->url, $data);
        $this->assertStringContainsString('multipart/alternative', $data);
        $this->assertStringContainsString('charset=utf-8', $data);
        $this->assertStringContainsString('token=' . $this->invitationToken(), $data);

        $invited = $this->server->logEntries('user_invited');
        $this->assertTrue(end($invited)['invitation_sent']);
        $this->assertSame([], $this->server->logEntries('mail_send_failure'));
    }

    public function testInvitationThroughSendmail(): void
    {
        $this->assertSame('Invitation sent', $this->invite()->json()['message']);

        $sent = $this->server->sentMail();
        $this->assertCount(1, $sent);
        $this->assertContains('-fnoreply@dfir.test', $sent[0]['argv'], 'envelope sender');

        $message = $this->decoded($sent[0]['message']);
        $this->assertStringContainsString('To: ' . self::INVITEE, $message);
        $this->assertStringContainsString('From: DFIR FM Test <noreply@dfir.test>', $message);
        $this->assertStringContainsString('multipart/alternative', $message);
        $this->assertStringContainsString('token=' . $this->invitationToken(), $message);
    }

    public function testSmtpFailureIsReportedAndLogged(): void
    {
        $smtp = $this->withSmtp();
        $smtp->stop();   // nothing listens on the configured port any more

        $response = $this->invite()->json();
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('could not be sent', $response['message']);
        $this->assertStringNotContainsString('127.0.0.1', $response['message'], 'details stay in the log');

        $failures = $this->server->logEntries('mail_send_failure');
        $this->assertCount(1, $failures);
        $this->assertSame(self::INVITEE, $failures[0]['recipient']);
        $this->assertSame('smtp', $failures[0]['mail_engine']);
        $this->assertNotEmpty($failures[0]['error']);

        $invited = $this->server->logEntries('user_invited');
        $this->assertFalse(end($invited)['invitation_sent']);
        $this->assertNotSame('', $this->invitationToken(), 'the contributor is still created');
    }

    public function testMailConfigurationErrorIsReportedNotFatal(): void
    {
        $this->restartWith(['mail_engine' => 'smtp']);   // no sender, no host

        $response = $this->invite();
        $this->assertSame(200, $response->status);
        $this->assertFalse($response->json()['success']);
        $failures = $this->server->logEntries('mail_send_failure');
        $this->assertStringContainsString('default_mail_from is missing', $failures[0]['error']);
    }

    public function testSendTestMailCli(): void
    {
        $smtp = $this->withSmtp();

        $result = $this->server->runBin('send-test-mail.php', 'ops@example.org');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString("accepted by mail engine 'smtp'", $result['stdout']);

        $messages = $smtp->messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['TO:<ops@example.org>'], $messages[0]['rcpt']);
        $this->assertStringContainsString('Subject: DFIR-FM test e-mail', $messages[0]['data']);

        $logged = $this->server->logEntries('cli_test_mail_sent');
        $this->assertSame('ops@example.org', $logged[0]['recipient']);
        $this->assertStringStartsWith('cli:', $logged[0]['username']);
    }

    public function testSendTestMailCliReportsFailure(): void
    {
        $this->withSmtp()->stop();

        $result = $this->server->runBin('send-test-mail.php', 'ops@example.org');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("Mail engine 'smtp' failed", $result['stderr']);
        $this->assertCount(1, $this->server->logEntries('mail_send_failure'));
    }

    public function testSendTestMailCliWithMailDisabled(): void
    {
        $this->restartWith(['mail_engine' => 'none']);   // and no sender

        $result = $this->server->runBin('send-test-mail.php', 'ops@example.org');
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('Mail is disabled', $result['stdout']);
        $this->assertSame([], $this->server->sentMail());
        $this->assertSame([], $this->server->logEntries('mail_send_failure'));
    }

    public function testSendTestMailCliUsage(): void
    {
        foreach ([[], ['not-an-address'], ['a@example.org', 'b@example.org']] as $args) {
            $result = $this->server->runBin('send-test-mail.php', ...$args);
            $this->assertSame(2, $result['exit']);
            $this->assertStringContainsString('Usage:', $result['stderr']);
        }
        $this->assertSame([], $this->server->sentMail());
    }
}
