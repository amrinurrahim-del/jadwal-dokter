<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_superadmin();   // hanya superadmin

$warn = null;   // peringatan dokter ganda
$form = null;
$edit = null;

if (isset($_GET['edit']) && !is_post()) {
    $st = db()->prepare('SELECT * FROM dokter WHERE id = ?');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    /* ---------------- Dokter ---------------- */
    if ($action === 'dokter_simpan') {
        $id     = (int) ($_POST['id'] ?? 0);
        $poliId = (int) ($_POST['poli_id'] ?? 0);
        $nama   = trim((string) ($_POST['nama'] ?? ''));
        $form   = ['id' => $id, 'poli_id' => $poliId, 'nama' => $nama];

        if ($poliId <= 0 || $nama === '') {
            redirect('master.php?err=' . urlencode('Nama poli dan nama dokter wajib diisi.'));
        }

        /* Cegah dokter ganda: nama sama pada poli yang sama */
        $dup = db()->prepare('SELECT id FROM dokter WHERE poli_id = ? AND lower(trim(nama)) = lower(trim(?)) AND id <> ?');
        $dup->execute([$poliId, $nama, $id]);
        if ($dup->fetchColumn() && ($_POST['force'] ?? '') !== '1') {
            $st = db()->prepare('SELECT nama FROM poli WHERE id = ?');
            $st->execute([$poliId]);
            $warn = ['nama' => $nama, 'poli' => (string) $st->fetchColumn()];
        } else {
            if ($id > 0) {
                $st = db()->prepare('UPDATE dokter SET poli_id = ?, nama = ? WHERE id = ?');
                $st->execute([$poliId, $nama, $id]);
                redirect('master.php?ok=' . urlencode('Data dokter berhasil diperbarui.'));
            }
            $st = db()->prepare('INSERT INTO dokter (poli_id, nama, aktif, created_at) VALUES (?, ?, 1, ?)');
            $st->execute([$poliId, $nama, date('Y-m-d H:i:s')]);
            redirect('master.php?ok=' . urlencode('Dokter baru "' . $nama . '" berhasil ditambahkan.'));
        }
    } elseif ($action === 'dokter_hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT COUNT(*) FROM jadwal WHERE dokter_id = ?');
        $st->execute([$id]);
        $jml = (int) $st->fetchColumn();
        db()->prepare('DELETE FROM jadwal WHERE dokter_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM template_item WHERE dokter_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM dokter WHERE id = ?')->execute([$id]);
        $msg = 'Data dokter dihapus.';
        if ($jml > 0) {
            $msg .= ' ' . $jml . ' jadwal milik dokter ini juga terhapus.';
        }
        redirect('master.php?ok=' . urlencode($msg));
    }

    /* ---------------- Poli ---------------- */
    elseif ($action === 'poli_tambah') {
        $nama = trim((string) ($_POST['nama_poli'] ?? ''));
        if ($nama === '') {
            redirect('master.php?err=' . urlencode('Nama poli baru wajib diisi.'));
        }
        $uppercase = nama_poli_normal($nama);
        $cek = db()->prepare('SELECT id FROM poli WHERE lower(nama) = lower(?)');
        $cek->execute([$uppercase]);
        if ($cek->fetchColumn()) {
            redirect('master.php?err=' . urlencode('Poli "' . $uppercase . '" sudah ada.'));
        }
        $urut = (int) db()->query('SELECT COALESCE(MAX(urutan), 0) + 1 FROM poli')->fetchColumn();
        $ins = db()->prepare('INSERT INTO poli (nama, urutan) VALUES (?, ?)');
        $ins->execute([$uppercase, $urut]);
        redirect('master.php?ok=' . urlencode('Poli baru "' . $uppercase . '" ditambahkan.'));
    } elseif ($action === 'poli_rename') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nama = nama_poli_normal((string) ($_POST["nama"] ?? ""));
        if ($id > 0 && $nama !== '') {
            $st = db()->prepare('UPDATE poli SET nama = ? WHERE id = ?');
            $st->execute([$nama, $id]);
            redirect('master.php?ok=' . urlencode('Nama poli diperbarui menjadi "' . $nama . '".'));
        }
        redirect('master.php?err=' . urlencode('Nama poli tidak boleh kosong.'));
    } elseif ($action === 'poli_hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT COUNT(*) FROM dokter WHERE poli_id = ?');
        $st->execute([$id]);
        $jmlDokter = (int) $st->fetchColumn();
        $st = db()->prepare('SELECT COUNT(*) FROM jadwal WHERE poli_id = ?');
        $st->execute([$id]);
        $jmlJadwal = (int) $st->fetchColumn();

        db()->prepare('DELETE FROM jadwal WHERE poli_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM template_item WHERE poli_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM dokter WHERE poli_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM poli WHERE id = ?')->execute([$id]);

        redirect('master.php?ok=' . urlencode('Poli dihapus beserta ' . $jmlDokter . ' dokter dan ' . $jmlJadwal . ' jadwal terkait.'));
    }
}

$poliList   = daftar_poli();
$dokterData = dokter_per_poli();

