<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();   // admin & superadmin boleh: halaman ini hanya Input Jadwal

$poliList   = daftar_poli();
$dokterData = dokter_per_poli();

$tanggal = tgl_valid($_POST['tanggal'] ?? $_GET['tanggal'] ?? tanggal_display());

/* ------------------------------- Aksi ------------------------------- */

$warn   = null;                 // peringatan dokter ganda
$form   = null;                 // isi form yang perlu dipertahankan
$edit   = null;

if (isset($_GET['edit']) && !is_post()) {
    $st = db()->prepare('SELECT * FROM jadwal WHERE id = ?');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $tanggal = $edit['tanggal'];
    }
}

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    /* ---------- Simpan / ubah jadwal ---------- */
    if ($action === 'simpan') {
        $id       = (int) ($_POST['id'] ?? 0);
        $poliId   = (int) ($_POST['poli_id'] ?? 0);
        $dokterId = (int) ($_POST['dokter_id'] ?? 0);
        $jam      = (string) ($_POST['jam'] ?? '');
        $status   = ($_POST['status'] ?? '') === STATUS_TUTUP ? STATUS_TUTUP : STATUS_BUKA;

        $form = ['id' => $id, 'tanggal' => $tanggal, 'poli_id' => $poliId, 'dokter_id' => $dokterId, 'jam' => $jam, 'status' => $status];

        if ($poliId <= 0 || $dokterId <= 0) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Poli dan nama dokter wajib dipilih.'));
        }
        if (!in_array($jam, JAM_OPTIONS, true)) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Jam operasional tidak valid.'));
        }

        $cek = db()->prepare('SELECT id FROM dokter WHERE id = ? AND poli_id = ?');
        $cek->execute([$dokterId, $poliId]);
        if (!$cek->fetchColumn()) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Nama dokter tidak terdaftar pada poli yang dipilih.'));
        }

        /* Cegah dokter ganda: dokter sama pada poli yang sama di tanggal yang sama */
        $dup = db()->prepare('SELECT jam FROM jadwal WHERE tanggal = ? AND poli_id = ? AND dokter_id = ? AND id <> ?');
        $dup->execute([$tanggal, $poliId, $dokterId, $id]);
        $jamBentrok = $dup->fetchAll(PDO::FETCH_COLUMN);

        if ($jamBentrok && ($_POST['force'] ?? '') !== '1') {
            $nmDokter = '';
            $nmPoli   = '';
            foreach ($dokterData as $pid => $grup) {
                if ($pid === $poliId) {
                    $nmPoli = $grup['poli'];
                    foreach ($grup['dokter'] as $d) {
                        if ($d['id'] === $dokterId) {
                            $nmDokter = $d['nama'];
                        }
                    }
                }
            }
            $warn = [
                'dokter' => $nmDokter,
                'poli'   => $nmPoli,
                'jam'    => $jamBentrok,
            ];
        } else {
            if ($id > 0) {
                $st = db()->prepare('UPDATE jadwal SET tanggal = ?, poli_id = ?, dokter_id = ?, jam = ?, status = ?, updated_at = ? WHERE id = ?');
                $st->execute([$tanggal, $poliId, $dokterId, $jam, $status, date('Y-m-d H:i:s'), $id]);
                redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode('Jadwal berhasil diperbarui.'));
            }
            $st = db()->prepare('INSERT INTO jadwal (tanggal, poli_id, dokter_id, jam, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$tanggal, $poliId, $dokterId, $jam, $status, date('Y-m-d H:i:s')]);
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode('Jadwal berhasil ditambahkan.'));
        }
    }

    /* ---------- Hapus jadwal ---------- */
    elseif ($action === 'hapus') {
        $st = db()->prepare('DELETE FROM jadwal WHERE id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0)]);
        redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode('Jadwal dihapus.'));
    }

    /* ---------- Ubah status cepat (BUKA <-> TUTUP) ---------- */
    elseif ($action === 'toggle_status') {
        $st = db()->prepare('SELECT status FROM jadwal WHERE id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0)]);
        $cur = $st->fetchColumn();
        if ($cur !== false) {
            $baru = ($cur === STATUS_BUKA) ? STATUS_TUTUP : STATUS_BUKA;
            $up = db()->prepare('UPDATE jadwal SET status = ?, updated_at = ? WHERE id = ?');
            $up->execute([$baru, date('Y-m-d H:i:s'), (int) $_POST['id']]);
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode('Status diubah menjadi ' . $baru . '.'));
        }
        redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Data jadwal tidak ditemukan.'));
    }

    /* ---------- Ubah tanggal yang ditampilkan di layar display ---------- */
    elseif ($action === 'set_tanggal_display') {
        $pilihan = trim((string) ($_POST['tanggal_display'] ?? ''));
        if ($pilihan !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pilihan)) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Tanggal display tidak valid.'));
        }
        set_setting('tanggal_aktif', $pilihan);
        $pesan = $pilihan === ''
            ? 'Display kini menampilkan jadwal HARI INI (' . tgl_label(hari_ini()) . '). Isi jadwal tidak berubah.'
            : 'Display kini menampilkan jadwal ' . tgl_label($pilihan) . '. Isi jadwal tidak berubah.';
        redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode($pesan));
    }

    /* ---------- Hapus jadwal yang sudah lewat (kemarin dan sebelumnya) ---------- */
    elseif ($action === 'bersihkan_lama') {
        $batas = hari_ini();
        $st = db()->prepare('DELETE FROM jadwal WHERE tanggal < ?');
        $st->execute([$batas]);
        $jumlah = $st->rowCount();
        $pesan = $jumlah > 0
            ? $jumlah . ' jadwal sebelum ' . tgl_label($batas) . ' dihapus dari database.'
            : 'Tidak ada jadwal lama yang perlu dihapus.';
        redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode($pesan));
    }

    /* ---------- Salin cepat jadwal hari ini ke besok ---------- */
    elseif ($action === 'salin_besok') {
        $besok = tgl_tambah($tanggal, 1);
        $rows  = jadwal_hari($tanggal);
        if (!$rows) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Tidak ada jadwal pada tanggal ' . tgl_label($tanggal) . ' untuk disalin.'));
        }
        $cek = db()->prepare('SELECT 1 FROM jadwal WHERE tanggal = ? AND poli_id = ? AND dokter_id = ? AND jam = ?');
        $ins = db()->prepare('INSERT INTO jadwal (tanggal, poli_id, dokter_id, jam, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $baru = 0;
        $skip = 0;
        foreach ($rows as $r) {
            $cek->execute([$besok, $r['poli_id'], $r['dokter_id'], $r['jam']]);
            if ($cek->fetchColumn()) {
                $skip++;
                continue;
            }
            $ins->execute([$besok, $r['poli_id'], $r['dokter_id'], $r['jam'], $r['status'], date('Y-m-d H:i:s')]);
            $baru++;
        }
        $msg = $baru . ' jadwal disalin ke ' . tgl_label($besok) . '.';
        if ($skip > 0) {
            $msg .= ' ' . $skip . ' jadwal dilewati karena sudah ada.';
        }
        redirect('input.php?tanggal=' . urlencode($besok) . '&ok=' . urlencode($msg));
    }

    /* ---------- Terapkan template mingguan ke tanggal ini ---------- */
    elseif ($action === 'terapkan_template') {
        $tid = (int) ($_POST['template_id'] ?? 0);
        $hari = hari_iso($tanggal);
        $st = db()->prepare('SELECT * FROM template_item WHERE template_id = ? AND hari = ?');
        $st->execute([$tid, $hari]);
        $items = $st->fetchAll();
        if (!$items) {
            redirect('input.php?tanggal=' . urlencode($tanggal) . '&err=' . urlencode('Template tidak memiliki jadwal untuk hari ' . HARI_PANJANG[$hari] . '.'));
        }
        $cek = db()->prepare('SELECT 1 FROM jadwal WHERE tanggal = ? AND poli_id = ? AND dokter_id = ? AND jam = ?');
        $ins = db()->prepare('INSERT INTO jadwal (tanggal, poli_id, dokter_id, jam, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $baru = 0;
        $skip = 0;
        foreach ($items as $r) {
            $cek->execute([$tanggal, $r['poli_id'], $r['dokter_id'], $r['jam']]);
            if ($cek->fetchColumn()) {
                $skip++;
                continue;
            }
            $ins->execute([$tanggal, $r['poli_id'], $r['dokter_id'], $r['jam'], $r['status'], date('Y-m-d H:i:s')]);
            $baru++;
        }
        $msg = 'Template diterapkan: ' . $baru . ' jadwal ditambahkan untuk hari ' . HARI_PANJANG[$hari] . '.';
        if ($skip > 0) {
            $msg .= ' ' . $skip . ' dilewati (sudah ada).';
        }
        redirect('input.php?tanggal=' . urlencode($tanggal) . '&ok=' . urlencode($msg));
    }
}

