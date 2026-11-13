<?php
declare(strict_types=1);

const TASK_STATUSES = [
    'pending' => 'Pending',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function string_input(mixed $value): string
{
    return is_string($value) ? $value : '';
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(mixed $submitted): bool
{
    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function validate_task(string $title, string $description, string $status): array
{
    $errors = [];
    $title = trim($title);
    $titleLength = preg_match_all('/./us', $title);

    if ($title === '') {
        $errors['title'] = 'Please enter a task title.';
    } elseif ($titleLength === false || $titleLength > 120) {
        $errors['title'] = 'The title must be valid text no longer than 120 characters.';
    }

    if (strlen($description) > 10000 || preg_match('//u', $description) !== 1) {
        $errors['description'] = 'The description must be valid text no longer than 10,000 bytes.';
    }

    if (!array_key_exists($status, TASK_STATUSES)) {
        $errors['status'] = 'Choose a valid task status.';
    }

    return $errors;
}

function valid_task_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : $id;
}
