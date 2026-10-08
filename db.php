<?php
declare(strict_types=1);

/*
 * Koneksi database (SQLite) + pembuatan skema.
 * Zona waktu mengikuti WITA (Asia/Makassar) sesuai jam operasional poli.
 */
date_default_timezone_set('Asia/Makassar');

const JAM_OPTIONS   = ['08.00-13.00 WITA', '15.00-20.00 WITA'];
const STATUS_BUKA   = 'BUKA';
const STATUS_TUTUP  = 'TUTUP';

const POLI_SEED = [
    'POLI ANAK',
    'POLI BEDAH',
    'POLI GIGI',
    'POLI GIZI',
    'POLI INTERNA',
    'POLI JANTUNG',
    'POLI JIWA',
    'POLI KANDUNGAN',
    'POLI KULIT DAN KELAMIN',
    'POLI MATA',
    'POLI PARU',
    'POLI SARAF',
    'POLI THT',
];

const HARI_PANJANG = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
const BULAN_ID     = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                      7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!class_exists('PDO')) {
        http_response_code(500);
        exit('Ekstensi database (PDO SQLite) tidak tersedia di server ini.');
    }

    /* JADWAL_DB hanya dipakai saat pengujian lokal, produksi memakai default di bawah. */
    $path = getenv('JADWAL_DB') ?: (__DIR__ . '/data/jadwal.sqlite');
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    /* Urutan penting: busy_timeout -> WAL -> synchronous (WAL aman dengan NORMAL). */
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    schema_ensure($pdo);
    return $pdo;
}

/**
 * Membuat tabel & data awal. Seluruh proses dibungkus SATU transaksi
 * (BEGIN IMMEDIATE) supaya pernyataan idempotent ini tidak memicu fsync per statement.
 */
