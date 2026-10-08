<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_superadmin();   // hanya superadmin

const LOGO_EXT_OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    /* ---------------- Ubah akun (username / kata sandi) ---------------- */
    if ($action === 'akun_simpan') {
        $id     = (int) ($_POST['user_id'] ?? 0);
        $target = null;
        foreach (daftar_user() as $u) {
            if ((int) $u['id'] === $id) {
                $target = $u;
            }
        }
        if (!$target) {
            redirect('pengaturan.php?err=' . urlencode('Akun tidak ditemukan.'));
        }
        $hasil = auth_ubah_kredensial(
            $id,
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_ulang'] ?? ''),
            (string) ($_POST['pengawas'] ?? '')
        );
        if (!$hasil['ok']) {
            redirect('pengaturan.php?err=' . urlencode($hasil['error']));
        }
        $pesan = 'Akun ' . label_role($target['role']) . ' berhasil diperbarui.';
        if ((string) ($_POST['password'] ?? '') !== '') {
            $pesan .= ' Kata sandi baru sudah aktif dan sesi login lain pada akun tersebut diputuskan.';
        }
        if ($id === (int) $user['id']) {
            $pesan .= ' Silakan login ulang bila diminta.';
        }
        redirect('pengaturan.php?ok=' . urlencode($pesan));
    }

    /* ---------------- Simpan pengaturan ---------------- */
    if ($action === 'simpan') {
        set_setting('nama_rs', trim((string) ($_POST['nama_rs'] ?? 'Rumah Sakit')));
        set_setting('footer_teks', trim((string) ($_POST['footer_teks'] ?? '')));
        $cepat = (int) ($_POST['kecepatan_gulir'] ?? 40);
        set_setting('kecepatan_gulir', (string) max(5, min(200, $cepat)));
        $refresh = (int) ($_POST['refresh_detik'] ?? 10);
        set_setting('refresh_detik', (string) max(3, min(300, $refresh)));

        /* Catatan: tanggal tayang display (pengaturan `tanggal_aktif`) TIDAK diubah di sini,
           karena pengaturannya sudah dipindahkan ke menu Input Jadwal. Jangan menimpanya,
           supaya menyimpan Pengaturan tidak mengembalikan tanggal tayang ke hari ini. */

        redirect('pengaturan.php?ok=' . urlencode('Pengaturan disimpan. Display mengikuti pada refresh berikutnya.'));
    }

    /* ---------------- Unggah logo ---------------- */
    if ($action === 'upload_logo') {
        if (empty($_FILES['logo']['tmp_name']) || (int) ($_FILES['logo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            redirect('pengaturan.php?err=' . urlencode('Pilih file logo terlebih dahulu.'));
        }
        $nama = (string) ($_FILES['logo']['name'] ?? 'logo.png');
        $ext  = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        if (!in_array($ext, LOGO_EXT_OK, true)) {
            redirect('pengaturan.php?err=' . urlencode('Format logo harus gambar (jpg, png, gif, webp, atau svg).'));
        }
        if ((int) $_FILES['logo']['size'] > 15 * 1024 * 1024) {
            redirect('pengaturan.php?err=' . urlencode('Ukuran logo maksimal 15 MB.'));
        }
        $hasil = media_upload((string) $_FILES['logo']['tmp_name'], $nama);
        if (!$hasil['ok']) {
            redirect('pengaturan.php?err=' . urlencode('Logo gagal diunggah: ' . $hasil['error']));
        }
        set_setting('logo_url', $hasil['url']);
        redirect('pengaturan.php?ok=' . urlencode('Logo rumah sakit berhasil diperbarui.'));
    }

    /* ---------------- Hapus logo ---------------- */
    if ($action === 'hapus_logo') {
        set_setting('logo_url', '');
        redirect('pengaturan.php?ok=' . urlencode('Logo dihapus dari display.'));
    }
}

$s          = all_settings();
$tanggalDis = tanggal_display();
$tokenAda   = media_token() !== '';
$users      = daftar_user();
$bawaan     = password_masih_bawaan();

page_head('Pengaturan', 'pengaturan.php');
?>

<h1 class="page-title">Pengaturan</h1>
<p class="page-desc">
    Kelola akun pengguna, identitas rumah sakit, teks berjalan, kecepatan gulir, dan tanggal jadwal yang
    ditampilkan. Semua perubahan langsung dipakai layar display pada refresh berikutnya.
</p>

<?php flash(); ?>

<?php if ($bawaan): ?>
    <div class="warn-box">
        <strong>Peringatan keamanan:</strong> akun berikut masih memakai data bawaan —
        <?= h(implode(', ', $bawaan)) ?>.
        Segera ubah username / kata sandinya pada bagian <strong>Akun &amp; Keamanan</strong> di bawah,
        karena data bawaan ini tertulis di dokumentasi dan mudah ditebak.
    </div>
<?php endif; ?>

<div class="card">
    <h2>Akun &amp; Keamanan <span class="hint">— ubah username / kata sandi</span></h2>
    <p class="card-sub">
        Terdapat 2 akun tetap: <strong>Superadmin</strong> (akses penuh) dan <strong>Admin Poli</strong>
        (hanya menu Input Jadwal; menu lain terkunci). Kosongkan kolom kata sandi bila hanya ingin
        mengubah username.
    </p>

    <?php foreach ($users as $u): ?>
        <div class="list-head">
            <span><?= h(label_role($u['role'])) ?></span>
            <span class="count">
                <?= h($u['username']) ?>
                <?= $u['updated_at'] !== '' ? ' · diubah ' . h(date('d/m/Y H:i', strtotime((string) $u['updated_at']))) : '' ?>
            </span>
        </div>
        <div class="table-wrap" style="padding:18px">
            <form method="post" action="pengaturan.php" id="formAkun<?= (int) $u['id'] ?>">
                <input type="hidden" name="action" value="akun_simpan">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">

                <div class="grid-3">
                    <div class="field">
                        <label for="username_<?= (int) $u['id'] ?>">Username</label>
                        <input type="text" id="username_<?= (int) $u['id'] ?>" name="username"
                               value="<?= h($u['username']) ?>" pattern="[A-Za-z0-9._\-]{3,32}"
                               title="3-32 karakter: huruf, angka, titik, garis bawah, atau strip" required>
                    </div>
                    <div class="field">
                        <label for="password_<?= (int) $u['id'] ?>">Kata Sandi Baru</label>
                        <input type="password" id="password_<?= (int) $u['id'] ?>" name="password"
                               minlength="4" autocomplete="new-password" placeholder="biarkan kosong = tidak diubah">
                    </div>
                    <div class="field">
                        <label for="password_ulang_<?= (int) $u['id'] ?>">Ulangi Kata Sandi Baru</label>
                        <input type="password" id="password_ulang_<?= (int) $u['id'] ?>" name="password_ulang"
                               minlength="4" autocomplete="new-password" placeholder="ulangi kata sandi baru">
                    </div>
                </div>

                <div class="field" style="max-width:420px">
                    <label for="pengawas_<?= (int) $u['id'] ?>">Konfirmasi Kata Sandi Superadmin Anda</label>
                    <input type="password" id="pengawas_<?= (int) $u['id'] ?>" name="pengawas"
                           autocomplete="current-password" placeholder="wajib diisi untuk menyimpan" required>
                    <p class="small">Demi keamanan, setiap perubahan akun harus dikonfirmasi dengan kata sandi Anda.</p>
                </div>

                <div class="btn-row">
                    <button class="btn btn-primary" type="submit">Simpan Akun <?= h(label_role($u['role'])) ?></button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>

    <p class="small" style="margin-top:18px">
        Lupa username atau kata sandi? Buka <a href="lupa.php" target="_blank">halaman lupa password</a>
        untuk membuat tautan konfirmasi sekali pakai (berlaku 60 menit) lalu kirimkan tautan itu ke
        developer <?= h(EMAIL_DEVELOPER) ?>.
    </p>
</div>

<div class="card">
    <h2>Identitas &amp; Tampilan Display</h2>
    <p class="card-sub">Nama rumah sakit tampil besar di header display. Logo tampil mengikuti tinggi panel header.</p>

    <form method="post" action="pengaturan.php">
        <input type="hidden" name="action" value="simpan">

        <div class="grid-2">
            <div>
                <div class="field">
                    <label for="nama_rs">Nama Rumah Sakit</label>
                    <input type="text" id="nama_rs" name="nama_rs" value="<?= h($s['nama_rs'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="footer_teks">Teks Berjalan di Bawah Layar</label>
                    <textarea id="footer_teks" name="footer_teks" placeholder="Contoh: Selamat datang di Rumah Sakit kami..."><?= h($s['footer_teks'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="kecepatan_gulir">Kecepatan Gulir Jadwal</label>
                    <div class="range-row">
                        <input type="range" id="kecepatan_gulir" name="kecepatan_gulir" min="5" max="200" step="5"
                               value="<?= (int) setting_num('kecepatan_gulir', 40) ?>" data-output="outKecepatan" data-satuan=" px/detik">
                        <output id="outKecepatan"><?= (int) setting_num('kecepatan_gulir', 40) ?></output>
                    </div>
                    <p class="small">Semakin kecil nilainya, semakin lambat jadwal bergulir. Teks berjalan di footer memakai kecepatan ini juga.</p>
                </div>
                <div class="field">
                    <label for="refresh_detik">Interval Auto-Refresh Display (detik)</label>
                    <input type="number" id="refresh_detik" name="refresh_detik" min="3" max="300" value="<?= (int) setting_num('refresh_detik', 10) ?>">
                    <p class="small">Default 10 detik — perubahan status/jadwal muncul otomatis tanpa reload manual.</p>
                </div>
            </div>

            <div>
                <div class="field">
                    <label>Tanggal Tayang di Display</label>
                    <p class="small" style="margin:0 0 10px">
                        Pengaturan tanggal ini kini berada di menu <strong>Input Jadwal &rarr; Tanggal Tayang di Display</strong>,
                        agar ada di tempat yang sama dengan pengelolaan jadwal.
                    </p>
                    <p class="small" style="margin:0">
                        Sekarang tayang: <strong><?= h(tgl_label($tanggalDis)) ?></strong>
                        <?= setting('tanggal_aktif', '') !== '' ? '(tanggal pilihan)' : '(hari ini)' ?>.
                    </p>
                </div>

                <a class="btn btn-ghost" href="input.php">Atur di Input Jadwal &#8594;</a>

                <p class="small" style="margin-top:14px">
                    Mengubah tanggal tayang tidak mengubah atau menghapus isi jadwal apa pun — hanya
                    mengganti tanggal yang sedang dibaca layar display.
                </p>
            </div>
        </div>

        <div class="btn-row" style="margin-top:22px">
            <button class="btn btn-primary" type="submit">Simpan Pengaturan</button>
            <a class="btn btn-ghost" href="index.php" target="_blank">Lihat Display &#8599;</a>
        </div>
    </form>
</div>

<div class="card">
    <h2>Logo Rumah Sakit</h2>
    <p class="card-sub">Gunakan gambar PNG/SVG berlatar transparan agar menyatu dengan header display. Logo ditampilkan penuh mengikuti tinggi panel header.</p>

    <div class="logo-preview">
        <?php if (($s['logo_url'] ?? '') !== ''): ?>
            <img src="<?= h($s['logo_url']) ?>" alt="Logo rumah sakit">
        <?php else: ?>
            <span>Belum ada logo — display menampilkan kotak penanda logo</span>
        <?php endif; ?>
    </div>

    <?php if (!$tokenAda): ?>
        <div class="warn-box">
            Penyimpanan media belum aktif pada tahap pratinjau lokal. Unggah logo akan berfungsi setelah aplikasi dipublikasikan.
        </div>
    <?php endif; ?>

    <div class="btn-row">
        <form class="inline-form" method="post" action="pengaturan.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_logo">
            <div class="field" style="min-width:280px">
                <label for="logo">Berkas logo</label>
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required>
            </div>
            <button class="btn btn-primary" type="submit">Unggah Logo</button>
        </form>
        <?php if (($s['logo_url'] ?? '') !== ''): ?>
            <form method="post" action="pengaturan.php" onsubmit="return confirm('Hapus logo dari display?');">
                <input type="hidden" name="action" value="hapus_logo">
                <button class="btn btn-danger" type="submit">Hapus Logo</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Ringkasan Data</h2>
    <div class="grid-4">
        <div>
            <div class="small">Jadwal pada tanggal display</div>
            <div style="font-size:1.6rem;font-weight:800;color:var(--green-300)"><?= (int) db()->query('SELECT COUNT(*) FROM jadwal WHERE tanggal = ' . db()->quote($tanggalDis))->fetchColumn() ?></div>
        </div>
        <div>
            <div class="small">Total jadwal tersimpan</div>
            <div style="font-size:1.6rem;font-weight:800"><?= (int) db()->query('SELECT COUNT(*) FROM jadwal')->fetchColumn() ?></div>
        </div>
        <div>
            <div class="small">Jumlah dokter (master)</div>
            <div style="font-size:1.6rem;font-weight:800"><?= (int) db()->query('SELECT COUNT(*) FROM dokter')->fetchColumn() ?></div>
        </div>
        <div>
            <div class="small">Jumlah poli</div>
            <div style="font-size:1.6rem;font-weight:800"><?= (int) db()->query('SELECT COUNT(*) FROM poli')->fetchColumn() ?></div>
        </div>
    </div>
</div>

<script src="<?= asset('admin.js') ?>"></script>
<?php page_end(); ?>