/* -------------------------- Data tampilan --------------------------- */

$rows   = jadwal_hari($tanggal);
$groups = [];
foreach ($rows as $r) {
    $pid = (int) $r['poli_id'];
    if (!isset($groups[$pid])) {
        $groups[$pid] = ['poli' => $r['poli'], 'items' => []];
    }
    $groups[$pid]['items'][] = $r;
}
$total = count($rows);

$templates = db()->query('SELECT id, nama FROM template ORDER BY nama')->fetchAll();

/* Nilai awal form: hasil edit > hasil post yang perlu diperbaiki > kosong */
$f = ['id' => 0, 'poli_id' => 0, 'dokter_id' => 0, 'jam' => JAM_OPTIONS[0], 'status' => STATUS_BUKA];
if ($edit) {
    $f = ['id' => (int) $edit['id'], 'poli_id' => (int) $edit['poli_id'], 'dokter_id' => (int) $edit['dokter_id'], 'jam' => $edit['jam'], 'status' => $edit['status']];
} elseif ($form) {
    $f = ['id' => $form['id'], 'poli_id' => $form['poli_id'], 'dokter_id' => $form['dokter_id'], 'jam' => $form['jam'], 'status' => $form['status']];
}
if ($f['jam'] === '') {
    $f['jam'] = JAM_OPTIONS[0];
}

