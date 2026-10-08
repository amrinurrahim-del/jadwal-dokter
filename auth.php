<?php
declare(strict_types=1);

/*
 * Autentikasi & hak akses.
 *
 * Dua akun tetap (tanpa pendaftaran):
 *   - superadmin : akses penuh ke seluruh aplikasi
 *   - admin      : HANYA menu Input Jadwal, menu lain terkunci
 *
 * Sesi disimpan di database (tabel `sesi`) memakai cookie acak, bukan session PHP,
 * supaya tidak bergantung pada folder penyimpanan session milik server.
 * Kata sandi disimpan sebagai hash (password_hash), tidak pernah sebagai teks biasa.
 */

require_once __DIR__ . '/db.php';

const DEFAULT_SUPER_USER = 'superadmin';
const DEFAULT_SUPER_PASS = 'superadmin';
const DEFAULT_ADMIN_USER = 'admin';
const DEFAULT_ADMIN_PASS = 'admin';

const SESI_COOKIE   = 'jd_sesi';
const SESI_JAM      = 8;    // masa berlaku sesi (jam), diperpanjang tiap aktivitas
const MAX_GAGAL     = 5;    // percobaan login gagal sebelum dikunci sementara
const KUNCI_DETIK   = 300;  // lama penguncian (detik)
const RESET_MENIT   = 60;   // masa berlaku tautan reset (menit)
const EMAIL_DEVELOPER = 'amrinurrahim@gmail.com';

/* ------------------------------------------------------------------ */
/* Dasar                                                              */
/* ------------------------------------------------------------------ */

function request_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function shift_time(int $detik): string
{
    return date('Y-m-d H:i:s', time() + $detik);
}

function sesi_set_cookie(string $token, int $detik): void
{
    setcookie(SESI_COOKIE, $token, [
        'expires'  => time() + $detik,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_https(),
    ]);
    $_COOKIE[SESI_COOKIE] = $token;
}

function sesi_hapus_cookie(): void
{
    setcookie(SESI_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_https(),
    ]);
    unset($_COOKIE[SESI_COOKIE]);
}

/* ------------------------------------------------------------------ */
/* Pengguna                                                           */
/* ------------------------------------------------------------------ */

