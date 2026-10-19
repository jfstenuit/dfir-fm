<?php
// src/Backends/MailText.php
namespace Backends;

use PHPMailer\PHPMailer\PHPMailer;

class MailText {
    /** Plain-text alternative of an HTML message, for multipart/alternative */
    public static function fromHtml(string $html): string
    {
        // PHPMailer drops <head>, <style>, <script> and tags, and decodes entities
        // in its CharSet (ISO-8859-1 by default: &copy; would become a Latin-1 byte)
        $mailer = new PHPMailer();
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $text = $mailer->html2text($html);

        $lines = array_map('trim', preg_split('/\R/', $text));
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
?>