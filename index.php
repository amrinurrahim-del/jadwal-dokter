<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$payload = payload_display();
$s       = $payload['settings'];
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Display Jadwal Dokter — <?= h($s['nama_rs']) ?></title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="display">
<div class="stage">

    <header class="hdr">
        <div class="logo-panel" id="logoPanel">
            <?php if ($s['logo_url'] !== ''): ?>
                <img id="logoImg" src="<?= h($s['logo_url']) ?>" alt="Logo <?= h($s['nama_rs']) ?>">
            <?php else: ?>
                <div class="logo-placeholder">LOGO</div>
            <?php endif; ?>
        </div>

        <div class="hdr-name">
            <h1 id="namaRs"><?= h($s['nama_rs']) ?></h1>
            <div class="hdr-sub">
                <span id="hdrTanggal"><?= h($payload['tanggal_label']) ?></span>
                <span class="tag" id="tagJumlah"><?= (int) $payload['jumlah_dokter'] ?> jadwal</span>
                <span class="tag tag-warn" id="tagTanggalPilihan" style="display:none">Menampilkan tanggal pilihan</span>
            </div>
        </div>

        <div class="hdr-clock">
            <div class="jam" id="clock"><?= h(date('H:i:s')) ?></div>
            <div class="tgl" id="clockDate">WITA</div>
        </div>

        <div class="display-actions">
            <a class="icon-btn" href="input.php" title="Input / ubah jadwal" aria-label="Input jadwal">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zM20.7 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </a>
            <a class="icon-btn" href="pengaturan.php" title="Pengaturan display" aria-label="Pengaturan display">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94a7.6 7.6 0 0 0 .06-.94c0-.32-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.1 7.1 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.65 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.62-.06.94s.02.63.06.94l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.31.6.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.09.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg>
            </a>
            <button class="icon-btn" type="button" id="btnFullscreen" title="Tampilkan layar penuh" aria-label="Tampilkan layar penuh">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
            </button>
        </div>
    </header>

    <div class="viewport" id="viewport">
        <div class="track" id="track"></div>
    </div>

    <div class="net-warn" id="netWarn">Koneksi ke server terputus — mencoba menyambung ulang…</div>

    <footer class="footer">
        <div class="footer-track" id="footerTrack">
            <span></span><span></span>
        </div>
    </footer>
</div>

<script>
window.INITIAL_DATA = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.ASSET_VERSION = <?= json_encode(asset_versi()) ?>;
</script>
<script src="<?= asset('display.js') ?>"></script>
</body>
</html>
