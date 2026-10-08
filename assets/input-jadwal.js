/* ==========================================================================
   Input Jadwal — simpan perubahan tanpa memuat ulang halaman.
   Tombol "Set BUKA/TUTUP" dan "Hapus" hanya menandai perubahan di browser;
   satu tombol "Simpan Semua Perubahan" mengirim semuanya ke api.php sekaligus.
   ========================================================================== */
(function () {
    'use strict';

    var bar     = document.getElementById('barPerubahan');
    var wrap    = document.querySelector('.card');          // tempat pesan hasil simpan
    var kotak   = document.getElementById('pesanJadwal');
    var teks    = document.getElementById('teksPerubahan');
    var label   = document.getElementById('labelSimpan');
    var btnSimpan = document.getElementById('btnSimpanPerubahan');
    var btnBatal  = document.getElementById('btnBatalkan');
    var penghitung = document.getElementById('jumlahJadwalTanggal');

    if (!bar || !btnSimpan) { return; }

    var perubahanStatus = {};   // { id: 'BUKA' | 'TUTUP' }
    var perubahanHapus  = {};   // { id: true }
    var menyimpan       = false;
    var sudahSimpan     = false; // penanda agar form boleh dikirim setelah simpan otomatis

    /* ------------------------------ Utilitas ------------------------------ */

    function baris(id) {
        return document.querySelector('tr[data-jadwal="' + id + '"]');
    }

    function jumlahPerubahan() {
        return Object.keys(perubahanStatus).length + Object.keys(perubahanHapus).length;
    }

    function adaPerubahan() {
        return jumlahPerubahan() > 0;
    }

    function tampilkanPesan(teksPesan, jenis) {
        if (!kotak) { return; }
        kotak.innerHTML = '';
        var div = document.createElement('div');
        div.className = 'flash ' + (jenis === 'err' ? 'flash-err' : (jenis === 'info' ? 'flash-info' : 'flash-ok'));
        div.textContent = teksPesan;
        kotak.appendChild(div);
        if (kotak.scrollIntoView) {
            kotak.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    /* --------------------------- Tandai perubahan ------------------------- */

    function gambarBaris(row) {
        if (!row) { return; }
        var id = row.getAttribute('data-jadwal');
        var asli = row.getAttribute('data-status');
        var statusKini = perubahanStatus[id] || asli;

        var badge = row.querySelector('[data-badge]');
        if (badge) {
            badge.textContent = statusKini;
            badge.className = 'badge ' + (statusKini === 'TUTUP' ? 'badge-tutup' : 'badge-buka');
        }
        var lbl = row.querySelector('[data-label]');
        if (lbl) {
            lbl.textContent = statusKini === 'TUTUP' ? 'Set BUKA' : 'Set TUTUP';
        }

        var diubahStatus = Object.prototype.hasOwnProperty.call(perubahanStatus, id);
        row.classList.toggle('row-pending', diubahStatus || !!perubahanHapus[id]);
        row.classList.toggle('row-hapus', !!perubahanHapus[id]);

        var tombolHapus = row.querySelector('.btn-hapus');
        if (tombolHapus) {
            tombolHapus.textContent = perubahanHapus[id] ? 'Batalkan Hapus' : 'Hapus';
        }
        var tombolToggle = row.querySelector('.btn-toggle');
        if (tombolToggle) {
            tombolToggle.setAttribute('title', diubahStatus
                ? 'Perubahan belum disimpan — klik untuk mengembalikan'
                : 'Ubah status BUKA/TUTUP tanpa memuat ulang');
        }
    }

    function perbaruiBar() {
        var n = jumlahPerubahan();
        if (n === 0) {
            /* Tidak ada perubahan: bilah disembunyikan dan tombolnya dinonaktifkan,
               supaya tidak ada tombol "diam" yang bisa diklik tanpa hasil. */
            bar.hidden = true;
            btnSimpan.disabled = true;
            btnBatal.disabled = true;
            document.body.classList.remove('ada-pending');
            return;
        }
        btnSimpan.disabled = false;
        btnBatal.disabled = false;
        var bagian = [];
        if (Object.keys(perubahanStatus).length) {
            bagian.push(Object.keys(perubahanStatus).length + ' perubahan status');
        }
        if (Object.keys(perubahanHapus).length) {
            bagian.push(Object.keys(perubahanHapus).length + ' jadwal ditandai hapus');
        }
        teks.textContent = bagian.join(' · ') + ' belum disimpan.';
        bar.hidden = false;
        document.body.classList.add('ada-pending');
        hitungUlangJumlah();
    }

    function hitungUlangJumlah() {
        if (!penghitung) { return; }
        var total = document.querySelectorAll('tr[data-jadwal]').length - Object.keys(perubahanHapus).length;
        penghitung.textContent = total < 0 ? 0 : total;
    }

    /* ------------------------------ Aksi klik ----------------------------- */

    document.addEventListener('click', function (e) {
        var tombolToggle = e.target.closest('.btn-toggle');
        if (tombolToggle) {
            var id = tombolToggle.getAttribute('data-id');
            var row = baris(id);
            if (!row) { return; }
            var asli = row.getAttribute('data-status');
            var kini = perubahanStatus[id] || asli;
            var baru = kini === 'BUKA' ? 'TUTUP' : 'BUKA';
            if (baru === asli) {
                delete perubahanStatus[id];
            } else {
                perubahanStatus[id] = baru;
            }
            gambarBaris(row);
            perbaruiBar();
            return;
        }

        var tombolHapus = e.target.closest('.btn-hapus');
        if (tombolHapus) {
            var idH = tombolHapus.getAttribute('data-id');
            var rowH = baris(idH);
            if (!rowH) { return; }
            if (perubahanHapus[idH]) {
                delete perubahanHapus[idH];
            } else {
                perubahanHapus[idH] = true;
                delete perubahanStatus[idH];   // tidak perlu ubah status jadwal yang akan dihapus
            }
            gambarBaris(rowH);
            perbaruiBar();
            return;
        }

        var linkEdit = e.target.closest('.btn-edit');
        if (linkEdit && adaPerubahan()) {
            e.preventDefault();
            var tujuan = linkEdit.getAttribute('href');
            simpan().then(function (ok) {
                if (ok) { sudahSimpan = true; window.location.href = tujuan; }
            });
            return;
        }

        if (e.target.closest('#btnSimpanPerubahan')) {
            simpan();
            return;
        }

        if (e.target.closest('#btnBatalkan')) {
            perubahanStatus = {};
            perubahanHapus = {};
            document.querySelectorAll('tr[data-jadwal]').forEach(gambarBaris);
            perbaruiBar();
            tampilkanPesan('Perubahan yang belum disimpan dibatalkan — data di database tidak berubah.', 'err');
        }
    });

    /* ------------------------------- Simpan ------------------------------- */

    function simpan() {
        if (menyimpan) { return Promise.resolve(false); }
        if (!adaPerubahan()) {
            /* Dulu di sini fungsi berhenti tanpa pesan apa pun sehingga tombol terasa
               "tidak berfungsi". Sekarang pengguna selalu dapat umpan balik. */
            tampilkanPesan('Tidak ada perubahan yang perlu disimpan.', 'info');
            return Promise.resolve(true);
        }

        menyimpan = true;
        btnSimpan.disabled = true;
        btnBatal.disabled = true;
        var labelAsli = label.textContent;
        label.textContent = 'Menyimpan…';

        var muatan = { status: perubahanStatus, hapus: Object.keys(perubahanHapus).map(Number) };

        /* Batas waktu: kalau koneksi menggantung, jangan biarkan tombol terkunci selamanya. */
        var kontrol = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var batas = setTimeout(function () { if (kontrol) { kontrol.abort(); } }, 15000);

        return fetch('api.php?action=simpan_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            signal: kontrol ? kontrol.signal : undefined,
            body: JSON.stringify(muatan)
        })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (hasil) {
                if (!hasil.data || !hasil.data.ok) {
                    throw new Error((hasil.data && hasil.data.error) || 'Gagal menyimpan perubahan.');
                }
                Object.keys(perubahanHapus).forEach(function (id) {
                    var row = baris(id);
                    if (row) { row.parentNode.removeChild(row); }
                });
                Object.keys(perubahanStatus).forEach(function (id) {
                    var row = baris(id);
                    if (row) { row.setAttribute('data-status', perubahanStatus[id]); }
                });
                perubahanStatus = {};
                perubahanHapus = {};
                document.querySelectorAll('tr[data-jadwal]').forEach(gambarBaris);
                perbaruiBar();
                hitungUlangJumlah();
                tampilkanPesan(hasil.data.pesan + ' Display akan mengikuti maksimal 10 detik lagi.', 'ok');
                return true;
            })
            .catch(function (err) {
                var sebab = (err && err.name === 'AbortError')
                    ? 'Waktu penyimpanan habis (koneksi lambat).'
                    : 'Perubahan gagal disimpan: ' + (err && err.message ? err.message : 'gangguan koneksi') + '.';
                tampilkanPesan(sebab + ' Perubahan Anda masih ditahan di halaman ini — tekan Simpan Semua Perubahan lagi.', 'err');
                return false;
            })
            .then(function (ok) {
                clearTimeout(batas);
                menyimpan = false;
                label.textContent = labelAsli;
                /* perbaruiBar() mengatur ulang keadaan tombol sesuai sisa perubahan,
                   jadi tombol tidak pernah tertinggal dalam keadaan nonaktif. */
                perbaruiBar();
                return ok;
            });
    }

    /* -------- Jangan kehilangan perubahan saat berpindah halaman/form ------- */

    /* Kirim form setelah memastikan perubahan tertahan tersimpan lebih dulu.
       Dipakai oleh submit tombol maupun form yang dikirim otomatis (mis. ganti tanggal),
       sebab form.submit() programatik TIDAK memicu event submit. */
    function lanjutkan(form) {
        if (!form) { return; }
        if (!sudahSimpan && adaPerubahan() && !menyimpan) {
            simpan().then(function (ok) {
                if (!ok) { return; }
                sudahSimpan = true;
                form.submit();
            });
            return;
        }
        form.submit();
    }

    /* Ganti tanggal kerja secara otomatis (dulu lewat onchange="this.form.submit()",
       yang melewati penyimpanan perubahan tertahan). */
    var navTanggal = document.getElementById('tanggalNav');
    if (navTanggal) {
        navTanggal.addEventListener('change', function () { lanjutkan(navTanggal.form); });
    }

    /* Sinkronkan keadaan awal: bilah tersembunyi & tombol nonaktif saat belum ada perubahan. */
    perbaruiBar();

    window.addEventListener('beforeunload', function (e) {
        if (adaPerubahan() && !menyimpan) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.addEventListener('submit', function (e) {
        if (sudahSimpan || !adaPerubahan() || menyimpan) { return; }
        e.preventDefault();
        lanjutkan(e.target);
    }, true);
})();
