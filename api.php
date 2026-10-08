<?php
declare(strict_types=1);

/*
 * Endpoint data display (dipakai auto-refresh tiap 10 detik oleh index.php)
 * dan endpoint penyimpanan tanpa reload dari halaman Input Jadwal.
 * Contoh: api.php?action=display_data
 *         api.php?action=dokter&poli_id=3
 *         POST api.php?action=simpan_status
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$action = $_GET['action'] ?? 'display_data';

try {
    switch ($action) {
        /* Simpan perubahan status BUKA/TUTUP (dan penghapusan) tanpa reload halaman.
           Body JSON: {"status":{"<id>":"BUKA|TUTUP"},"hapus":[<id>,...]} */
        case 'simpan_status':
            if (!auth_user()) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Sesi Anda sudah berakhir. Silakan muat ulang halaman dan masuk kembali.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            /* Hanya menerima JSON: permintaan lintas situs dari form biasa tidak bisa
               memakai tipe konten ini, sehingga turut melindungi dari CSRF. */
            $tipe = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (strpos($tipe, 'application/json') === false) {
                http_response_code(415);
                echo json_encode(['ok' => false, 'error' => 'Format permintaan harus JSON.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $body   = json_decode((string) file_get_contents('php://input'), true);
            $status = is_array($body['status'] ?? null) ? $body['status'] : [];
            $hapus  = is_array($body['hapus'] ?? null) ? $body['hapus'] : [];

            $validStatus = [];
            foreach ($status as $id => $nilai) {
                $id = (int) $id;
                $nilai = ($nilai === STATUS_TUTUP) ? STATUS_TUTUP : (($nilai === STATUS_BUKA) ? STATUS_BUKA : null);
                if ($id > 0 && $nilai !== null) {
                    $validStatus[$id] = $nilai;
                }
            }
            $validHapus = [];
            foreach ($hapus as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $validHapus[$id] = true;
                }
            }

            if (!$validStatus && !$validHapus) {
                echo json_encode(['ok' => true, 'status_tersimpan' => 0, 'dihapus' => 0, 'pesan' => 'Tidak ada perubahan yang perlu disimpan.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $stStatus = $pdo->prepare('UPDATE jadwal SET status = ?, updated_at = ? WHERE id = ?');
                $stHapus  = $pdo->prepare('DELETE FROM jadwal WHERE id = ?');
                $waktu    = date('Y-m-d H:i:s');
                $nStatus  = 0;
                $nHapus   = 0;

                foreach ($validHapus as $id => $_) {
                    $stHapus->execute([$id]);
                    $nHapus += $stHapus->rowCount();
                    unset($validStatus[$id]);   // yang dihapus tidak perlu diubah statusnya
                }
                foreach ($validStatus as $id => $nilai) {
                    $stStatus->execute([$nilai, $waktu, $id]);
                    $nStatus += $stStatus->rowCount();
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }

            $bagian = [];
            if ($nStatus > 0) {
                $bagian[] = $nStatus . ' status diperbarui';
            }
            if ($nHapus > 0) {
                $bagian[] = $nHapus . ' jadwal dihapus';
            }
            $pesan = $bagian ? ('Tersimpan: ' . implode(', ', $bagian) . '.') : 'Tidak ada perubahan yang tersimpan.';

            echo json_encode([
                'ok'                => true,
                'status_tersimpan'  => $nStatus,
                'dihapus'           => $nHapus,
                'pesan'             => $pesan,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'dokter':
            /* Data master dokter hanya untuk pengguna yang sudah masuk. */
            if (!auth_user()) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Perlu login untuk mengakses data ini.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $poliId = isset($_GET['poli_id']) ? (int) $_GET['poli_id'] : 0;
            $rows   = [];
            foreach (daftar_dokter($poliId > 0 ? $poliId : null) as $d) {
                if ($poliId > 0 && (int) $d['poli_id'] !== $poliId) {
                    continue;
                }
                $rows[] = ['id' => (int) $d['id'], 'nama' => $d['nama']];
            }
            echo json_encode(['ok' => true, 'dokter' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'display_data':
        default:
            echo json_encode(payload_display(), JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
