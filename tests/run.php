<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
session_start();

$checks = 0;

function check(bool $condition, string $description): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "PASS: {$description}\n";
}

check(validate_task('Plan the week', 'Write an outline', 'pending') === [], 'accepts a valid pending task');
check(validate_task('Finish report', '', 'in_progress') === [], 'accepts an optional empty description and in-progress status');
check(isset(validate_task('  ', '', 'pending')['title']), 'rejects an empty title');
check(validate_task(str_repeat('é', 120), '', 'pending') === [], 'accepts a 120-character Unicode title');
check(isset(validate_task(str_repeat('é', 121), '', 'pending')['title']), 'rejects a title over 120 characters');
check(isset(validate_task('Task', '', 'unknown')['status']), 'rejects an unsupported status');
check(isset(validate_task('Task', str_repeat('x', 10001), 'completed')['description']), 'rejects a description over 10,000 bytes');
check(valid_task_id('12') === 12, 'accepts a positive task ID');
check(valid_task_id('0') === null && valid_task_id('1 OR 1=1') === null, 'rejects invalid task IDs');
check(string_input(['unexpected']) === '', 'rejects non-string request input safely');
check(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'escapes HTML output');
$token = csrf_token();
check(verify_csrf_token($token), 'accepts the session CSRF token');
check(!verify_csrf_token('invalid-token'), 'rejects an invalid CSRF token');

echo "\n{$checks} checks passed.\n";
