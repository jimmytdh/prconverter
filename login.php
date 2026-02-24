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
    <link rel="icon" type="image/png" href="src/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen text-slate-900 bg-[radial-gradient(circle_at_top_left,_#a7f3d0,_transparent_42%),radial-gradient(circle_at_bottom_right,_#fde68a,_transparent_45%),linear-gradient(180deg,_#f8fafc_0%,_#edf2f7_100%)]">
    <main class="min-h-screen flex items-center justify-center px-4 py-8">
        <section class="w-full max-w-5xl overflow-hidden rounded-3xl border border-white/80 bg-white/90 shadow-[0_24px_70px_rgba(15,23,42,0.18)] backdrop-blur">
            <div class="grid md:grid-cols-2">
                <div class="relative bg-slate-900 p-8 md:p-10 text-white">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(20,184,166,0.35),_transparent_55%)]"></div>
                    <div class="relative">
                        <div class="inline-flex items-center gap-3 rounded-xl border border-white/20 bg-white/10 px-3 py-2">
                            <img src="src/logo.png" alt="CSMC Logo" class="h-10 w-10 rounded-full bg-white p-1">
                            <div>
                                <p class="text-xs uppercase tracking-[0.2em] text-teal-200">CSMC</p>
                                <p class="text-sm font-medium">Purchase Request System</p>
                            </div>
                        </div>
                        <h1 class="mt-8 text-3xl font-semibold leading-tight">Professional PR Processing Platform</h1>
                        <p class="mt-3 text-sm text-slate-200 leading-relaxed">
                            Securely access document conversion, extraction, and structured item management in one streamlined workspace.
                        </p>
                        <div class="mt-8 space-y-3 text-sm text-slate-200">
                            <p class="rounded-lg border border-white/20 bg-white/5 px-3 py-2">Structured PDF extraction with itemized validation</p>
                            <p class="rounded-lg border border-white/20 bg-white/5 px-3 py-2">Centralized records and export-ready outputs</p>
                        </div>
                    </div>
                </div>

                <div class="p-8 md:p-10 bg-white">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-semibold text-slate-900">Sign In</h2>
                        <img src="src/logo.png" alt="CSMC Logo" class="h-12 w-12 object-contain rounded-full border border-slate-200 p-1">
                    </div>
                    <p class="mt-1 text-sm text-slate-600">Use your hospital account credentials.</p>

                    <?php if ($error !== null): ?>
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="mt-6 space-y-4">
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <div>
                            <label for="username" class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                            <input id="username" name="username" type="text" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm transition focus:border-teal-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-100" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                        </div>
                        <div>
                            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                            <input id="password" name="password" type="password" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm transition focus:border-teal-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Login</button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
