<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/database.php';

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $https,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

$page = string_input($_GET['page'] ?? 'dashboard');
$action = string_input($_POST['action'] ?? '');
$errors = [];
$formAction = '';
$editForm = [];
$old = ['email' => ''];
$notice = null;
$tasks = [];
$taskStats = array_fill_keys(array_keys(TASK_STATUSES), 0);
$search = '';
$statusFilter = '';
$user = null;
$db = null;
$serverError = false;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            $errors['_form'] = 'Your session token expired. Refresh the page and try again.';
            $page = in_array($action, ['login', 'register'], true) ? $action : 'dashboard';
        } elseif ($action === 'login') {
            $email = strtolower(trim(string_input($_POST['email'] ?? '')));
            $password = string_input($_POST['password'] ?? '');
            $old['email'] = $email;

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $errors['_form'] = 'Enter a valid email address and password.';
                $page = 'login';
            } else {
                $db = database();
                $statement = $db->prepare('SELECT id, email, password_hash, role FROM users WHERE email = :email');
                $statement->execute(['email' => $email]);
                $account = $statement->fetch();

                if (!$account || !password_verify($password, $account['password_hash'])) {
                    $errors['_form'] = 'The email or password is incorrect.';
                    $page = 'login';
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']);
                    $_SESSION['user_id'] = (int) $account['id'];
                    $_SESSION['user_email'] = $account['email'];
                    $_SESSION['user_role'] = $account['role'];
                    redirect('?page=dashboard');
                }
            }
        } elseif ($action === 'register') {
            $email = strtolower(trim(string_input($_POST['email'] ?? '')));
            $password = string_input($_POST['password'] ?? '');
            $passwordConfirmation = string_input($_POST['password_confirmation'] ?? '');
            $old['email'] = $email;
            $page = 'register';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $errors['email'] = 'Enter a valid email address (maximum 254 characters).';
            }
            if (strlen($password) < 12 || strlen($password) > 72) {
                $errors['password'] = 'Use a password between 12 and 72 bytes long.';
            } elseif (!hash_equals($password, $passwordConfirmation)) {
                $errors['password_confirmation'] = 'The passwords do not match.';
            }

            if (!$errors) {
                $db = database();
                try {
                    $statement = $db->prepare(
                        'INSERT INTO users (email, password_hash) VALUES (:email, :password_hash)'
                    );
                    $statement->execute([
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                } catch (PDOException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }
                    $errors['email'] = 'An account with that email already exists.';
                }

                if (!$errors) {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']);
                    $_SESSION['user_id'] = (int) $db->lastInsertId();
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_role'] = 'user';
                    set_flash('success', 'Your account is ready. Welcome to Task Tracker.');
                    redirect('?page=dashboard');
                }
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
            redirect('?page=login');
        } elseif (in_array($action, ['create_task', 'update_task', 'delete_task', 'set_task_status'], true)) {
            $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId < 1) {
                redirect('?page=login');
            }
            if (string_input($_SESSION['user_role'] ?? 'user') === 'admin') {
                set_flash('error', 'Administrator accounts can view tasks but cannot change them.');
                redirect('?page=dashboard');
            }

            $db = database();

            if ($action === 'set_task_status') {
                $taskId = valid_task_id($_POST['task_id'] ?? null);
                $status = string_input($_POST['status'] ?? '');
                if ($taskId === null || !array_key_exists($status, TASK_STATUSES)) {
                    set_flash('error', 'Choose a valid task and status.');
                    redirect('?page=dashboard');
                }

                $statement = $db->prepare(
                    'UPDATE tasks SET status = :status WHERE id = :id AND user_id = :user_id'
                );
                $statement->execute([
                    'status' => $status,
                    'id' => $taskId,
                    'user_id' => $userId,
                ]);
                if ($statement->rowCount() === 0) {
                    $check = $db->prepare('SELECT id FROM tasks WHERE id = :id AND user_id = :user_id');
                    $check->execute(['id' => $taskId, 'user_id' => $userId]);
                    if (!$check->fetch()) {
                        set_flash('error', 'That task could not be found.');
                        redirect('?page=dashboard');
                    }
                }

                $returnSearch = substr(trim(string_input($_POST['return_search'] ?? '')), 0, 120);
                $returnStatus = string_input($_POST['return_status'] ?? '');
                $query = ['page' => 'dashboard'];
                if ($returnSearch !== '') {
                    $query['search'] = $returnSearch;
                }
                if (array_key_exists($returnStatus, TASK_STATUSES)) {
                    $query['status'] = $returnStatus;
                }
                set_flash('success', 'Task status updated to ' . TASK_STATUSES[$status] . '.');
                redirect('?' . http_build_query($query));
            } elseif ($action === 'create_task' || $action === 'update_task') {
                $formAction = $action;
                $title = string_input($_POST['title'] ?? '');
                $description = string_input($_POST['description'] ?? '');
                $status = string_input($_POST['status'] ?? 'pending');
                $errors = validate_task($title, $description, $status);
                $title = trim($title);
                $taskId = $action === 'update_task' ? valid_task_id($_POST['task_id'] ?? null) : null;

                if ($errors) {
                    $page = 'dashboard';
                    $notice = 'Please correct the highlighted fields.';
                    if ($action === 'update_task') {
                        $editForm = [
                            'id' => $taskId,
                            'title' => $title,
                            'description' => $description,
                            'status' => $status,
                        ];
                        $errors['_form'] = 'The task was not changed. Correct the fields and submit again.';
                    }
                } elseif ($action === 'create_task') {
                    $statement = $db->prepare(
                        'INSERT INTO tasks (user_id, title, description, status)
                         VALUES (:user_id, :title, :description, :status)'
                    );
                    $statement->execute([
                        'user_id' => $userId,
                        'title' => $title,
                        'description' => $description,
                        'status' => $status,
                    ]);
                    set_flash('success', 'Task added successfully.');
                    redirect('?page=dashboard');
                } else {
                    if ($taskId === null) {
                        set_flash('error', 'That task could not be found.');
                        redirect('?page=dashboard');
                    }
                    $statement = $db->prepare(
                        'UPDATE tasks
                         SET title = :title, description = :description, status = :status
                         WHERE id = :id AND user_id = :user_id'
                    );
                    $statement->execute([
                        'title' => $title,
                        'description' => $description,
                        'status' => $status,
                        'id' => $taskId,
                        'user_id' => $userId,
                    ]);
                    if ($statement->rowCount() === 0) {
                        $check = $db->prepare('SELECT id FROM tasks WHERE id = :id AND user_id = :user_id');
                        $check->execute(['id' => $taskId, 'user_id' => $userId]);
                        if (!$check->fetch()) {
                            set_flash('error', 'That task could not be found.');
                            redirect('?page=dashboard');
                        }
                    }
                    set_flash('success', 'Task updated successfully.');
                    redirect('?page=dashboard');
                }
            } else {
                $taskId = valid_task_id($_POST['task_id'] ?? null);
                if ($taskId === null) {
                    set_flash('error', 'That task could not be found.');
                    redirect('?page=dashboard');
                }
                $statement = $db->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :user_id');
                $statement->execute(['id' => $taskId, 'user_id' => $userId]);
                if ($statement->rowCount() !== 1) {
                    set_flash('error', 'That task could not be found.');
                } else {
                    set_flash('success', 'Task deleted successfully.');
                }
                redirect('?page=dashboard');
            }
        } else {
            http_response_code(400);
            $errors['_form'] = 'That action is not available.';
            $page = 'dashboard';
        }
    }

    if (!in_array($page, ['dashboard', 'login', 'register'], true)) {
        http_response_code(404);
        $page = 'login';
    }

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $isLoggedIn = $userId !== false && $userId !== null && $userId > 0;
    if ($page === 'dashboard' && !$isLoggedIn) {
        redirect('?page=login');
    }
    if (in_array($page, ['login', 'register'], true) && $isLoggedIn) {
        redirect('?page=dashboard');
    }

    if ($page === 'dashboard') {
        $db ??= database();
        $role = string_input($_SESSION['user_role'] ?? 'user');
        $user = [
            'id' => (int) $userId,
            'email' => string_input($_SESSION['user_email'] ?? ''),
            'role' => $role,
        ];
        $statsSql = 'SELECT status, COUNT(*) AS total FROM tasks';
        $statsParameters = [];
        if ($role !== 'admin') {
            $statsSql .= ' WHERE user_id = :user_id';
            $statsParameters['user_id'] = $user['id'];
        }
        $statsSql .= ' GROUP BY status';
        $statement = $db->prepare($statsSql);
        $statement->execute($statsParameters);
        foreach ($statement->fetchAll() as $row) {
            if (array_key_exists($row['status'], $taskStats)) {
                $taskStats[$row['status']] = (int) $row['total'];
            }
        }
        $search = trim(string_input($_GET['search'] ?? ''));
        if (strlen($search) > 120) {
            $search = substr($search, 0, 120);
        }
        $requestedStatus = string_input($_GET['status'] ?? '');
        $statusFilter = array_key_exists($requestedStatus, TASK_STATUSES) ? $requestedStatus : '';

        $conditions = $role === 'admin' ? ['1 = 1'] : [];
        $parameters = [];
        if ($role !== 'admin') {
            $conditions[] = 'tasks.user_id = :user_id';
            $parameters['user_id'] = $user['id'];
        }
        if ($search !== '') {
            $escapedSearch = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $conditions[] = "tasks.title LIKE :search ESCAPE '!'";
            $parameters['search'] = '%' . $escapedSearch . '%';
        }
        if ($statusFilter !== '') {
            $conditions[] = 'tasks.status = :status';
            $parameters['status'] = $statusFilter;
        }
        $statement = $db->prepare(
            'SELECT tasks.id, tasks.title, tasks.description, tasks.status, tasks.created_at, users.email AS owner_email
             FROM tasks
             JOIN users ON users.id = tasks.user_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY tasks.created_at DESC, tasks.id DESC'
        );
        $statement->execute($parameters);
        $tasks = $statement->fetchAll();
    }

    $notice ??= take_flash();
} catch (Throwable $exception) {
    error_log(sprintf(
        'Task Tracker request failed (%s) in %s:%d',
        get_class($exception),
        basename($exception->getFile()),
        $exception->getLine()
    ));
    http_response_code(500);
    $serverError = true;
    $notice = ['type' => 'error', 'message' => 'Something went wrong. Please try again later.'];
}