/** Daftar 2 akun (urutan tetap: superadmin lalu admin). */
function daftar_user(): array
{
    return db()->query('SELECT id, username, role, updated_at, failed_count, locked_until
                        FROM user
                        ORDER BY CASE role WHEN "superadmin" THEN 1 ELSE 2 END')->fetchAll();
}

function user_by_role(string $role): ?array
{
    $st = db()->prepare('SELECT id, username, role, password_hash, updated_at FROM user WHERE role = ? LIMIT 1');
    $st->execute([$role]);
    return $st->fetch() ?: null;
}

function label_role(string $role): string
{
    return $role === 'superadmin' ? 'Superadmin' : ($role === 'admin' ? 'Admin Poli' : $role);
}

/** True bila akun masih memakai kata sandi bawaan (untuk peringatan di Pengaturan). */
function password_masih_bawaan(): array
{
    $hasil = [];
    foreach (daftar_user() as $u) {
        $st = db()->prepare('SELECT password_hash FROM user WHERE id = ?');
        $st->execute([(int) $u['id']]);
        $hash = (string) $st->fetchColumn();
        $bawaan = $u['role'] === 'superadmin' ? DEFAULT_SUPER_PASS : DEFAULT_ADMIN_PASS;
        $bawaanUser = $u['role'] === 'superadmin' ? DEFAULT_SUPER_USER : DEFAULT_ADMIN_USER;
        if (password_verify($bawaan, $hash)) {
            $hasil[] = $u['username'] . ' (kata sandi masih "' . $bawaan . '")';
        } elseif (strtolower($u['username']) === $bawaanUser) {
            $hasil[] = $u['username'] . ' (username masih bawaan)';
        }
    }
    return $hasil;
}

/* ------------------------------------------------------------------ */
/* Sesi                                                               */
/* ------------------------------------------------------------------ */

function auth_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;

    $token = (string) ($_COOKIE[SESI_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);

    $st = db()->prepare('SELECT s.token_hash, s.expires_at, u.id, u.username, u.role
                         FROM sesi s JOIN user u ON u.id = s.user_id
                         WHERE s.token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        db()->prepare('DELETE FROM sesi WHERE token_hash = ?')->execute([$hash]);
        return null;
    }

    /* Perpanjang masa sesi selama masih dipakai. */
    db()->prepare('UPDATE sesi SET last_seen = ?, expires_at = ? WHERE token_hash = ?')
        ->execute([now(), shift_time(SESI_JAM * 3600), $hash]);

    $cache = [
        'id'         => (int) $row['id'],
        'username'   => (string) $row['username'],
        'role'       => (string) $row['role'],
        'token_hash' => $hash,
    ];
    return $cache;
}

function auth_buat_sesi(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM sesi WHERE expires_at < ?')->execute([now()]);
    db()->prepare('INSERT INTO sesi (token_hash, user_id, created_at, expires_at, last_seen) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, now(), shift_time(SESI_JAM * 3600), now()]);
    sesi_set_cookie($token, SESI_JAM * 3600);
}

function auth_login(string $username, string $password): array
{
    $username = trim($username);
    $st = db()->prepare('SELECT * FROM user WHERE lower(username) = lower(?) LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user) {
        return ['ok' => false, 'error' => 'Username atau kata sandi salah.'];
    }

    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        $sisa = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
        return ['ok' => false, 'error' => 'Akun dikunci sementara karena terlalu banyak percobaan gagal. Coba lagi sekitar ' . $sisa . ' menit lagi.'];
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        $gagal = (int) $user['failed_count'] + 1;
        if ($gagal >= MAX_GAGAL) {
            db()->prepare('UPDATE user SET failed_count = 0, locked_until = ? WHERE id = ?')
                ->execute([shift_time(KUNCI_DETIK), (int) $user['id']]);
            return ['ok' => false, 'error' => 'Terlalu banyak percobaan gagal. Akun dikunci sementara ' . (int) ceil(KUNCI_DETIK / 60) . ' menit.'];
        }
        db()->prepare('UPDATE user SET failed_count = ? WHERE id = ?')->execute([$gagal, (int) $user['id']]);
        $sisaCoba = MAX_GAGAL - $gagal;
        return ['ok' => false, 'error' => 'Username atau kata sandi salah. Sisa ' . $sisaCoba . ' percobaan sebelum akun dikunci sementara.'];
    }

    db()->prepare('UPDATE user SET failed_count = 0, locked_until = NULL WHERE id = ?')->execute([(int) $user['id']]);
    auth_buat_sesi((int) $user['id']);

    return ['ok' => true, 'user' => [
        'id'       => (int) $user['id'],
        'username' => (string) $user['username'],
        'role'     => (string) $user['role'],
    ]];
}

function auth_logout(): void
{
    $token = (string) ($_COOKIE[SESI_COOKIE] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        db()->prepare('DELETE FROM sesi WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    sesi_hapus_cookie();
}

/** Putuskan sesi milik satu pengguna (dipakai setelah kata sandi diubah). */
function auth_hapus_sesi_user(int $userId, string $kecualiHash = ''): void
{
    if ($kecualiHash !== '') {
        db()->prepare('DELETE FROM sesi WHERE user_id = ? AND token_hash <> ?')->execute([$userId, $kecualiHash]);
    } else {
        db()->prepare('DELETE FROM sesi WHERE user_id = ?')->execute([$userId]);
    }
}

/* ------------------------------------------------------------------ */
/* Hak akses menu                                                     */
/* ------------------------------------------------------------------ */

function menu_akses(): array
{
    return [
        'input.php'      => 'Input Jadwal',
        'master.php'     => 'Master Dokter',
        'template.php'   => 'Template Mingguan',
        'pengaturan.php' => 'Pengaturan',
    ];
}

/** Admin hanya boleh membuka Input Jadwal; superadmin boleh semuanya. */
function menu_diizinkan(array $user, string $file): bool
{
    return $user['role'] === 'superadmin' || $file === 'input.php';
}

function halaman_diizinkan(string $file): bool
{
    return array_key_exists($file, menu_akses());
}

/** Halaman (file tujuan) yang aman dipakai pada parameter ?next= . */
function next_aman(?string $next): string
{
    $next = basename((string) $next);
    return halaman_diizinkan($next) ? $next : 'input.php';
}

function require_login(): array
{
    $user = auth_user();
    if (!$user) {
        $self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'input.php'));
        redirect('login.php?next=' . urlencode($self));
    }
    return $user;
}

function require_superadmin(): array
{
    $user = require_login();
    if ($user['role'] !== 'superadmin') {
        redirect('input.php?err=' . urlencode('Menu ini hanya dapat diakses oleh superadmin.'));
    }
    return $user;
}

/* ------------------------------------------------------------------ */
/* Ubah username / kata sandi                                         */
/* ------------------------------------------------------------------ */

function auth_ubah_kredensial(int $userId, string $usernameBaru, string $passBaru, string $passUlang, string $passPengawas): array
{
    $pengawas = auth_user();
    if (!$pengawas) {
        return ['ok' => false, 'error' => 'Sesi Anda sudah berakhir. Silakan login ulang.'];
    }

    $usernameBaru = trim($usernameBaru);

    $st = db()->prepare('SELECT password_hash FROM user WHERE id = ?');
    $st->execute([(int) $pengawas['id']]);
    if (!password_verify($passPengawas, (string) $st->fetchColumn())) {
        return ['ok' => false, 'error' => 'Kata sandi Anda (superadmin) tidak sesuai. Perubahan dibatalkan.'];
    }
    if (strlen($usernameBaru) < 3) {
        return ['ok' => false, 'error' => 'Username minimal 3 karakter.'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $usernameBaru)) {
        return ['ok' => false, 'error' => 'Username hanya boleh huruf, angka, titik, garis bawah, atau strip (3-32 karakter).'];
    }
    $st = db()->prepare('SELECT id FROM user WHERE lower(username) = lower(?) AND id <> ?');
    $st->execute([$usernameBaru, $userId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'error' => 'Username "' . $usernameBaru . '" sudah dipakai akun lain.'];
    }

    $passBaru = (string) $passBaru;
    if ($passBaru !== '') {
        if (strlen($passBaru) < 4) {
            return ['ok' => false, 'error' => 'Kata sandi minimal 4 karakter.'];
        }
        if ($passBaru !== $passUlang) {
            return ['ok' => false, 'error' => 'Ulangi kata sandi tidak sama dengan kata sandi baru.'];
        }
    }

    if ($passBaru !== '') {
        db()->prepare('UPDATE user SET username = ?, password_hash = ?, updated_at = ?, failed_count = 0, locked_until = NULL WHERE id = ?')
            ->execute([$usernameBaru, password_hash($passBaru, PASSWORD_DEFAULT), now(), $userId]);
        /* Putuskan sesi lain milik akun ini, kecuali sesi yang sedang dipakai. */
        auth_hapus_sesi_user($userId, (string) $pengawas['token_hash']);
    } else {
        db()->prepare('UPDATE user SET username = ?, updated_at = ? WHERE id = ?')
            ->execute([$usernameBaru, now(), $userId]);
    }

    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* Lupa username / kata sandi (tautan reset)                          */
/* ------------------------------------------------------------------ */

function auth_buat_reset(int $userId): array
{
    db()->prepare('DELETE FROM reset_token WHERE expires_at < ? OR used_at IS NOT NULL')->execute([now()]);

    /* Cukup 3 tautan aktif per akun; yang paling lama dihapus. */
    $st = db()->prepare('SELECT token_hash FROM reset_token WHERE user_id = ? AND used_at IS NULL ORDER BY created_at DESC');
    $st->execute([$userId]);
    $lama = array_slice($st->fetchAll(PDO::FETCH_COLUMN), 3);
    foreach ($lama as $h) {
        db()->prepare('DELETE FROM reset_token WHERE token_hash = ?')->execute([$h]);
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO reset_token (token_hash, user_id, created_at, expires_at, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, now(), shift_time(RESET_MENIT * 60), (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);

    return ['token' => $token, 'berlaku_menit' => RESET_MENIT];
}

function auth_link_reset(string $token): string
{
    $scheme = request_https() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/lupa.php'));
    $dir    = rtrim(dirname($script), '/');
    return $scheme . '://' . $host . $dir . '/reset.php?token=' . urlencode($token);
}

/** Cari token reset yang valid. */
function auth_verifikasi_reset(?string $token): ?array
{
    $token = (string) $token;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT t.token_hash, t.expires_at, t.used_at, u.id AS user_id, u.username, u.role
                         FROM reset_token t JOIN user u ON u.id = t.user_id
                         WHERE t.token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row || $row['used_at'] !== null) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    return [
        'token_hash' => (string) $row['token_hash'],
        'user_id'    => (int) $row['user_id'],
        'username'   => (string) $row['username'],
        'role'       => (string) $row['role'],
        'expires_at' => (string) $row['expires_at'],
    ];
}

/** Ubah kredensial lewat tautan reset (tanpa login, cukup token yang valid). */
function auth_pakai_reset(string $token, string $usernameBaru, string $passBaru, string $passUlang): array
{
    $info = auth_verifikasi_reset($token);
    if (!$info) {
        return ['ok' => false, 'error' => 'Tautan reset tidak valid, sudah dipakai, atau sudah kedaluwarsa.'];
    }

    $usernameBaru = trim($usernameBaru);
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $usernameBaru)) {
        return ['ok' => false, 'error' => 'Username hanya boleh huruf, angka, titik, garis bawah, atau strip (3-32 karakter).'];
    }
    $st = db()->prepare('SELECT id FROM user WHERE lower(username) = lower(?) AND id <> ?');
    $st->execute([$usernameBaru, $info['user_id']]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'error' => 'Username "' . $usernameBaru . '" sudah dipakai akun lain.'];
    }
    if (strlen($passBaru) < 4) {
        return ['ok' => false, 'error' => 'Kata sandi minimal 4 karakter.'];
    }
    if ($passBaru !== $passUlang) {
        return ['ok' => false, 'error' => 'Ulangi kata sandi tidak sama dengan kata sandi baru.'];
    }

    db()->prepare('UPDATE user SET username = ?, password_hash = ?, updated_at = ?, failed_count = 0, locked_until = NULL WHERE id = ?')
        ->execute([$usernameBaru, password_hash($passBaru, PASSWORD_DEFAULT), now(), $info['user_id']]);
    db()->prepare('UPDATE reset_token SET used_at = ? WHERE token_hash = ?')->execute([now(), $info['token_hash']]);
    auth_hapus_sesi_user($info['user_id']);

    return ['ok' => true, 'username' => $usernameBaru, 'role' => $info['role']];
}
