<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/*
 * Lupa username / kata sandi.
 *
 * Server ini tidak dapat mengirim email (tidak ada layanan email keluar), jadi alurnya:
 *   1. Pengguna memilih akun, aplikasi membuat TAUTAN RESET sekali-pakai (berlaku 60 menit).
 *   2. Tautan ditampilkan di layar ini untuk disalin dan dikirim manual ke developer
 *      (tombol Email / WhatsApp sudah terisi otomatis ke amrinurrahim@gmail.com).
 *   3. Developer membuka tautan tersebut di reset.php untuk melihat username saat ini
 *      dan menetapkan username / kata sandi baru.
 */

$hasil    = null;   // tautan yang baru dibuat
$error    = '';

if (is_post() && ($_POST['action'] ?? '') === 'minta') {
    $role = (string) ($_POST['role'] ?? '');
    $user = in_array($role, ['admin', 'superadmin'], true) ? user_by_role($role) : null;
    if (!$user) {
        $error = 'Akun yang dipilih tidak ditemukan.';
    } else {
        $reset = auth_buat_reset((int) $user['id']);
        $link  = auth_link_reset($reset['token']);
        $hasil = [
            'role'     => $role,
            'link'     => $link,
            'menit'    => (int) $reset['berlaku_menit'],
            'token'    => $reset['token'],
        ];
    }
}

$judul = 'Lupa Username / Kata Sandi';
$pesan = 'Pilih akun yang perlu direset. Aplikasi akan membuat tautan reset yang hanya berlaku sekali pakai.';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($judul) ?> — Jadwal Dokter</title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="auth">
<div class="auth-wrap auth-wrap-wide">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand-dot"></span>
            <span class="auth-brand-text">Jadwal Dokter<span class="brand-sub">Sistem Display Rumah Sakit</span></span>
        </div>

        <h1 class="auth-title"><?= h($judul) ?></h1>
        <p class="auth-desc"><?= h($pesan) ?></p>

        <?php if ($error !== ''): ?>
            <div class="flash flash-err"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($hasil): ?>
            <div class="flash flash-ok">
                Tautan reset berhasil dibuat. <strong>Kirimkan tautan di bawah ini ke developer
                (<?= h(EMAIL_DEVELOPER) ?>)</strong> — hanya developer yang dapat membukanya untuk
                mengonfirmasi perubahan username / kata sandi.
            </div>

            <div class="field">
                <label for="linkReset">Tautan reset akun <?= h(label_role($hasil['role'])) ?></label>
                <input type="text" id="linkReset" value="<?= h($hasil['link']) ?>" readonly onclick="this.select()">
                <p class="small">Berlaku <strong><?= (int) $hasil['menit'] ?> menit</strong> dan hanya dapat dipakai satu kali.
                   Setelah dipakai, link ini otomatis tidak berlaku lagi.</p>
            </div>

            <div class="btn-row">
                <button class="btn btn-primary" type="button" id="btnSalin" data-salin="<?= h($hasil['link']) ?>">Salin Tautan</button>
                <a class="btn btn-ghost"
                   href="mailto:<?= h(EMAIL_DEVELOPER) ?>?subject=<?= rawurlencode('Permintaan reset akun jadwal dokter (' . label_role($hasil['role']) . ')') ?>&body=<?= rawurlencode("Halo developer,\n\nMohon konfirmasi perubahan username / kata sandi akun " . label_role($hasil['role']) . " aplikasi Jadwal Dokter.\n\nBuka tautan berikut untuk menetapkan username dan kata sandi baru:\n" . $hasil['link'] . "\n\nTautan berlaku " . $hasil['menit'] . " menit dan hanya sekali pakai.\n\nTerima kasih.") ?>">
                    Kirim via Email
                </a>
                <a class="btn btn-ghost" target="_blank" rel="noopener"
                   href="https://wa.me/?text=<?= rawurlencode("Permintaan reset akun " . label_role($hasil['role']) . " Jadwal Dokter. Buka tautan ini untuk menetapkan username & kata sandi baru (berlaku " . $hasil['menit'] . " menit, sekali pakai): " . $hasil['link']) ?>">
                    Kirim via WhatsApp
                </a>
            </div>

            <div class="warn-box" style="margin-top:20px">
                <strong>Catatan keamanan:</strong> developer hanya memproses permintaan yang benar-benar
                diminta oleh pengelola aplikasi. Bila Anda tidak meminta reset, abaikan halaman ini —
                tidak ada data yang berubah sampai developer membuka tautannya.
            </div>

            <hr class="divider">
            <form method="post" action="lupa.php">
                <input type="hidden" name="action" value="minta">
                <input type="hidden" name="role" value="<?= h($hasil['role']) ?>">
                <button class="btn btn-ghost" type="submit">Buat Tautan Baru</button>
            </form>
        <?php else: ?>
            <form method="post" action="lupa.php">
                <input type="hidden" name="action" value="minta">
                <div class="field">
                    <label for="role">Akun yang ingin direset</label>
                    <select id="role" name="role" required>
                        <option value="">— pilih akun —</option>
                        <option value="admin">Admin Poli (akses Input Jadwal)</option>
                        <option value="superadmin">Superadmin (akses penuh)</option>
                    </select>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Buat Tautan Reset</button>
            </form>

            <p class="small" style="margin-top:16px">
                Tautan reset berlaku 60 menit dan hanya bisa dipakai satu kali. Untuk keamanan,
                username akun tidak ditampilkan di halaman ini — developer akan melihatnya saat
                membuka tautan konfirmasi.
            </p>
        <?php endif; ?>

        <a class="auth-forgot" href="login.php">&#8592; Kembali ke halaman masuk</a>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('#btnSalin');
    if (!btn) { return; }
    var teks = btn.getAttribute('data-salin');
    var selesai = function () {
        var asli = btn.textContent;
        btn.textContent = 'Tersalin!';
        setTimeout(function () { btn.textContent = asli; }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(teks).then(selesai, function () { pilihManual(); });
    } else {
        pilihManual();
    }
    function pilihManual() {
        var inp = document.getElementById('linkReset');
        inp.select();
        try { document.execCommand('copy'); selesai(); } catch (err) { alert('Salin manual: ' + teks); }
    }
});
</script>
</body>
</html>