function render_page_start(string $title): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="A simple, secure task tracker.">
        <title><?= e($title) ?> · Task Tracker</title>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/app.js" defer></script>
    </head>
    <body>
    <?php
}

function render_status_options(string $selected): void
{
    foreach (TASK_STATUSES as $value => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            e($value),
            $selected === $value ? ' selected' : '',
            e($label)
        );
    }
}

if ($serverError) {
    render_page_start('Temporarily unavailable');
    ?>
    <main class="auth-shell">
        <section class="auth-card">
            <p class="eyebrow">Task Tracker</p>
            <h1>We could not load the app</h1>
            <p class="muted">Please check the database setup and try again.</p>
        </section>
    </main>
    </body>
    </html>
    <?php
    exit;
}

render_page_start($page === 'dashboard' ? 'Your tasks' : ucfirst($page));
?>
<header class="topbar">
    <a class="brand" href="?page=dashboard" aria-label="Task Tracker home">
        <span class="brand-mark" aria-hidden="true">T</span>
        <span>Task Tracker</span>
    </a>
    <?php if ($page === 'dashboard' && $user): ?>
        <div class="account">
            <span class="account-email"><?= e($user['email']) ?></span>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="button button-quiet" type="submit">Log out</button>
            </form>
        </div>
    <?php endif; ?>
