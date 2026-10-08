<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/*
 * Halaman konfirmasi developer (dibuka dari tautan yang dikirim ke amrinurrahim@gmail.com).
 * Token sekali pakai, berlaku 60 menit. Developer bisa melihat username saat ini
 * (membantu kasus "lupa username") lalu menetapkan username / kata sandi baru.
 */

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
if ($token !== '' && !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token = '';
}

$permintaan = auth_verifikasi_reset($token);
$error      = '';
$sukses     = null;

if (is_post() && ($_POST['action'] ?? '') === 'simpan') {
    if (!$permintaan) {
        $error = 'Tautan reset tidak valid, sudah dipakai, atau sudah kedaluwarsa. Minta tautan baru dari halaman lupa password.';
    } else {
        $hasil = auth_pakai_reset(
            $token,
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_ulang'] ?? '')
        );
        if ($hasil['ok']) {
            $sukses     = $hasil;
            $permintaan = null;
        } else {
            $error = $hasil['error'];
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Konfirmasi Reset Akun — Jadwal Dokter</title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="auth">
<div class="auth-wrap auth-wrap-wide">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand-dot"></span>
            <span class="auth-brand-text">Jadwal Dokter<span class="brand-sub">Halaman Konfirmasi Developer</span></span>
        </div>

        <?php if ($sukses): ?>
            <h1 class="auth-title">Perubahan Berhasil Disimpan</h1>
            <div class="flash flash-ok">
                Akun <strong><?= h(label_role($sukses['role'])) ?></strong> sudah diperbarui menjadi
                username <strong><?= h($sukses['username']) ?></strong>.
                Tautan reset ini sudah terpakai dan tidak bisa digunakan lagi.
            </div>
            <p class="auth-desc">
                Sampaikan username baru tersebut kepada pengguna aplikasi, lalu minta dia masuk
                memakai kata sandi yang baru ditetapkan.
            </p>
            <a class="btn btn-primary btn-block" href="login.php">Buka Halaman Masuk</a>

        <?php elseif ($permintaan): ?>
            <h1 class="auth-title">Konfirmasi Reset Akun</h1>
            <p class="auth-desc">
                Halaman ini hanya untuk <strong>developer</strong> (<?= h(EMAIL_DEVELOPER) ?>).
                Periksa dulu bahwa permintaan ini benar-benar dari pengelola aplikasi sebelum menyimpan.
            </p>

            <?php if ($error !== ''): ?>
                <div class="flash flash-err"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="info-grid">
                <div>
                    <span class="info-label">Akun</span>
                    <span class="info-nilai"><?= h(label_role($permintaan['role'])) ?></span>
                </div>
                <div>
                    <span class="info-label">Username saat ini</span>
                    <span class="info-nilai"><?= h($permintaan['username']) ?></span>
                </div>
                <div>
                    <span class="info-label">Tautan berlaku sampai</span>
                    <span class="info-nilai"><?= h(date('d/m/Y H:i', strtotime($permintaan['expires_at']))) ?> WITA</span>
                </div>
            </div>

            <form method="post" action="reset.php">
                <input type="hidden" name="action" value="simpan">
                <input type="hidden" name="token" value="<?= h($token) ?>">

                <div class="field">
                    <label for="username">Username Baru</label>
                    <input type="text" id="username" name="username" value="<?= h($permintaan['username']) ?>"
                           pattern="[A-Za-z0-9._\-]{3,32}" title="3-32 karakter: huruf, angka, titik, garis bawah, atau strip" required>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="password">Kata Sandi Baru</label>
                        <input type="password" id="password" name="password" minlength="4" autocomplete="new-password" required>
                    </div>
                    <div class="field">
                        <label for="password_ulang">Ulangi Kata Sandi Baru</label>
                        <input type="password" id="password_ulang" name="password_ulang" minlength="4" autocomplete="new-password" required>
                    </div>
                </div>

                <button class="btn btn-primary btn-block" type="submit">Simpan Username &amp; Kata Sandi Baru</button>
            </form>

            <div class="warn-box" style="margin-top:20px">
                Setelah disimpan, tautan ini otomatis tidak berlaku lagi dan semua sesi login akun tersebut
                diputuskan, sehingga wajib masuk ulang dengan data baru.
            </div>

        <?php else: ?>
            <h1 class="auth-title">Tautan Tidak Berlaku</h1>
            <p class="auth-desc">
                Tautan reset ini tidak valid, sudah dipakai, atau sudah kedaluwarsa.
                Minta pengelola aplikasi membuat tautan baru dari halaman lupa username / kata sandi.
            </p>

            <?php if ($error !== ''): ?>
                <div class="flash flash-err"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="get" action="reset.php">
                <div class="field">
                    <label for="tokenManual">Atau tempel kode tautan reset secara manual</label>
                    <input type="text" id="tokenManual" name="token" placeholder="Tempel kode token di sini" required>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Periksa Kode</button>
            </form>
        <?php endif; ?>

        <a class="auth-forgot" href="lupa.php">Buat tautan reset baru</a>
    </div>
</div>
</body>
</html>
