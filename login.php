<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$error = null;
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if ($next === '' || str_contains($next, 'login.php')) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $pdo = authDb();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) ($user['password'] ?? ''))) {
                $error = 'Invalid credentials.';
            } else {
                loginUser($user);
                header('Location: ' . $next);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Login is unavailable. Check DB connection and users table.';
        }
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - PR Converter</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="min-h-screen flex items-center justify-center px-4">
        <section class="w-full max-w-md rounded-2xl bg-white border border-slate-200 shadow-sm p-6">
            <h1 class="text-2xl font-semibold">Sign In</h1>
            <p class="mt-1 text-sm text-slate-600">Use your account from the `users` table.</p>

            <?php if ($error !== null): ?>
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-5 space-y-4">
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                    <input id="username" name="username" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <input id="password" name="password" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Login</button>
            </form>
        </section>
    </main>
</body>
</html>