$tanggalDis   = tanggal_display();
$jumlahLama   = (int) db()->query('SELECT COUNT(*) FROM jadwal WHERE tanggal < ' . db()->quote(hari_ini()))->fetchColumn();
$tanggalLamaAwal = db()->query('SELECT MIN(tanggal) FROM jadwal WHERE tanggal < ' . db()->quote(hari_ini()))->fetchColumn();

page_head('Input Jadwal', 'input.php');
?>

<h1 class="page-title">Input Jadwal Dokter</h1>
<p class="page-desc">
    Kelola jadwal poli per tanggal. Layar display menampilkan jadwal sesuai
    <strong>tanggal tayang</strong> yang diatur di bawah, dan tanggal itu bisa diganti
    tanpa mengubah isi jadwal.
</p>

<?php flash(); ?>

<div class="card">
    <h2>Tanggal Tayang di Display <span class="hint">— menentukan jadwal yang tampil di TV ruang tunggu</span></h2>
    <p class="card-sub">
        Bawaan: jadwal <strong>hari ini</strong>. Mengubah tanggal di sini hanya mengganti tanggal
        yang dibaca layar display — <strong>tidak mengubah atau menghapus isi jadwal</strong>.
        Layar display mengikuti perubahan ini maksimal 10 detik kemudian.
    </p>

    <form class="inline-form" method="post" action="input.php">
        <input type="hidden" name="action" value="set_tanggal_display">
        <div class="field" style="min-width:210px">
            <label for="tanggal_display">Tanggal tayang</label>
            <input type="date" id="tanggal_display" name="tanggal_display" value="<?= h($tanggalDis) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Tampilkan Tanggal Ini</button>
    </form>

    <div class="btn-row" style="margin-top:14px">
        <form method="post" action="input.php">
            <input type="hidden" name="action" value="set_tanggal_display">
            <input type="hidden" name="tanggal_display" value="">
            <button class="btn btn-ghost" type="submit"<?= setting('tanggal_aktif', '') === '' ? ' disabled' : '' ?>>
                Kembalikan ke Jadwal Hari Ini
            </button>
        </form>
        <a class="btn btn-ghost" href="index.php" target="_blank">Buka Display &#8599;</a>
    </div>

    <p class="small" style="margin-top:16px">
        Sekarang tayang: <strong><?= h(tgl_label($tanggalDis)) ?></strong>
        <?php if (setting('tanggal_aktif', '') !== ''): ?>
            <span class="tag tag-warn" style="margin-left:6px">tanggal pilihan</span>
        <?php else: ?>
            <span class="tag" style="margin-left:6px">hari ini</span>
        <?php endif; ?>
        · Jumlah jadwal pada tanggal tayang:
        <strong><?= (int) db()->query('SELECT COUNT(*) FROM jadwal WHERE tanggal = ' . db()->quote($tanggalDis))->fetchColumn() ?></strong>
    </p>
