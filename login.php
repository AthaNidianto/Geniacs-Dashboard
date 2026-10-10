<?php
require_once __DIR__ . '/config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
    } else {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                redirect('/dashboard.php');
            } else {
                $error = 'Username atau password salah';
            }
        } else {
            $error = 'Username atau password salah';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="icon" href="/favicon.ico?v=1" sizes="any">
    <link rel="icon" href="/assets/img/favicon-32.png?v=1" type="image/png" sizes="32x32">
    <link rel="icon" href="/assets/img/favicon-64.png?v=1" type="image/png" sizes="64x64">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png?v=1">
    <style>
        .login-container {
            padding-bottom: 80px;
            /* Give space for footer */
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <!-- logo beranimasi: ikon wifi muncul di tengah, geser ke kiri sambil wordmark "eratel" terbuka dari kiri -->
                <img src="/assets/img/eratel-anim.svg?v=1" alt="Eratel"
                    style="display:block; width:100%; max-width:300px; margin: 0 auto;">
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">
                        <i class="bi bi-person"></i> Username
                    </label>
                    <input type="text" name="username" id="username" class="form-control"
                        placeholder="Masukkan username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="bi bi-lock"></i> Password
                    </label>
                    <input type="password" name="password" id="password" class="form-control"
                        placeholder="Masukkan password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>

        </div>
    </div>

    <div class="footer" style="position: fixed; bottom: 0; width: 100%; background: transparent; color: white;">
        Made by <a href="https://github.com/safrinnetwork/" target="_blank" style="color: white;">Mostech</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>