</header>

<main class="<?= $page === 'dashboard' ? 'app-shell' : 'auth-shell' ?>">
    <?php if (is_array($notice) || is_string($notice)): ?>
        <?php $noticeType = is_array($notice) ? ($notice['type'] ?? 'info') : 'info'; ?>
        <?php $noticeMessage = is_array($notice) ? ($notice['message'] ?? '') : $notice; ?>
        <div class="notice notice-<?= e((string) $noticeType) ?>" role="status"><?= e((string) $noticeMessage) ?></div>
    <?php endif; ?>

    <?php if (isset($errors['_form'])): ?>
        <div class="notice notice-error" role="alert"><?= e($errors['_form']) ?></div>
    <?php endif; ?>

    <?php if ($page === 'login' || $page === 'register'): ?>
        <div class="auth-layout">
            <section class="auth-aside">
                <span class="auth-orbit auth-orbit-one" aria-hidden="true"></span>
                <span class="auth-orbit auth-orbit-two" aria-hidden="true"></span>
                <p class="eyebrow">A calmer way to get things done</p>
                <h2><?= $page === 'login' ? 'Pick up where you left off.' : 'Make room for what matters.' ?></h2>
                <p class="auth-aside-copy">One thoughtful list. A little progress, whenever you are ready.</p>
                <ul class="auth-highlights">
                    <li><span aria-hidden="true">01</span> Keep every task in one place</li>
                    <li><span aria-hidden="true">02</span> See what needs your attention</li>
                    <li><span aria-hidden="true">03</span> Track your progress at your pace</li>
                </ul>
                <div class="auth-sparkle" aria-hidden="true">✦</div>
            </section>
            <section class="auth-card">
            <p class="eyebrow"><?= $page === 'login' ? 'Welcome back' : 'Get started' ?></p>
            <h1><?= $page === 'login' ? 'Sign in to your tasks' : 'Create your account' ?></h1>
            <p class="muted">Keep your work organised, one task at a time.</p>
            <form method="post" class="stack-form auth-form" data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= e($page) ?>">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="254"
                       value="<?= e($old['email']) ?>" required>
                <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?>
                <label for="password">Password</label>
                <div class="password-control">
                    <input id="password" name="password" type="password" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>"
                           <?= $page === 'register' ? 'minlength="12" maxlength="72" required' : 'required' ?>>
                    <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
                <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif; ?>
                <?php if ($page === 'register'): ?>
                    <div class="password-meter" data-password-meter hidden>
                        <div class="password-meter-track"><span></span></div>
                        <small class="muted" aria-live="polite"></small>
                    </div>
                    <small class="muted password-hint">Use 12–72 bytes. Your password is stored as a secure hash.</small>
                    <label for="password-confirmation">Confirm password</label>
                    <div class="password-control">
                        <input id="password-confirmation" name="password_confirmation" type="password"
                               autocomplete="new-password" minlength="12" maxlength="72" required>
                        <button class="password-toggle" type="button" data-password-toggle="password-confirmation" aria-label="Show confirmation password" aria-pressed="false">Show</button>
                    </div>
                    <?php if (isset($errors['password_confirmation'])): ?><small class="field-error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>
                <?php endif; ?>
                <button class="button button-primary button-full auth-submit" type="submit" data-loading-label="<?= $page === 'login' ? 'Signing in…' : 'Creating account…' ?>">
                    <?= $page === 'login' ? 'Sign in' : 'Create account' ?>
                </button>
            </form>
            <p class="auth-switch">
                <?= $page === 'login' ? 'New here?' : 'Already have an account?' ?>
                <a href="?page=<?= $page === 'login' ? 'register' : 'login' ?>">
                    <?= $page === 'login' ? 'Create an account' : 'Sign in' ?>
                </a>
            </p>
            </section>
        </div>
    <?php else: ?>
        <section class="page-heading">
            <div>
                <p class="eyebrow"><?= $user['role'] === 'admin' ? 'Administrator · read-only' : 'Your personal workspace' ?></p>
                <h1><?= $user['role'] === 'admin' ? 'All tasks' : 'My tasks' ?></h1>
                <p class="muted"><?= $user['role'] === 'admin' ? 'Review tasks across accounts. Administrator access is read-only.' : 'A little progress adds up. What would you like to get done?' ?></p>
            </div>
            <a class="task-count" href="?page=dashboard">
                <?= array_sum($taskStats) ?> <?= array_sum($taskStats) === 1 ? 'task' : 'tasks' ?> <?= $user['role'] === 'admin' ? 'across all accounts' : 'in your workspace' ?>
            </a>
        </section>

        <section class="summary-grid" aria-label="Task status summary">
            <?php
            $summaryCards = [
                ['label' => 'All tasks', 'status' => '', 'count' => array_sum($taskStats), 'class' => 'summary-all', 'icon' => '✳'],
                ['label' => 'Pending', 'status' => 'pending', 'count' => $taskStats['pending'], 'class' => 'summary-pending', 'icon' => '◷'],
                ['label' => 'In progress', 'status' => 'in_progress', 'count' => $taskStats['in_progress'], 'class' => 'summary-progress', 'icon' => '↗'],
                ['label' => 'Completed', 'status' => 'completed', 'count' => $taskStats['completed'], 'class' => 'summary-completed', 'icon' => '✓'],
            ];
            foreach ($summaryCards as $summaryCard):
                $summaryQuery = ['page' => 'dashboard'];
                if ($search !== '') {
                    $summaryQuery['search'] = $search;
                }
                if ($summaryCard['status'] !== '') {
                    $summaryQuery['status'] = $summaryCard['status'];
                }
                $summaryHref = '?' . http_build_query($summaryQuery);
            ?>
                <a class="summary-card <?= e($summaryCard['class']) ?>"
                   href="<?= e($summaryHref) ?>"
                   <?= $statusFilter === $summaryCard['status'] ? 'aria-current="page"' : '' ?>>
                    <span class="summary-icon" aria-hidden="true"><?= e($summaryCard['icon']) ?></span>
                    <span class="summary-label"><?= e($summaryCard['label']) ?></span>
                    <span class="summary-count"><?= (int) $summaryCard['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="toolbar" aria-label="Search and filter tasks">
            <form method="get" class="filter-form" data-live-filter>
                <input type="hidden" name="page" value="dashboard">
                <label class="sr-only" for="search">Search task titles</label>
                <input id="search" name="search" type="search" maxlength="120"
                       placeholder="Search task titles..." value="<?= e($search) ?>" data-live-search
                       <?= $search !== '' ? 'autofocus' : '' ?>>
                <label class="sr-only" for="status-filter">Filter by status</label>
                <select id="status-filter" name="status" data-live-status>
                    <option value="">All statuses</option>
                    <?php render_status_options($statusFilter); ?>
                </select>
                <button class="button button-secondary filter-submit" type="submit">Filter tasks</button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a class="button button-quiet clear-link" href="?page=dashboard">Clear filters</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="content-grid<?= $user['role'] === 'admin' ? ' content-grid-readonly' : '' ?>">
            <?php if ($user['role'] !== 'admin'): ?>
            <aside class="panel create-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Make it happen</p>
                        <h2>Add a task</h2>
                    </div>
                    <span class="panel-icon" aria-hidden="true">+</span>
                </div>
                <form method="post" class="stack-form" data-task-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_task">
                    <label for="new-title">Title <span class="required">*</span></label>
                    <input id="new-title" name="title" type="text" maxlength="120" required
                           aria-describedby="new-title-error"
                           value="<?= $formAction === 'create_task' ? e($title ?? '') : '' ?>"
                           placeholder="e.g. Plan the week" data-count-input>
                    <small class="character-count" data-count-for="new-title" aria-live="polite">0 / 120</small>
                    <small id="new-title-error" class="field-error" data-validation-for="title" role="alert"
                           <?= !($formAction === 'create_task' && isset($errors['title'])) ? 'hidden' : '' ?>><?= $formAction === 'create_task' ? e($errors['title'] ?? '') : '' ?></small>
                    <label for="new-description">Description <span class="optional">(optional)</span></label>
                    <textarea id="new-description" name="description" rows="4" maxlength="10000"
                              placeholder="Add a few helpful details..." data-count-input data-count-bytes><?= $formAction === 'create_task' ? e($description ?? '') : '' ?></textarea>
                    <small class="character-count" data-count-for="new-description" aria-live="polite">0 / 10,000</small>
                    <?php if ($formAction === 'create_task' && isset($errors['description'])): ?><small class="field-error"><?= e($errors['description']) ?></small><?php endif; ?>
                    <label for="new-status">Status</label>
                    <select id="new-status" name="status">
                        <?php render_status_options($formAction === 'create_task' ? (string) ($status ?? 'pending') : 'pending'); ?>
                    </select>
                    <?php if ($formAction === 'create_task' && isset($errors['status'])): ?><small class="field-error"><?= e($errors['status']) ?></small><?php endif; ?>
                    <button class="button button-primary" type="submit">Add task <span aria-hidden="true">→</span></button>
                </form>
            </aside>
            <?php endif; ?>

            <section class="task-list" aria-label="Task list">
                <?php if (!$tasks): ?>
                    <div class="empty-state">
                        <span class="empty-icon" aria-hidden="true">✓</span>
                        <h2><?= $search !== '' || $statusFilter !== '' ? 'No matching tasks' : 'A clear space to start' ?></h2>
                        <p class="muted">
                            <?= $search !== '' || $statusFilter !== ''
                                ? 'Try another title or status filter.'
                                : 'Add your first task and make today count.' ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <article class="task-card">
                            <div class="task-card-top">
                                <span class="status-badge status-<?= e($task['status']) ?>">
                                    <span class="status-dot" aria-hidden="true"></span>
                                    <?= e(TASK_STATUSES[$task['status']]) ?>
                                </span>
                                <time class="task-date" datetime="<?= e($task['created_at']) ?>">
                                    <?= e(date('M j, Y', strtotime($task['created_at']))) ?>
                                </time>
                            </div>
                            <?php if ($user['role'] === 'admin'): ?><p class="task-owner">Owner: <?= e($task['owner_email']) ?></p><?php endif; ?>
                            <h2><?= e($task['title']) ?></h2>
                            <?php if ($task['description'] !== ''): ?>
                                <p class="task-description"><?= nl2br(e($task['description']), false) ?></p>
                            <?php else: ?>
                                <p class="task-description muted">No description provided.</p>
                            <?php endif; ?>
                            <?php if ($user['role'] !== 'admin'): ?>
                            <form method="post" class="quick-status-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_task_status">
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <input type="hidden" name="return_search" value="<?= e($search) ?>">
                                <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                <label for="quick-status-<?= (int) $task['id'] ?>">Move to</label>
                                <select id="quick-status-<?= (int) $task['id'] ?>" name="status"
                                        aria-label="Change status for <?= e($task['title']) ?>" data-quick-status>
                                    <?php render_status_options($task['status']); ?>
                                </select>
                                <button class="button button-secondary quick-status-button" type="submit">Update status</button>
                                <span class="quick-status-hint" aria-hidden="true">Choose a status to update</span>
                            </form>
                            <?php $isFailedEdit = isset($editForm['id']) && $editForm['id'] === (int) $task['id']; ?>
                            <details class="task-edit"<?= $isFailedEdit ? ' open' : '' ?>>
                                <summary>Edit task</summary>
                                <form method="post" class="stack-form edit-form" data-task-form novalidate>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_task">
                                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                    <label for="title-<?= (int) $task['id'] ?>">Title</label>
                                    <input id="title-<?= (int) $task['id'] ?>" name="title" type="text" maxlength="120"
                                           value="<?= e($isFailedEdit ? $editForm['title'] : $task['title']) ?>" required data-count-input
                                           aria-describedby="title-error-<?= (int) $task['id'] ?>">
                                    <small class="character-count" data-count-for="title-<?= (int) $task['id'] ?>" aria-live="polite"></small>
                                    <small id="title-error-<?= (int) $task['id'] ?>" class="field-error" data-validation-for="title" role="alert"
                                           <?= !($isFailedEdit && isset($errors['title'])) ? 'hidden' : '' ?>><?= $isFailedEdit ? e($errors['title'] ?? '') : '' ?></small>
                                    <label for="description-<?= (int) $task['id'] ?>">Description</label>
                                    <textarea id="description-<?= (int) $task['id'] ?>" name="description"
                                              rows="3" maxlength="10000" data-count-input data-count-bytes><?= e($isFailedEdit ? $editForm['description'] : $task['description']) ?></textarea>
                                    <small class="character-count" data-count-for="description-<?= (int) $task['id'] ?>" aria-live="polite"></small>
                                    <?php if ($isFailedEdit && isset($errors['description'])): ?><small class="field-error"><?= e($errors['description']) ?></small><?php endif; ?>
                                    <label for="status-<?= (int) $task['id'] ?>">Status</label>
                                    <select id="status-<?= (int) $task['id'] ?>" name="status">
                                        <?php render_status_options($isFailedEdit ? $editForm['status'] : $task['status']); ?>
                                    </select>
                                    <?php if ($isFailedEdit && isset($errors['status'])): ?><small class="field-error"><?= e($errors['status']) ?></small><?php endif; ?>
                                    <button class="button button-secondary" type="submit">Save changes</button>
                                </form>
                                <details class="delete-confirmation">
                                    <summary>Delete task</summary>
                                    <p>This cannot be undone.</p>
                                    <form method="post" class="delete-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                        <button class="button button-danger-quiet" type="submit">Confirm delete</button>
                                    </form>
                                </details>
                            </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </section>
    <?php endif; ?>
</main>
<footer class="footer">Built for focused work <span aria-hidden="true">·</span> Your tasks are private to your account</footer>
</body>
</html>