</div>

<div class="card">
    <h2>Tanggal Kerja <span class="hint">— pilih tanggal untuk melihat / mengubah jadwalnya</span></h2>
    <div class="btn-row" style="margin-top:14px">
        <form class="inline-form" method="get" action="input.php">
            <div class="field" style="min-width:190px">
                <label for="tanggalNav">Tanggal jadwal</label>
                <input type="date" id="tanggalNav" name="tanggal" value="<?= h($tanggal) ?>">
            </div>
            <noscript><button class="btn btn-ghost" type="submit">Tampilkan</button></noscript>
        </form>
        <form class="inline-form" method="post" action="input.php">
            <input type="hidden" name="action" value="salin_besok">
            <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
            <button class="btn btn-primary" type="submit"
                    onclick="return confirm('Salin semua jadwal tanggal <?= h(tgl_label($tanggal)) ?> ke besok?');">
                Salin Cepat ke Besok &#8594;
            </button>
        </form>
    </div>
    <p class="small" style="margin-top:14px">
        Sedang dikelola: <strong><?= h(tgl_label($tanggal)) ?></strong> (<?= h(HARI_PANJANG[hari_iso($tanggal)]) ?>) —
        <span id="jumlahJadwalTanggal"><?= (int) $total ?></span> jadwal.
    </p>
</div>

<div class="card">
    <h2>Bersihkan Jadwal yang Sudah Lewat <span class="hint">— cegah penumpukan data</span></h2>
    <p class="card-sub">
        Menghapus permanen seluruh jadwal dengan tanggal <strong>sebelum hari ini</strong>
        (kemarin dan seterusnya). Data hari ini dan tanggal yang akan datang tidak tersentuh.
        Jadwal lama tidak diperlukan lagi karena layar display hanya menampilkan satu tanggal.
    </p>
    <?php if ($jumlahLama > 0): ?>
        <div class="warn-box" style="margin-bottom:18px">
            Ada <strong><?= $jumlahLama ?> jadwal lama</strong> tersimpan
            <?php if ($tanggalLamaAwal): ?> (mulai <?= h(tgl_label((string) $tanggalLamaAwal, false)) ?>)<?php endif; ?>
            yang bisa dihapus. Tindakan ini tidak dapat dibatalkan.
        </div>
        <form method="post" action="input.php">
            <input type="hidden" name="action" value="bersihkan_lama">
            <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
            <button class="btn btn-danger" type="submit" id="btnBersihkanLama"
                    onclick="return confirm('Hapus permanen <?= $jumlahLama ?> jadwal sebelum hari ini (<?= h(tgl_label(hari_ini(), false)) ?>)? Data ini tidak dapat dikembalikan.');">
                Hapus <?= $jumlahLama ?> Jadwal Lama
            </button>
        </form>
    <?php else: ?>
        <div class="empty-note">Tidak ada jadwal lama. Database hanya berisi jadwal hari ini dan yang akan datang.</div>
    <?php endif; ?>
