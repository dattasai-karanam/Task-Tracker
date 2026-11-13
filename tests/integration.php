<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/database.php';

$checks = 0;

function integration_check(bool $condition, string $description): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "PASS: {$description}\n";
}

function app_request(string $baseUrl, string $method, string $path, array $data = [], string $cookie = ''): array
{
    $headers = ['Accept: text/html'];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $content = '';
    if ($method === 'POST') {
        $content = http_build_query($data);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 8,
        ],
    ]);
    $body = file_get_contents(rtrim($baseUrl, '/') . $path, false, $context);
    if ($body === false) {
        throw new RuntimeException('Could not reach the app. Start its PHP development server first.');
    }

    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $statusMatch);
    preg_match('/^Set-Cookie:\s*([^;]+)/mi', implode("\n", $responseHeaders), $cookieMatch);

    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 0,
        'body' => $body,
        'cookie' => $cookieMatch[1] ?? $cookie,
    ];
}

function csrf_from(string $html): string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('The app response did not contain a CSRF token.');
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$db = database();
$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8000';
$suffix = bin2hex(random_bytes(5));
$firstEmail = 'integration-' . $suffix . '@example.test';
$secondEmail = 'other-' . $suffix . '@example.test';
$password = 'IntegrationTestPass123!';
$privateTitle = 'Private integration task ' . $suffix;
$firstUserId = null;
$secondUserId = null;
$adminUserId = null;

try {
    $createUser = $db->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, \'user\')');
    $createUser->execute(['email' => $firstEmail, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    $firstUserId = (int) $db->lastInsertId();
    $createUser->execute([
        'email' => $secondEmail,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $secondUserId = (int) $db->lastInsertId();

    $createTask = $db->prepare(
        'INSERT INTO tasks (user_id, title, description, status) VALUES (:user_id, :title, \'\', \'pending\')'
    );
    $createTask->execute(['user_id' => $secondUserId, 'title' => $privateTitle]);
    $privateTaskId = (int) $db->lastInsertId();

    $loginPage = app_request($baseUrl, 'GET', '/?page=login');
    $cookie = $loginPage['cookie'];
    $login = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => csrf_from($loginPage['body']),
        'action' => 'login',
        'email' => $firstEmail,
        'password' => $password,
    ], $cookie);
    $cookie = $login['cookie'];
    integration_check($login['status'] === 303, 'test account can sign in');

    $dashboard = app_request($baseUrl, 'GET', '/?page=dashboard', [], $cookie);
    integration_check(!str_contains($dashboard['body'], $privateTitle), 'a user cannot view another user\'s task');

    $missingCsrf = app_request($baseUrl, 'POST', '/', [
        'action' => 'create_task',
        'title' => 'Must not be created',
        'description' => '',
        'status' => 'pending',
    ], $cookie);
    integration_check($missingCsrf['status'] === 400, 'a state-changing request without CSRF is rejected');

    $token = csrf_from($dashboard['body']);
    $emptyTitle = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => $token,
        'action' => 'create_task',
        'title' => '   ',
        'description' => '',
        'status' => 'pending',
    ], $cookie);
    integration_check(
        $emptyTitle['status'] === 200 && str_contains($emptyTitle['body'], 'Please enter a task title.'),
        'an empty title is rejected with an inline server validation message'
    );

    $update = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => $token,
        'action' => 'update_task',
        'task_id' => (string) $privateTaskId,
        'title' => 'Changed by another user',
        'description' => '',
        'status' => 'pending',
    ], $cookie);
    $delete = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => $token,
        'action' => 'delete_task',
        'task_id' => (string) $privateTaskId,
    ], $cookie);
    $privateTask = $db->prepare('SELECT title FROM tasks WHERE id = :id AND user_id = :user_id');
    $privateTask->execute(['id' => $privateTaskId, 'user_id' => $secondUserId]);
    integration_check(
        $update['status'] === 303 && $delete['status'] === 303 && $privateTask->fetchColumn() === $privateTitle,
        'cross-user update and delete attempts leave the task unchanged'
    );

    $injection = app_request(
        $baseUrl,
        'GET',
        '/?page=dashboard&search=' . rawurlencode('%\' OR 1=1 --'),
        [],
        $cookie
    );
    integration_check(
        $injection['status'] === 200 && !str_contains($injection['body'], $privateTitle),
        'SQL-injection text in search remains data and cannot expose another account\'s tasks'
    );

    $createAdmin = $db->prepare(
        'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, \'admin\')'
    );
    $createAdmin->execute([
        'email' => 'admin-' . $suffix . '@example.test',
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $adminUserId = (int) $db->lastInsertId();
    $adminLoginPage = app_request($baseUrl, 'GET', '/?page=login');
    $adminLogin = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => csrf_from($adminLoginPage['body']),
        'action' => 'login',
        'email' => 'admin-' . $suffix . '@example.test',
        'password' => $password,
    ], $adminLoginPage['cookie']);
    $adminCookie = $adminLogin['cookie'];
    $adminDashboard = app_request($baseUrl, 'GET', '/?page=dashboard', [], $adminCookie);
    integration_check(
        $adminLogin['status'] === 303
            && str_contains($adminDashboard['body'], '<h1>All tasks</h1>')
            && str_contains($adminDashboard['body'], $privateTitle)
            && !str_contains($adminDashboard['body'], 'class="create-panel"'),
        'an admin can view all tasks in a read-only dashboard'
    );

    $adminDelete = app_request($baseUrl, 'POST', '/', [
        'csrf_token' => csrf_from($adminDashboard['body']),
        'action' => 'delete_task',
        'task_id' => (string) $privateTaskId,
    ], $adminCookie);
    $privateTask->execute(['id' => $privateTaskId, 'user_id' => $secondUserId]);
    integration_check(
        $adminDelete['status'] === 303 && $privateTask->fetchColumn() === $privateTitle,
        'an admin cannot change tasks through a forged request'
    );
} finally {
    if ($firstUserId !== null) {
        $cleanup = $db->prepare('DELETE FROM users WHERE id IN (:first_id, :second_id, :admin_id)');
        $cleanup->execute([
            'first_id' => $firstUserId,
            'second_id' => $secondUserId,
            'admin_id' => $adminUserId,
        ]);
    }
}

echo "\n{$checks} integration checks passed.\n";
