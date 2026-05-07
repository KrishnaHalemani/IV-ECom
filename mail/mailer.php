<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function br_mail_config(): array
{
    $path = __DIR__ . '/../config/mail.php';
    if (!is_file($path)) {
        return [];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

function br_send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $cfg = br_mail_config();
    if ((bool) ($cfg['enabled'] ?? false) !== true) {
        app_log_error('mail', 'Mail disabled in config.', ['to' => $toEmail, 'subject' => $subject]);
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $smtp = (array) ($cfg['smtp'] ?? []);
        $mail->isSMTP();
        $mail->Host = (string) ($smtp['host'] ?? '');
        $mail->SMTPAuth = (bool) ($smtp['auth'] ?? true);
        $mail->Username = (string) ($smtp['username'] ?? '');
        $mail->Password = (string) ($smtp['password'] ?? '');
        $mail->Port = (int) ($smtp['port'] ?? 587);
        $mail->Timeout = (int) ($smtp['timeout'] ?? 20);
        $enc = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $mail->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) ($cfg['from_email'] ?? ''), (string) ($cfg['from_name'] ?? 'BattleRock'));
        $replyTo = trim((string) ($cfg['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        return $mail->send();
    } catch (Exception $e) {
        app_log_error('mail', 'PHPMailer failed.', ['to' => $toEmail, 'subject' => $subject, 'error' => $e->getMessage()]);
        return false;
    }
}