</div>


<?php if ($warn): ?>
    <div class="warn-box">
        <strong>Peringatan: dokter ganda!</strong><br>
        Dokter <strong><?= h($warn['dokter']) ?></strong> sudah punya jadwal pada poli <strong><?= h($warn['poli']) ?></strong>
        di tanggal <?= h(tgl_label($tanggal)) ?>, jam: <?= h(implode(', ', $warn['jam'])) ?>.
        Anda tetap bisa menyimpannya bila memang jadwal ganda ini disengaja.
        <form method="post" action="input.php" style="margin-top:14px">
            <input type="hidden" name="action" value="simpan">
            <input type="hidden" name="force" value="1">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
            <input type="hidden" name="poli_id" value="<?= (int) $form['poli_id'] ?>">
            <input type="hidden" name="dokter_id" value="<?= (int) $form['dokter_id'] ?>">
            <input type="hidden" name="jam" value="<?= h($form['jam']) ?>">
            <input type="hidden" name="status" value="<?= h($form['status']) ?>">
            <button class="btn btn-danger" type="submit">Tetap Simpan</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $f['id'] > 0 ? 'Ubah Jadwal' : 'Tambah Jadwal' ?></h2>
    <p class="card-sub">
        Nama dokter mengikuti poli yang dipilih (data diambil dari Master Dokter).
        <?php if ($f['id'] > 0): ?>Sedang mengubah data jadwal yang sudah ada — <a href="input.php?tanggal=<?= h($tanggal) ?>" style="color:var(--green-300)">batal &amp; tambah baru</a>.<?php endif; ?>
    </p>

    <form method="post" action="input.php" id="formJadwal">
        <input type="hidden" name="action" value="simpan">
        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
        <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">

        <div class="grid-3">
            <div class="field">
                <label for="poli_id">Nama Poli</label>
                <select name="poli_id" id="poli_id" required>
                    <option value="">— pilih poli —</option>
                    <?php foreach ($poliList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === (int) $f['poli_id'] ? ' selected' : '' ?>><?= h($p['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="dokter_id">Nama Dokter</label>
                <select name="dokter_id" id="dokter_id" required>
                    <option value="">— pilih poli dahulu —</option>
                </select>
            </div>

            <div class="field">
                <label for="jam">Jam Operasional</label>
                <select name="jam" id="jam" required>
                    <?php foreach (JAM_OPTIONS as $j): ?>
                        <option value="<?= h($j) ?>"<?= $j === $f['jam'] ? ' selected' : '' ?>><?= h($j) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field" style="max-width:420px">
            <label>Status</label>
            <div class="status-pills">
                <label>
                    <input type="radio" name="status" value="BUKA"<?= $f['status'] === STATUS_BUKA ? ' checked' : '' ?>>
                    <span class="pill pill-buka">BUKA</span>
                </label>
                <label>
                    <input type="radio" name="status" value="TUTUP"<?= $f['status'] === STATUS_TUTUP ? ' checked' : '' ?>>
                    <span class="pill pill-tutup">TUTUP</span>
                </label>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" type="submit"><?= $f['id'] > 0 ? 'Simpan Perubahan' : 'Tambah Jadwal' ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Terapkan Template Mingguan</h2>
    <p class="card-sub">Salin susunan jadwal dari template ke tanggal <?= h(tgl_label($tanggal)) ?> (<?= h(HARI_PANJANG[hari_iso($tanggal)]) ?>) dengan satu klik.</p>
    <?php if (!$templates): ?>
        <div class="empty-note">
            Belum ada template.
            <?php if (menu_diizinkan($user, 'template.php')): ?>
                Buat dulu di menu <a href="template.php" style="color:var(--green-300)">Template Mingguan</a>.
            <?php else: ?>
                Mintakan superadmin untuk membuat template mingguan terlebih dahulu (menu Template Mingguan terkunci untuk akun Anda).
            <?php endif; ?>
        </div>
    <?php else: ?>
        <form class="inline-form" method="post" action="input.php">
            <input type="hidden" name="action" value="terapkan_template">
            <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
            <div class="field" style="min-width:260px">
                <label for="template_id">Template</label>
                <select name="template_id" id="template_id" required>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= h($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Terapkan ke Tanggal Ini</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Jadwal <?= h(tgl_label($tanggal)) ?></h2>
    <p class="card-sub">
        Tombol <strong>Set TUTUP / Set BUKA</strong> dan <strong>Hapus</strong> dikerjakan di halaman ini
        tanpa memuat ulang. Setelah selesai, tekan <strong>Simpan Semua Perubahan</strong> pada bilah di
        bawah layar — perubahan langsung tampil di display maksimal 10 detik kemudian.
    </p>

    <div id="pesanJadwal"></div>

    <?php if (!$groups): ?>
        <div class="empty-note">Belum ada jadwal pada tanggal ini. Tambahkan melalui form di atas.</div>
    <?php else: ?>
        <?php foreach ($groups as $g): ?>
            <div class="list-head">
                <span><?= h($g['poli']) ?></span>
                <span class="count"><?= count($g['items']) ?> dokter</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40%">Nama Dokter</th>
                            <th style="width:22%">Jam Operasional</th>
                            <th style="width:14%">Status</th>
                            <th style="width:24%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($g['items'] as $item): ?>
                        <tr data-jadwal="<?= (int) $item['id'] ?>" data-status="<?= h($item['status']) ?>">
                            <td class="nama-dokter"><?= h($item['dokter']) ?></td>
                            <td><?= h($item['jam']) ?></td>
                            <td>
                                <span class="badge <?= $item['status'] === STATUS_TUTUP ? 'badge-tutup' : 'badge-buka' ?>" data-badge><?= h($item['status']) ?></span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button class="btn btn-ghost btn-sm btn-toggle" type="button"
                                            data-id="<?= (int) $item['id'] ?>"
                                            title="Ubah status BUKA/TUTUP tanpa memuat ulang">
                                        &#8646; <span data-label><?= $item['status'] === STATUS_TUTUP ? 'Set BUKA' : 'Set TUTUP' ?></span>
                                    </button>
                                    <a class="btn btn-ghost btn-sm btn-edit" href="input.php?tanggal=<?= h($tanggal) ?>&amp;edit=<?= (int) $item['id'] ?>">Edit</a>
                                    <button class="btn btn-danger btn-sm btn-hapus" type="button"
                                            data-id="<?= (int) $item['id'] ?>"
                                            title="Tandai jadwal ini untuk dihapus (bisa dibatalkan sebelum disimpan)">
                                        Hapus
                                    </button>
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

<div class="save-bar" id="barPerubahan" hidden>
    <div class="save-bar-info">
        <span class="save-bar-dot"></span>
        <span id="teksPerubahan">Ada perubahan belum disimpan.</span>
    </div>
    <div class="save-bar-aksi">
        <button class="btn btn-ghost" type="button" id="btnBatalkan">Batalkan</button>
        <button class="btn btn-primary" type="button" id="btnSimpanPerubahan">
            <span id="labelSimpan">Simpan Semua Perubahan</span>
        </button>
    </div>
</div>

<script>
window.POLI_DOKTER = <?= json_encode($dokterData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.DOKTER_TERPILIH = <?= (int) $f['dokter_id'] ?>;
</script>
<script src="<?= asset('admin.js') ?>"></script>
<script src="<?= asset('input-jadwal.js') ?>"></script>
<?php page_end(); ?>
