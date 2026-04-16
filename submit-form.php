<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

/**
 * @return array{
 *   host:string,
 *   port:int,
 *   encryption:string,
 *   username:string,
 *   password:string,
 *   timeout:int,
 *   from_email:string,
 *   from_name:string,
 *   recipient_email:string
 * }
 */
function loadEmailConfig(): array
{
    $fileConfig = [];
    $configFile = __DIR__ . '/email-config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $getValue = static function (string $key, mixed $default = '') use ($fileConfig): mixed {
        $envValue = getenv($key);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        if (array_key_exists($key, $fileConfig) && $fileConfig[$key] !== '') {
            return $fileConfig[$key];
        }

        return $default;
    };

    return [
        'host' => (string) $getValue('SMTP_HOST', 'smtp.hostinger.com'),
        'port' => (int) $getValue('SMTP_PORT', 465),
        'encryption' => strtolower((string) $getValue('SMTP_ENCRYPTION', 'ssl')),
        'username' => (string) $getValue('SMTP_USERNAME', 'contact@bufcompany.com'),
        'password' => (string) $getValue('SMTP_PASSWORD', ''),
        'timeout' => (int) $getValue('SMTP_TIMEOUT', 15),
        'from_email' => (string) $getValue('SMTP_FROM_EMAIL', 'contact@bufcompany.com'),
        'from_name' => (string) $getValue('SMTP_FROM_NAME', 'Buf Company Website'),
        'recipient_email' => (string) $getValue('SMTP_RECIPIENT_EMAIL', 'contact@bufcompany.com'),
    ];
}

/**
 * @param resource $socket
 */
function smtpReadResponse($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP server closed the connection unexpectedly.');
    }

    return $response;
}

/**
 * @param resource $socket
 * @param int[] $expectedCodes
 */
function smtpCommand($socket, ?string $command, array $expectedCodes): string
{
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }

    $response = smtpReadResponse($socket);
    $statusCode = (int) substr($response, 0, 3);

    if (!in_array($statusCode, $expectedCodes, true)) {
        throw new RuntimeException(trim($response));
    }

    return $response;
}

function buildMimeHeader(string $value): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * @param array<string, scalar> $config
 */
function sendViaSmtp(array $config, string $replyToName, string $replyToEmail, string $subject, string $plainTextBody): void
{
    if ($config['password'] === '') {
        throw new RuntimeException('SMTP password is not configured.');
    }

    $transportHost = $config['host'];
    $port = (int) $config['port'];
    $timeout = (int) $config['timeout'];
    $encryption = strtolower((string) $config['encryption']);

    if ($encryption === 'ssl' || $encryption === 'smtps') {
        $transportHost = 'ssl://' . $transportHost;
    }

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errorNumber,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(sprintf('Unable to connect to SMTP server: %s (%d)', $errorMessage, $errorNumber));
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtpCommand($socket, null, [220]);
        smtpCommand($socket, 'EHLO bufcompany.com', [250]);

        if ($encryption === 'tls' || $encryption === 'starttls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to start TLS encryption with SMTP server.');
            }

            smtpCommand($socket, 'EHLO bufcompany.com', [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode((string) $config['username']), [334]);
        smtpCommand($socket, base64_encode((string) $config['password']), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $config['from_email'] . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $config['recipient_email'] . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@bufcompany.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . buildMimeHeader((string) $config['from_name']) . ' <' . $config['from_email'] . '>',
            'To: <' . $config['recipient_email'] . '>',
            'Reply-To: ' . buildMimeHeader($replyToName) . ' <' . $replyToEmail . '>',
            'Subject: ' . buildMimeHeader($subject),
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(
            ["\r\n.", "\n."],
            ["\r\n..", "\n.."],
            $plainTextBody
        );

        fwrite($socket, $message . "\r\n.\r\n");
        smtpCommand($socket, null, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$honeypot = trim((string) ($_POST['website'] ?? ''));

if ($honeypot !== '') {
    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully.',
    ]);
    exit;
}

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please fill in your name, email, and message.',
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.',
    ]);
    exit;
}

$emailConfig = loadEmailConfig();
$subject = 'New website lead - Buf Company';
$safeName = preg_replace('/[\r\n]+/', ' ', $name) ?: 'Website visitor';
$safeEmail = preg_replace('/[\r\n]+/', '', $email) ?: '';
$safeCompany = preg_replace('/[\r\n]+/', ' ', $company) ?: 'Not provided';

$bodyLines = [
    'New contact request from the website.',
    '',
    'Name: ' . $safeName,
    'Email: ' . $safeEmail,
    'Company: ' . $safeCompany,
    '',
    'Message:',
    $message,
    '',
    'Sent at: ' . date('c'),
    'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
];

try {
    sendViaSmtp($emailConfig, $safeName, $safeEmail, $subject, implode("\n", $bodyLines));
} catch (Throwable $exception) {
    error_log('Buf Company SMTP error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to send your message right now. Please try again later.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Message sent successfully. We will get back to you soon.',
]);
