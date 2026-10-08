<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ------------------------------------------------------------------ */
/* Pengaturan                                                          */
/* ------------------------------------------------------------------ */

function all_settings(): array
{
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (db()->query('SELECT kunci, nilai FROM pengaturan') as $r) {
            $s[$r['kunci']] = (string) $r['nilai'];
        }
    }
    return $s;
}

function setting(string $k, string $default = ''): string
{
    $s = all_settings();
    return array_key_exists($k, $s) ? $s[$k] : $default;
}

function setting_num(string $k, float $default): float
{
    $v = setting($k, '');
    return is_numeric($v) ? (float) $v : $default;
}

function set_setting(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)
                         ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai');
    $st->execute([$k, $v]);
}

/* ------------------------------------------------------------------ */
/* Tanggal                                                             */
/* ------------------------------------------------------------------ */

function hari_ini(): string
{
    return date('Y-m-d');
}

/** Tanggal yang sedang ditampilkan display (bisa diubah dari Pengaturan tanpa mengubah data jadwal). */
function tanggal_display(): string
{
    $t = setting('tanggal_aktif', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : hari_ini();
}

function tgl_valid(?string $ymd): string
{
    return (is_string($ymd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) ? $ymd : hari_ini();
}

function tgl_label(string $ymd, bool $dengan_hari = true): string
{
    $ts = strtotime($ymd . ' 00:00:00');
    if ($ts === false) {
        return $ymd;
    }
    $label = (int) date('j', $ts) . ' ' . BULAN_ID[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    return $dengan_hari ? HARI_PANJANG[(int) date('N', $ts)] . ', ' . $label : $label;
}

function hari_iso(string $ymd): int
{
    $ts = strtotime($ymd . ' 00:00:00');
    return $ts === false ? 1 : (int) date('N', $ts);
}

function tgl_tambah(string $ymd, int $hari): string
{
    return date('Y-m-d', strtotime($ymd . ' 00:00:00 ' . ($hari >= 0 ? '+' : '') . $hari . ' day'));
}

/* ------------------------------------------------------------------ */
/* Data master                                                         */
/* ------------------------------------------------------------------ */

function daftar_poli(): array
{
    return db()->query('SELECT id, nama FROM poli ORDER BY urutan, nama')->fetchAll();
}

function daftar_dokter(?int $poliId = null): array
{
    if ($poliId !== null) {
        $st = db()->prepare('SELECT id, poli_id, nama FROM dokter WHERE poli_id = ? ORDER BY nama');
        $st->execute([$poliId]);
        return $st->fetchAll();
    }
    return db()->query('SELECT id, poli_id, nama FROM dokter ORDER BY nama')->fetchAll();
}

/** Dokter dikelompokkan per poli (untuk dropdown bertingkat). */
function dokter_per_poli(): array
{
    $out = [];
    $rows = db()->query('SELECT d.id, d.poli_id, d.nama, p.nama AS poli FROM dokter d
                         JOIN poli p ON p.id = d.poli_id
                         ORDER BY p.urutan, p.nama, d.nama')->fetchAll();
    foreach ($rows as $r) {
        $out[(int) $r['poli_id']]['poli']    = $r['poli'];
        $out[(int) $r['poli_id']]['dokter'][] = ['id' => (int) $r['id'], 'nama' => $r['nama']];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Jadwal                                                              */
/* ------------------------------------------------------------------ */

const SQL_JADWAL = 'SELECT j.id, j.tanggal, j.poli_id, j.dokter_id, j.jam, j.status,
                           p.nama AS poli, p.urutan AS poli_urutan, d.nama AS dokter
                    FROM jadwal j
                    JOIN poli p ON p.id = j.poli_id
                    JOIN dokter d ON d.id = j.dokter_id';

function jadwal_hari(string $tanggal): array
{
    $st = db()->prepare(SQL_JADWAL . ' WHERE j.tanggal = ?
                         ORDER BY p.urutan, p.nama, j.jam, d.nama');
    $st->execute([$tanggal]);
    return $st->fetchAll();
}

/** Nama poli disimpan dalam huruf kapital (tanpa mbstring, yang tidak tersedia di server ini). */
function nama_poli_normal(string $s): string
{
    return strtoupper(trim($s));
}

/** Jadwal dikelompokkan per poli: [ ['poli'=>'POLI ANAK', 'items'=>[...]], ... ] */
function jadwal_grouped(string $tanggal): array
{
    $groups = [];
    foreach (jadwal_hari($tanggal) as $r) {
        $key = (int) $r['poli_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['poli_id' => $key, 'poli' => $r['poli'], 'items' => []];
        }
        $groups[$key]['items'][] = [
            'dokter' => $r['dokter'],
            'jam'    => $r['jam'],
            'status' => $r['status'] === STATUS_TUTUP ? STATUS_TUTUP : STATUS_BUKA,
        ];
    }
    return array_values($groups);
}

/** Data yang dipakai halaman display & endpoint auto-refresh. */
function payload_display(): array
{
    $tanggal = tanggal_display();
    return [
        'ok'             => true,
        'tanggal'        => $tanggal,
        'tanggal_label'  => tgl_label($tanggal),
        'hari'           => HARI_PANJANG[hari_iso($tanggal)],
        'is_today'       => $tanggal === hari_ini(),
        'jumlah_dokter'  => (int) db()->query('SELECT COUNT(*) FROM jadwal WHERE tanggal = ' . db()->quote($tanggal))->fetchColumn(),
        'groups'         => jadwal_grouped($tanggal),
        'settings'       => [
            'nama_rs'         => setting('nama_rs'),
            'logo_url'        => setting('logo_url'),
            'footer_teks'     => setting('footer_teks'),
            'kecepatan_gulir' => (int) setting_num('kecepatan_gulir', 40),
            'refresh_detik'   => max(3, (int) setting_num('refresh_detik', 10)),
        ],
        'server_time'    => date('H:i:s'),
        /* Versi aset: dipakai display untuk memuat ulang sendiri bila CSS/JS diperbarui,
           supaya layar TV yang sudah lama terbuka tidak stuck dengan tampilan lama. */
        'asset_version'  => asset_versi(),
    ];
}

/* ------------------------------------------------------------------ */
/* Tampilan (layout admin, tema hijau tua)                             */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* Versi aset (cache-busting)                                          */
/* ------------------------------------------------------------------ */

/**
 * Versi aset dihitung dari waktu ubah terakhir berkas CSS/JS.
 * Dipakai sebagai parameter ?v=... supaya browser/perangkat (termasuk TV)
 * tidak memakai salinan lama dari cache setelah tema diperbarui.
 */
function asset_versi(): string
{
    static $versi = null;
    if ($versi !== null) {
        return $versi;
    }
    $waktu = 0;
    foreach (['style.css', 'display.js', 'admin.js', 'input-jadwal.js'] as $berkas) {
        $path = __DIR__ . '/assets/' . $berkas;
        if (is_file($path)) {
            $waktu = max($waktu, (int) filemtime($path));
        }
    }
    return $versi = ($waktu > 0 ? (string) $waktu : '1');
}

/** URL aset dengan versi, mis. assets/style.css?v=1759450000 */
function asset(string $berkas): string
{
    return 'assets/' . $berkas . '?v=' . asset_versi();
}

/* ------------------------------------------------------------------ */
/* Unggah media (logo rumah sakit) melalui proxy penyimpanan platform   */
/* ------------------------------------------------------------------ */

function media_token(): string
{
    $f = __DIR__ . '/.vibecoder-media-token';
    return is_readable($f) ? trim((string) file_get_contents($f)) : '';
}

/**
 * Kirim file ke proxy media platform (raw body + header token).
 * Hanya dipanggil dari sisi server — token tidak pernah dikirim ke browser.
 */
function media_upload(string $filePath, string $filename): array
{
    $token = media_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Penyimpanan media belum aktif (token belum tersedia).'];
    }
    $bytes = @file_get_contents($filePath);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'File tidak dapat dibaca.'];
    }
    $url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($filename);
    $out = false;
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return ['ok' => false, 'error' => 'Gagal menghubungi penyimpanan media: ' . $err];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "X-App-Media-Token: $token\r\nContent-Type: application/octet-stream\r\n",
            'content'       => $bytes,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($out === false) {
            return ['ok' => false, 'error' => 'Gagal menghubungi penyimpanan media.'];
        }
    }

    $json = json_decode((string) $out, true);
    if (is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
        return ['ok' => true, 'url' => (string) $json['url']];
    }
    $pesan = 'Penyimpanan media menolak file ini';
    if (is_array($json)) {
        $pesan = (string) ($json['error'] ?? $json['message'] ?? $pesan);
    }
    if ($code === 403) {
        $pesan = 'Kuota penyimpanan media penuh. ' . $pesan;
    }
    return ['ok' => false, 'error' => $pesan];
}

function flash(): void
{
    $ok  = $_GET['ok']  ?? '';
    $err = $_GET['err'] ?? '';
    if ($ok !== '') {
        echo '<div class="flash flash-ok">' . h($ok) . '</div>';
    }
    if ($err !== '') {
        echo '<div class="flash flash-err">' . h($err) . '</div>';
    }
}

function page_head(string $title, string $active = ''): void
{
    $user   = auth_user();
    $menu   = menu_akses();
    $jumlah = 0;
    foreach ($menu as $file => $label) {
        if ($user && !menu_diizinkan($user, $file)) {
            $jumlah++;
        }
    }
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — Jadwal Dokter</title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="admin">
<header class="topbar">
    <div class="brand">
        <span class="brand-dot"></span>
        <span class="brand-text">Jadwal Dokter<span class="brand-sub">Sistem Display Rumah Sakit</span></span>
    </div>
    <nav class="nav">
        <?php foreach ($menu as $file => $label): ?>
            <?php if ($user && menu_diizinkan($user, $file)): ?>
                <a class="nav-link<?= $active === $file ? ' is-active' : '' ?>" href="<?= h($file) ?>"><?= h($label) ?></a>
            <?php elseif ($user): ?>
                <span class="nav-link is-locked" title="Menu ini hanya untuk superadmin">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5zm-3 8V6a3 3 0 1 1 6 0v3H9zm3 4a1.75 1.75 0 0 1 1 3.2V18a1 1 0 0 1-2 0v-1.8A1.75 1.75 0 0 1 12 13z"/></svg>
                    <?= h($label) ?>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
        <a class="nav-link nav-display" href="index.php" target="_blank">Buka Display &#8599;</a>
        <?php if ($user): ?>
            <span class="nav-user">
                <?= h($user['username']) ?>
                <span class="nav-role"><?= h(label_role($user['role'])) ?></span>
            </span>
            <a class="nav-link nav-logout" href="logout.php">Keluar</a>
        <?php endif; ?>
    </nav>
</header>
<main class="wrap">
<?php if ($jumlah > 0): ?>
    <div class="lock-note">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5zm-3 8V6a3 3 0 1 1 6 0v3H9zm3 4a1.75 1.75 0 0 1 1 3.2V18a1 1 0 0 1-2 0v-1.8A1.75 1.75 0 0 1 12 13z"/></svg>
        <span>Anda masuk sebagai <strong><?= h(label_role($user['role'])) ?></strong>. Hanya menu Input Jadwal yang tersedia;
        <?= (int) $jumlah ?> menu lain terkunci dan hanya dapat dibuka oleh superadmin.</span>
    </div>
<?php endif; ?>
<?php
}

function page_end(): void
{
    ?>
</main>
</body>
</html>
<?php
}