function schema_ensure(PDO $pdo): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS pengaturan (
            kunci TEXT PRIMARY KEY,
            nilai TEXT NOT NULL DEFAULT ""
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS poli (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL UNIQUE,
            urutan INTEGER NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS dokter (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poli_id INTEGER NOT NULL,
            nama TEXT NOT NULL,
            aktif INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT ""
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS jadwal (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tanggal TEXT NOT NULL,
            poli_id INTEGER NOT NULL,
            dokter_id INTEGER NOT NULL,
            jam TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "BUKA",
            updated_at TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jadwal_tanggal ON jadwal (tanggal)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jadwal_dokter ON jadwal (tanggal, poli_id, dokter_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS template (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT ""
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS template_item (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            hari INTEGER NOT NULL,
            poli_id INTEGER NOT NULL,
            dokter_id INTEGER NOT NULL,
            jam TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "BUKA"
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_item ON template_item (template_id, hari)');

        /* --- Akun & sesi (login 2 user: superadmin & admin) --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS user (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT "",
            failed_count INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS sesi (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT "",
            expires_at TEXT NOT NULL DEFAULT "",
            last_seen TEXT NOT NULL DEFAULT ""
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS reset_token (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT "",
            expires_at TEXT NOT NULL DEFAULT "",
            used_at TEXT NULL,
            ip TEXT NOT NULL DEFAULT ""
        )');

        /* --- Data awal: 2 akun bawaan --- */
        $adaUser = (int) $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn();
        if ($adaUser === 0) {
            $insU = $pdo->prepare('INSERT INTO user (username, password_hash, role, updated_at) VALUES (?, ?, ?, ?)');
            $insU->execute(['superadmin', password_hash('superadmin', PASSWORD_DEFAULT), 'superadmin', date('Y-m-d H:i:s')]);
            $insU->execute(['admin', password_hash('admin', PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
        }

        /* --- Data awal: daftar poli --- */
        $adaPoli = (int) $pdo->query('SELECT COUNT(*) FROM poli')->fetchColumn();
        if ($adaPoli === 0) {
            $ins = $pdo->prepare('INSERT INTO poli (nama, urutan) VALUES (?, ?)');
            foreach (POLI_SEED as $i => $nama) {
                $ins->execute([$nama, $i + 1]);
            }
        }

        /* --- Pengaturan default --- */
        $defaults = [
            'nama_rs'         => 'RUMAH SAKIT SEHAT SENTOSA',
            'logo_url'        => '',
            'footer_teks'     => 'Selamat datang di Rumah Sakit kami — Jadwal poli dapat berubah sewaktu-waktu. Info pendaftaran: (0411) 123-4567.',
            'kecepatan_gulir' => '40',
            'refresh_detik'   => '10',
            'tanggal_aktif'   => '',
            'demo_seeded'     => '0',
        ];
        $insSet = $pdo->prepare('INSERT OR IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $insSet->execute([$k, $v]);
        }

        /* --- Contoh data (sekali saja, agar display tidak kosong) --- */
        $sudahSeed = (string) $pdo->query('SELECT nilai FROM pengaturan WHERE kunci = "demo_seeded"')->fetchColumn();
        $jmlDokter = (int) $pdo->query('SELECT COUNT(*) FROM dokter')->fetchColumn();
        if ($sudahSeed === '0' && $jmlDokter === 0) {
            seed_demo($pdo);
        }
        $pdo->exec('UPDATE pengaturan SET nilai = "1" WHERE kunci = "demo_seeded"');

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function seed_demo(PDO $pdo): void
{
    $poliId = [];
    foreach ($pdo->query('SELECT id, nama FROM poli') as $r) {
        $poliId[$r['nama']] = (int) $r['id'];
    }
    $dokter = [
        ['POLI ANAK', 'dr. Andi Pratama, Sp.A'],
        ['POLI ANAK', 'dr. Sari Melati, Sp.A'],
        ['POLI JANTUNG', 'dr. Budi Santoso, Sp.JP'],
        ['POLI GIGI', 'drg. Rina Kartika'],
        ['POLI MATA', 'dr. Yusuf Hamid, Sp.M'],
        ['POLI THT', 'dr. Hendra Gunawan, Sp.THT-KL'],
        ['POLI SARAF', 'dr. Maya Anggraini, Sp.S'],
    ];
    $insD = $pdo->prepare('INSERT INTO dokter (poli_id, nama, aktif, created_at) VALUES (?, ?, 1, ?)');
    $now  = date('Y-m-d H:i:s');
    $idDokter = [];
    foreach ($dokter as [$poli, $nama]) {
        if (!isset($poliId[$poli])) {
            continue;
        }
        $insD->execute([$poliId[$poli], $nama, $now]);
        $idDokter[$nama] = (int) $pdo->lastInsertId();
    }
    $hari = date('Y-m-d');
    $insJ = $pdo->prepare('INSERT INTO jadwal (tanggal, poli_id, dokter_id, jam, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $rows = [
        ['dr. Andi Pratama, Sp.A',   'POLI ANAK',    '08.00-13.00 WITA', STATUS_BUKA],
        ['dr. Sari Melati, Sp.A',    'POLI ANAK',    '15.00-20.00 WITA', STATUS_BUKA],
        ['dr. Budi Santoso, Sp.JP',  'POLI JANTUNG', '08.00-13.00 WITA', STATUS_BUKA],
        ['drg. Rina Kartika',        'POLI GIGI',    '08.00-13.00 WITA', STATUS_TUTUP],
        ['dr. Yusuf Hamid, Sp.M',    'POLI MATA',    '15.00-20.00 WITA', STATUS_TUTUP],
        ['dr. Hendra Gunawan, Sp.THT-KL', 'POLI THT', '08.00-13.00 WITA', STATUS_BUKA],
        ['dr. Maya Anggraini, Sp.S', 'POLI SARAF',   '15.00-20.00 WITA', STATUS_BUKA],
    ];
    foreach ($rows as [$nama, $poli, $jam, $status]) {
        if (!isset($idDokter[$nama], $poliId[$poli])) {
            continue;
        }
        $insJ->execute([$hari, $poliId[$poli], $idDokter[$nama], $jam, $status, $now]);
    }
}
