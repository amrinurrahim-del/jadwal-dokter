<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/* Kalau sudah login, langsung ke halaman tujuannya. */
if (auth_user()) {
    redirect(next_aman($_GET['next'] ?? null));
}

$next  = next_aman($_GET['next'] ?? ($_POST['next'] ?? null));
$error = '';
$info  = '';
$isi   = ['username' => ''];

if (isset($_GET['keluar'])) {
    $info = 'Anda sudah keluar dari aplikasi.';
}
if (isset($_GET['reset'])) {
    $info = 'Username / kata sandi berhasil diubah. Silakan masuk dengan data baru.';
}

if (is_post()) {
    $isi['username'] = trim((string) ($_POST['username'] ?? ''));
    $hasil = auth_login($isi['username'], (string) ($_POST['password'] ?? ''));
    if ($hasil['ok']) {
        redirect($next . '?ok=' . urlencode('Selamat datang, ' . $hasil['user']['username'] . '.' . ($hasil['user']['role'] === 'admin' ? ' Anda masuk sebagai Admin Poli (hanya Input Jadwal).' : ' Anda masuk sebagai Superadmin (akses penuh).')));
    }
    $error = $hasil['error'];
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk — Jadwal Dokter</title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="auth">
<div class="auth-wrap">
    <form class="auth-card" method="post" action="login.php<?= $next !== 'input.php' ? '?next=' . h($next) : '' ?>">
        <div class="auth-brand">
            <span class="brand-dot"></span>
            <span class="auth-brand-text">Jadwal Dokter<span class="brand-sub">Sistem Display Rumah Sakit</span></span>
        </div>

        <h1 class="auth-title">Masuk ke Aplikasi</h1>
        <p class="auth-desc">Masukkan username dan kata sandi akun Anda.</p>

        <?php if ($info !== ''): ?>
            <div class="flash flash-ok"><?= h($info) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="flash flash-err"><?= h($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="next" value="<?= h($next) ?>">

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($isi['username']) ?>" autocomplete="username" autofocus required>
        </div>
        <div class="field">
            <label for="password">Kata Sandi</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>

        <button class="btn btn-primary btn-block" type="submit">Masuk</button>

        <a class="auth-forgot" href="lupa.php">Lupa username atau kata sandi?</a>

        <p class="auth-foot">
            Akses dibedakan per akun: <strong>Admin Poli</strong> hanya dapat mengisi jadwal,
            <strong>Superadmin</strong> memiliki akses penuh ke seluruh menu.
        </p>
    </form>
</div>
</body>
</html>