$f = ['id' => 0, 'poli_id' => (int) ($poliList[0]['id'] ?? 0), 'nama' => ''];
if ($edit) {
    $f = ['id' => (int) $edit['id'], 'poli_id' => (int) $edit['poli_id'], 'nama' => $edit['nama']];
} elseif ($form) {
    $f = $form;
}

page_head('Master Dokter', 'master.php');
?>

<h1 class="page-title">Master Data Dokter &amp; Poli</h1>
<p class="page-desc">
    Daftar nama dokter di sini dipakai pada form Input Jadwal — nama dokter akan otomatis mengikuti
    poli yang dipilih. Tambahkan poli baru di bagian bawah halaman bila ada layanan baru.
</p>

<?php flash(); ?>

<?php if ($warn): ?>
    <div class="warn-box">
        <strong>Peringatan: dokter ganda!</strong><br>
        Nama dokter <strong><?= h($warn['nama']) ?></strong> sudah terdaftar pada poli <strong><?= h($warn['poli']) ?></strong>.
        Simpan tetap bisa dilakukan bila memang berbeda orang (mis. dokter jaga berbeda).
        <form method="post" action="master.php" style="margin-top:14px">
            <input type="hidden" name="action" value="dokter_simpan">
            <input type="hidden" name="force" value="1">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <input type="hidden" name="poli_id" value="<?= (int) $form['poli_id'] ?>">
            <input type="hidden" name="nama" value="<?= h($form['nama']) ?>">
            <button class="btn btn-danger" type="submit">Tetap Simpan</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $f['id'] > 0 ? 'Ubah Data Dokter' : 'Tambah Dokter Baru' ?></h2>
    <p class="card-sub">Satu dokter cukup didaftarkan sekali, lalu dipakai untuk jadwal tanggal mana pun.</p>
    <form method="post" action="master.php">
        <input type="hidden" name="action" value="dokter_simpan">
        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
        <div class="grid-2">
            <div class="field">
                <label for="poli_id">Nama Poli</label>
                <select name="poli_id" id="poli_id" required>
                    <?php foreach ($poliList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === (int) $f['poli_id'] ? ' selected' : '' ?>><?= h($p['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="nama">Nama Dokter</label>
                <input type="text" id="nama" name="nama" value="<?= h($f['nama']) ?>" placeholder="contoh: dr. Andi Pratama, Sp.A" required>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" type="submit"><?= $f['id'] > 0 ? 'Simpan Perubahan' : 'Tambah Dokter' ?></button>
            <?php if ($f['id'] > 0): ?>
                <a class="btn btn-ghost" href="master.php">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>Daftar Dokter per Poli</h2>
    <p class="card-sub">Total <?= count($dokterData) ?> poli terisi data dokter.</p>

    <?php if (!$dokterData): ?>
        <div class="empty-note">Belum ada data dokter. Tambahkan melalui form di atas.</div>
    <?php else: ?>
        <?php foreach ($dokterData as $pid => $grup): ?>
            <div class="list-head">
                <span><?= h($grup['poli']) ?></span>
                <span class="count"><?= count($grup['dokter']) ?> dokter</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th style="width:70%">Nama Dokter</th><th style="width:30%">Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($grup['dokter'] as $d): ?>
                        <tr>
                            <td class="nama-dokter"><?= h($d['nama']) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-ghost btn-sm" href="master.php?edit=<?= (int) $d['id'] ?>">Edit</a>
                                    <form method="post" action="master.php" onsubmit="return confirm('Hapus dokter <?= h(addslashes($d['nama'])) ?>? Jadwal &amp; template milik dokter ini juga akan terhapus.');">
                                        <input type="hidden" name="action" value="dokter_hapus">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Kelola Nama Poli</h2>
    <p class="card-sub">Tambahkan poli baru bila ada layanan tambahan, atau ubah nama poli yang sudah ada.</p>

    <form class="inline-form" method="post" action="master.php" style="margin-bottom:22px">
        <input type="hidden" name="action" value="poli_tambah">
        <div class="field" style="min-width:300px">
            <label for="nama_poli">Nama poli baru</label>
            <input type="text" id="nama_poli" name="nama_poli" placeholder="contoh: POLI REHABILITASI MEDIK" required>
        </div>
        <button class="btn btn-primary" type="submit">Tambah Poli</button>
    </form>

    <div class="table-wrap" style="border-top:1px solid var(--line); border-radius:12px">
        <table>
            <thead>
                <tr><th style="width:60%">Nama Poli</th><th style="width:40%">Aksi</th></tr>
            </thead>
            <tbody>
            <?php foreach ($poliList as $p): ?>
                <tr>
                    <td>
                        <form class="inline-form" method="post" action="master.php">
                            <input type="hidden" name="action" value="poli_rename">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="text" name="nama" value="<?= h($p['nama']) ?>" style="min-width:260px">
                            <button class="btn btn-ghost btn-sm" type="submit">Simpan</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="master.php" onsubmit="return confirm('Hapus poli <?= h(addslashes($p['nama'])) ?>? Seluruh dokter dan jadwal pada poli ini akan terhapus.');">
                            <input type="hidden" name="action" value="poli_hapus">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Hapus Poli</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.POLI_DOKTER = <?= json_encode($dokterData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= asset('admin.js') ?>"></script>
<?php page_end(); ?>
