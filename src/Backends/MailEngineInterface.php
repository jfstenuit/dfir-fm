<?php
// src/Backends/MailEngineInterface.php
namespace Backends;

interface MailEngineInterface {
    public function send($to, $subject, $message, $headers = []);
}
?>