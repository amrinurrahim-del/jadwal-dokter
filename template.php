<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_superadmin();   // hanya superadmin

$tid = (int) ($_POST['template_id'] ?? $_GET['template_id'] ?? 0);

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'tmpl_tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($nama === '') {
            redirect('template.php?err=' . urlencode('Nama template wajib diisi.'));
        }
        $cek = db()->prepare('SELECT id FROM template WHERE lower(nama) = lower(?)');
        $cek->execute([$nama]);
        if ($cek->fetchColumn()) {
            redirect('template.php?err=' . urlencode('Template "' . $nama . '" sudah ada.'));
        }
        $ins = db()->prepare('INSERT INTO template (nama, created_at) VALUES (?, ?)');
        $ins->execute([$nama, date('Y-m-d H:i:s')]);
        redirect('template.php?template_id=' . (int) db()->lastInsertId() . '&ok=' . urlencode('Template "' . $nama . '" dibuat.'));
    } elseif ($action === 'tmpl_rename') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($id > 0 && $nama !== '') {
            db()->prepare('UPDATE template SET nama = ? WHERE id = ?')->execute([$nama, $id]);
            redirect('template.php?template_id=' . $id . '&ok=' . urlencode('Nama template diperbarui.'));
        }
        redirect('template.php?err=' . urlencode('Nama template tidak boleh kosong.'));
    } elseif ($action === 'tmpl_hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM template_item WHERE template_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM template WHERE id = ?')->execute([$id]);
        redirect('template.php?ok=' . urlencode('Template dihapus.'));
    } elseif ($action === 'item_tambah') {
        $hari     = max(1, min(7, (int) ($_POST['hari'] ?? 1)));
        $poliId   = (int) ($_POST['poli_id'] ?? 0);
        $dokterId = (int) ($_POST['dokter_id'] ?? 0);
        $jam      = (string) ($_POST['jam'] ?? '');
        $status   = ($_POST['status'] ?? '') === STATUS_TUTUP ? STATUS_TUTUP : STATUS_BUKA;

        if ($tid <= 0 || $poliId <= 0 || $dokterId <= 0 || !in_array($jam, JAM_OPTIONS, true)) {
            redirect('template.php?template_id=' . $tid . '&err=' . urlencode('Lengkapi poli, dokter, dan jam operasional.'));
        }
        $cek = db()->prepare('SELECT id FROM dokter WHERE id = ? AND poli_id = ?');
        $cek->execute([$dokterId, $poliId]);
        if (!$cek->fetchColumn()) {
            redirect('template.php?template_id=' . $tid . '&err=' . urlencode('Dokter tidak terdaftar pada poli yang dipilih.'));
        }
        $dup = db()->prepare('SELECT id FROM template_item WHERE template_id = ? AND hari = ? AND poli_id = ? AND dokter_id = ? AND jam = ?');
        $dup->execute([$tid, $hari, $poliId, $dokterId, $jam]);
        if ($dup->fetchColumn()) {
            redirect('template.php?template_id=' . $tid . '&err=' . urlencode('Jadwal dokter tersebut sudah ada pada hari ' . HARI_PANJANG[$hari] . ' jam ' . $jam . '.'));
        }
        $ins = db()->prepare('INSERT INTO template_item (template_id, hari, poli_id, dokter_id, jam, status) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([$tid, $hari, $poliId, $dokterId, $jam, $status]);
        redirect('template.php?template_id=' . $tid . '&ok=' . urlencode('Jadwal ditambahkan ke template hari ' . HARI_PANJANG[$hari] . '.'));
    } elseif ($action === 'item_hapus') {
        db()->prepare('DELETE FROM template_item WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        redirect('template.php?template_id=' . $tid . '&ok=' . urlencode('Jadwal dihapus dari template.'));
    } elseif ($action === 'ambil_tanggal') {
        $tgl = tgl_valid($_POST['tanggal_sumber'] ?? '');
        $rows = jadwal_hari($tgl);
        if (!$rows) {
            redirect('template.php?template_id=' . $tid . '&err=' . urlencode('Tidak ada jadwal pada ' . tgl_label($tgl) . '.'));
        }
        $hari = hari_iso($tgl);
        $cek  = db()->prepare('SELECT id FROM template_item WHERE template_id = ? AND hari = ? AND poli_id = ? AND dokter_id = ? AND jam = ?');
        $ins  = db()->prepare('INSERT INTO template_item (template_id, hari, poli_id, dokter_id, jam, status) VALUES (?, ?, ?, ?, ?, ?)');
        $baru = 0;
        foreach ($rows as $r) {
            $cek->execute([$tid, $hari, $r['poli_id'], $r['dokter_id'], $r['jam']]);
            if ($cek->fetchColumn()) {
                continue;
            }
            $ins->execute([$tid, $hari, $r['poli_id'], $r['dokter_id'], $r['jam'], $r['status']]);
            $baru++;
        }
        redirect('template.php?template_id=' . $tid . '&ok=' . urlencode($baru . ' jadwal dari ' . tgl_label($tgl) . ' disimpan ke template hari ' . HARI_PANJANG[$hari] . '.'));
    } elseif ($action === 'terapkan') {
        $dari  = tgl_valid($_POST['dari'] ?? '');
        $sampai = tgl_valid($_POST['sampai'] ?? $dari);
        if (strtotime($sampai) < strtotime($dari)) {
            $sampai = $dari;
        }
        $maxHari = 62;
        if ((strtotime($sampai) - strtotime($dari)) / 86400 > $maxHari) {
            redirect('template.php?template_id=' . $tid . '&err=' . urlencode('Rentang tanggal maksimal 2 bulan.'));
        }
        $itemsByHari = [];
        $st = db()->prepare('SELECT * FROM template_item WHERE template_id = ?');
        $st->execute([$tid]);
        foreach ($st->fetchAll() as $it) {
            $itemsByHari[(int) $it['hari']][] = $it;
        }
        $cek = db()->prepare('SELECT 1 FROM jadwal WHERE tanggal = ? AND poli_id = ? AND dokter_id = ? AND jam = ?');
        $ins = db()->prepare('INSERT INTO jadwal (tanggal, poli_id, dokter_id, jam, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $baru = 0;
        $skip = 0;
        for ($t = $dari; strtotime($t) <= strtotime($sampai); $t = tgl_tambah($t, 1)) {
            $hari = hari_iso($t);
            foreach ($itemsByHari[$hari] ?? [] as $it) {
                $cek->execute([$t, $it['poli_id'], $it['dokter_id'], $it['jam']]);
                if ($cek->fetchColumn()) {
                    $skip++;
                    continue;
                }
                $ins->execute([$t, $it['poli_id'], $it['dokter_id'], $it['jam'], $it['status'], date('Y-m-d H:i:s')]);
                $baru++;
            }
        }
        $msg = 'Template diterapkan: ' . $baru . ' jadwal ditambahkan';
        $msg .= ($dari === $sampai) ? ' untuk ' . tgl_label($dari) : ' untuk rentang ' . tgl_label($dari) . ' s/d ' . tgl_label($sampai);
        if ($skip > 0) {
            $msg .= ' (' . $skip . ' dilewati karena sudah ada)';
        }
        redirect('input.php?tanggal=' . urlencode($dari) . '&ok=' . urlencode($msg . '.'));
    }
}

$templates = db()->query('SELECT t.id, t.nama, (SELECT COUNT(*) FROM template_item i WHERE i.template_id = t.id) AS jml
                          FROM template t ORDER BY t.nama')->fetchAll();
$aktif = null;
foreach ($templates as $t) {
    if ((int) $t['id'] === $tid) {
        $aktif = $t;
    }
}
if (!$aktif && $templates) {
    $aktif = $templates[0];
    $tid   = (int) $aktif['id'];
}

$poliList   = daftar_poli();
$dokterData = dokter_per_poli();

$itemsByHari = [];
if ($aktif) {
    $st = db()->prepare('SELECT i.*, p.nama AS poli, d.nama AS dokter
                         FROM template_item i
                         JOIN poli p ON p.id = i.poli_id
                         JOIN dokter d ON d.id = i.dokter_id
                         WHERE i.template_id = ?
                         ORDER BY i.hari, i.jam, d.nama');
    $st->execute([$tid]);
    foreach ($st->fetchAll() as $r) {
        $itemsByHari[(int) $r['hari']][] = $r;
    }
}

page_head('Template Mingguan', 'template.php');
?>

<h1 class="page-title">Template Jadwal Mingguan</h1>
<p class="page-desc">
    Simpan susunan jadwal berulang per hari dalam seminggu, lalu terapkan ke tanggal mana pun dengan satu klik.
    Template tidak mengubah jadwal yang sudah ada — hanya menambahkan jadwal yang belum ada.
</p>

<?php flash(); ?>

<div class="card">
    <h2>Template Tersimpan</h2>
    <p class="card-sub">Pilih template untuk mengelola isinya.</p>

    <?php if (!$templates): ?>
        <div class="empty-note">Belum ada template. Buat template pertama di bawah.</div>
    <?php else: ?>
        <div class="table-wrap" style="border-top:1px solid var(--line); border-radius:12px; margin-bottom:20px">
            <table>
                <thead>
                    <tr><th style="width:46%">Nama Template</th><th style="width:16%">Jumlah Jadwal</th><th style="width:38%">Aksi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td>
                            <form class="inline-form" method="post" action="template.php">
                                <input type="hidden" name="action" value="tmpl_rename">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <input type="text" name="nama" value="<?= h($t['nama']) ?>" style="min-width:220px">
                                <button class="btn btn-ghost btn-sm" type="submit">Simpan</button>
                            </form>
                        </td>
                        <td><?= (int) $t['jml'] ?> jadwal</td>
                        <td>
                            <div class="row-actions">
                                <a class="btn <?= (int) $t['id'] === $tid ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="template.php?template_id=<?= (int) $t['id'] ?>">Kelola</a>
                                <form method="post" action="template.php" onsubmit="return confirm('Hapus template <?= h(addslashes($t['nama'])) ?> beserta isinya?');">
                                    <input type="hidden" name="action" value="tmpl_hapus">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form class="inline-form" method="post" action="template.php">
        <input type="hidden" name="action" value="tmpl_tambah">
        <div class="field" style="min-width:300px">
            <label for="nama_template">Template baru</label>
            <input type="text" id="nama_template" name="nama" placeholder="contoh: Jadwal Mingguan Reguler" required>
        </div>
        <button class="btn btn-primary" type="submit">Buat Template</button>
    </form>
</div>

<?php if ($aktif): ?>

<div class="card">
    <h2>Tambah Jadwal ke Template <span class="hint">— <?= h($aktif['nama']) ?></span></h2>
    <p class="card-sub">Tentukan hari, poli, dokter, jam operasional, dan status default. Status bisa diubah kapan saja saat jadwal sudah masuk tanggal tertentu.</p>
    <form method="post" action="template.php" id="formTemplate">
        <input type="hidden" name="action" value="item_tambah">
        <input type="hidden" name="template_id" value="<?= $tid ?>">

        <div class="grid-3">
            <div class="field">
                <label for="hari">Hari</label>
                <select name="hari" id="hari" required>
                    <?php foreach (HARI_PANJANG as $no => $nm): ?>
                        <option value="<?= $no ?>"<?= $no === 1 ? ' selected' : '' ?>><?= h($nm) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tmpl_poli">Nama Poli</label>
                <select id="tmpl_poli" name="poli_id" required>
                    <option value="">— pilih poli —</option>
                    <?php foreach ($poliList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= h($p['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tmpl_dokter">Nama Dokter</label>
                <select id="tmpl_dokter" name="dokter_id" required>
                    <option value="">— pilih poli dahulu —</option>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="tmpl_jam">Jam Operasional</label>
                <select name="jam" id="tmpl_jam" required>
                    <?php foreach (JAM_OPTIONS as $j): ?>
                        <option value="<?= h($j) ?>"><?= h($j) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status Default</label>
                <div class="status-pills">
                    <label>
                        <input type="radio" name="status" value="BUKA" checked>
                        <span class="pill pill-buka">BUKA</span>
                    </label>
                    <label>
                        <input type="radio" name="status" value="TUTUP">
                        <span class="pill pill-tutup">TUTUP</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" type="submit">Tambah ke Template</button>
        </div>
    </form>

    <hr class="divider">

    <div class="grid-2">
        <div>
            <h2 style="font-size:1rem">Salin dari Jadwal yang Sudah Ada</h2>
            <p class="card-sub">Ambil seluruh jadwal pada satu tanggal (jadwal hari itu) dan simpan sebagai isi template mingguan.</p>
            <form class="inline-form" method="post" action="template.php">
                <input type="hidden" name="action" value="ambil_tanggal">
                <input type="hidden" name="template_id" value="<?= $tid ?>">
                <div class="field" style="min-width:190px">
                    <label for="tanggal_sumber">Tanggal sumber</label>
                    <input type="date" id="tanggal_sumber" name="tanggal_sumber" value="<?= h(tanggal_display()) ?>" required>
                </div>
                <button class="btn btn-ghost" type="submit">Ambil ke Template</button>
            </form>
        </div>
        <div>
            <h2 style="font-size:1rem">Terapkan Template ke Tanggal</h2>
            <p class="card-sub">Bagian dari template yang cocok dengan hari pada tanggal tersebut akan ditambahkan ke jadwal.</p>
            <form class="inline-form" method="post" action="template.php">
                <input type="hidden" name="action" value="terapkan">
                <input type="hidden" name="template_id" value="<?= $tid ?>">
                <div class="field" style="min-width:170px">
                    <label for="dari">Dari tanggal</label>
                    <input type="date" id="dari" name="dari" value="<?= h(tanggal_display()) ?>" required>
                </div>
                <div class="field" style="min-width:170px">
                    <label for="sampai">Sampai tanggal</label>
                    <input type="date" id="sampai" name="sampai" value="<?= h(tanggal_display()) ?>">
                </div>
                <button class="btn btn-primary" type="submit">Terapkan</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h2>Isi Template: <?= h($aktif['nama']) ?></h2>
    <p class="card-sub">Susunan jadwal per hari dalam seminggu.</p>

    <?php $ada = false; ?>
    <?php foreach (HARI_PANJANG as $no => $nm): ?>
        <?php $items = $itemsByHari[$no] ?? []; if (!$items) { continue; } $ada = true; ?>
        <div class="list-head">
            <span><?= h($nm) ?></span>
            <span class="count"><?= count($items) ?> jadwal</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th style="width:28%">Poli</th><th style="width:34%">Nama Dokter</th><th style="width:20%">Jam</th><th style="width:12%">Status</th><th style="width:6%"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= h($it['poli']) ?></td>
                        <td class="nama-dokter"><?= h($it['dokter']) ?></td>
                        <td><?= h($it['jam']) ?></td>
                        <td><span class="badge <?= $it['status'] === STATUS_TUTUP ? 'badge-tutup' : 'badge-buka' ?>"><?= h($it['status']) ?></span></td>
                        <td>
                            <form method="post" action="template.php">
                                <input type="hidden" name="action" value="item_hapus">
                                <input type="hidden" name="template_id" value="<?= $tid ?>">
                                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <?php if (!$ada): ?>
        <div class="empty-note">Template ini masih kosong. Tambahkan jadwal melalui form di atas.</div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
window.POLI_DOKTER = <?= json_encode($dokterData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= asset('admin.js') ?>"></script>
<script>
window.pasangCascading(document.getElementById('tmpl_poli'), document.getElementById('tmpl_dokter'), null, null);
</script>
<?php page_end(); ?>
