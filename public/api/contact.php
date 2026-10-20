<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new AppException('Use the contact form to send a message.', 405, 'method');
}
csrf_check();
rate_limit('contact', 8, 3600);
$data = request_data();
if (trim((string) ($data['company_website'] ?? '')) !== '') {
    json_response(['ok' => true]);
}
$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new AppException('Enter your name, a valid email, and a message.', 422, 'contact');
}
if (strlen($message) > 5000) {
    throw new AppException('The message is too long.', 422, 'contact');
}
$dir = APP_ROOT . '/private_storage/messages';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
$id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$record = [
    'id' => $id,
    'name' => $name,
    'email' => $email,
    'message' => $message,
    'created' => gmdate('c'),
];
file_put_contents($dir . '/' . $id . '.json', json_encode($record, JSON_UNESCAPED_UNICODE));
$sent = false;
$contact = trim((string) config('contact_email'));
if ($contact !== '' && function_exists('mail')) {
    $body = "From: {$name} <{$email}>\n\n{$message}\n";
    $sent = @mail($contact, 'Quire contact form', $body, 'Reply-To: ' . $email);
}
json_response([
    'ok' => true,
    'mailed' => (bool) $sent,
    'message' => $sent
        ? 'Your message was sent and a copy was stored for the site owner.'
        : 'Your message was stored for the site owner. Email delivery is not configured on this server.',
]);
