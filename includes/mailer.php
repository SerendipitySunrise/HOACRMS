<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

/**
 * E-mail delivery.
 *
 * Transport is configuration-driven so the same code can talk to a local
 * Mailpit / MailHog instance during development, an SMTP relay in production,
 * or write to a filesystem "outbox" during automated tests.
 *
 *   CURORA_MAIL_HOST        SMTP host (if unset, dry-run mode is used)
 *   CURORA_MAIL_PORT        SMTP port (default 1025 for Mailpit/MailHog)
 *   CURORA_MAIL_USER        SMTP username (optional)
 *   CURORA_MAIL_PASS        SMTP password (optional)
 *   CURORA_MAIL_SECURE      '', 'tls' or 'ssl'
 *   CURORA_MAIL_FROM        Envelope/From address
 *   CURORA_MAIL_FROM_NAME   From display name
 *   CURORA_MAIL_DRYRUN      truthy to force dry-run even with a host set
 *   CURORA_MAIL_OUTBOX_DIR  where dry-run messages are written
 *
 * There are deliberately no credentials in this file.
 */

/** @return array{host:string,port:int,user:string,pass:string,secure:string,from:string,fromName:string,dryrun:bool} */
function curoraMailConfig(): array
{
    $host = trim((string) (getenv('CURORA_MAIL_HOST') ?: ''));
    $dryrunEnv = (string) (getenv('CURORA_MAIL_DRYRUN') ?: '');

    // Default to dry-run unless a host is configured, so a fresh checkout never
    // attempts to speak to the network. An explicit DRYRUN value always wins.
    $dryrun = $host === '';
    if ($dryrunEnv !== '') {
        $dryrun = in_array(strtolower($dryrunEnv), ['1', 'true', 'yes', 'on'], true);
    }

    return [
        'host'     => $host !== '' ? $host : '127.0.0.1',
        'port'     => (int) ((string) getenv('CURORA_MAIL_PORT') ?: 1025),
        'user'     => (string) (getenv('CURORA_MAIL_USER') ?: ''),
        'pass'     => (string) (getenv('CURORA_MAIL_PASS') ?: ''),
        'secure'   => strtolower((string) (getenv('CURORA_MAIL_SECURE') ?: '')),
        'from'     => (string) (getenv('CURORA_MAIL_FROM') ?: 'no-reply@curora.local'),
        'fromName' => (string) (getenv('CURORA_MAIL_FROM_NAME') ?: 'Curora'),
        'dryrun'   => $dryrun,
    ];
}

function curoraMailIsDryRun(): bool
{
    $cfg = curoraMailConfig();

    return $cfg['dryrun'];
}

/** Directory used to capture e-mails while in dry-run mode. */
function curoraMailOutboxDir(): string
{
    $dir = trim((string) (getenv('CURORA_MAIL_OUTBOX_DIR') ?: ''));
    if ($dir === '') {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'curora_mail_outbox';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function curoraMailWriteOutbox(string $to, string $subject, string $htmlBody): bool
{
    $payload = json_encode([
        'to'      => $to,
        'subject' => $subject,
        'html'    => $htmlBody,
        'sent_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return false;
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $path = curoraMailOutboxDir() . DIRECTORY_SEPARATOR . $name;

    return @file_put_contents($path, $payload) !== false;
}

function sendCuroraEmail(
    string $to,
    string $subject,
    string $htmlBody
): bool {
    $cfg = curoraMailConfig();

    if ($cfg['dryrun']) {
        return curoraMailWriteOutbox($to, $subject, $htmlBody);
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = $cfg['port'];
        $mail->SMTPAuth = $cfg['user'] !== '';
        if ($cfg['user'] !== '') {
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
        }
        if ($cfg['secure'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($cfg['secure'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->setFrom($cfg['from'], $cfg['fromName']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        // Never let an unreachable mail host hang the request.
        $mail->Timeout = 10;

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * E-mails an administrator-issued temporary password. The password is placed
 * in the message body only — never in the subject, a URL, a log or an audit
 * entry.
 */
function sendTemporaryPasswordEmail(
    string $to,
    string $name,
    string $temporaryPassword,
    string $roleName,
    bool $isReset,
    int $ttlHours
): bool {
    $safeName = htmlspecialchars($name !== '' ? $name : $to, ENT_QUOTES, 'UTF-8');
    $subject  = $isReset ? 'Your Curora password has been reset' : 'Your Curora account is ready';

    $intro = $isReset
        ? '<p>An administrator reset the password for your Curora account.</p>'
        : '<p>An administrator created a Curora account for you'
            . ($roleName !== '' ? ' (' . htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') . ')' : '')
            . '.</p>';

    $html = '<p>Hi ' . $safeName . ',</p>'
        . $intro
        . '<p>Sign in with this temporary password:</p>'
        . '<p style="font-size:18px;font-family:ui-monospace,Menlo,Consolas,monospace;'
        . 'background:#f1f5f9;padding:12px 16px;border-radius:8px;display:inline-block;'
        . 'letter-spacing:1px;">' . htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>You must choose a new password the first time you sign in.</p>'
        . '<p>This temporary password expires in ' . max(1, $ttlHours) . ' hour'
        . ($ttlHours === 1 ? '' : 's') . '.</p>'
        . '<p>If you did not expect this message, please contact your administrator.</p>';

    return sendCuroraEmail($to, $subject, $html);
}
