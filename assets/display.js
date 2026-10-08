/* ==========================================================================
   Display jadwal dokter — auto-refresh, gulir otomatis, jam realtime
   ========================================================================== */
(function () {
    'use strict';

    var track        = document.getElementById('track');
    var viewport     = document.getElementById('viewport');
    var footerTrack  = document.getElementById('footerTrack');
    var netWarn      = document.getElementById('netWarn');
    var elNamaRs     = document.getElementById('namaRs');
    var elTanggal    = document.getElementById('hdrTanggal');
    var elJumlah     = document.getElementById('tagJumlah');
    var elTagPilihan = document.getElementById('tagTanggalPilihan');
    var elClock      = document.getElementById('clock');
    var elClockDate  = document.getElementById('clockDate');
    var logoPanel    = document.getElementById('logoPanel');
    var btnFull      = document.getElementById('btnFullscreen');

    var speed        = 40;      // px/detik, diatur dari halaman Pengaturan
    var refreshMs    = 10000;
    var offset       = 0;
    var loopHeight   = 0;
    var playing      = false;
    var lastFrame    = 0;
    var lastKey      = '';
    var lastData     = null;
    var pollTimer    = null;

    /* Penanda anti-loop untuk muat ulang otomatis saat aset (CSS/JS) diperbarui.
       Versi yang sudah pernah dimuat dicatat di URL (?v_aset=...), sehingga walau
       HTML-nya sendiri ter-cache, halaman tidak akan memuat ulang berulang-ulang. */
    var versiSudahDimuat = (function () {
        var m = /[?&]v_aset=([^&]*)/.exec(window.location.search);
        return m ? decodeURIComponent(m[1]) : '';
    })();

    /* ---------------------------- Render ---------------------------- */

    function buatKartu(item) {
        var kartu = document.createElement('div');
        kartu.className = 'dokter-card ' + (item.status === 'TUTUP' ? 'tutup' : 'buka');

        var nama = document.createElement('div');
        nama.className = 'dc-name';
        nama.textContent = item.dokter;
        kartu.appendChild(nama);

        var meta = document.createElement('div');
        meta.className = 'dc-meta';

        var jam = document.createElement('div');
        jam.className = 'dc-jam';
        jam.textContent = item.jam;

        var stat = document.createElement('div');
        stat.className = 'dc-status';
        stat.textContent = item.status;

        meta.appendChild(jam);
        meta.appendChild(stat);
        kartu.appendChild(meta);
        return kartu;
    }

    function buatGrup(grup) {
        var sec = document.createElement('section');
        sec.className = 'poli-group';

        var head = document.createElement('div');
        head.className = 'poli-head';

        var namaPoli = document.createElement('div');
        namaPoli.className = 'poli-nama';
        namaPoli.textContent = grup.poli;

        var jml = document.createElement('div');
        jml.className = 'poli-jml';
        jml.textContent = grup.items.length + ' dokter';

        head.appendChild(namaPoli);
        head.appendChild(jml);
        sec.appendChild(head);

        var body = document.createElement('div');
        body.className = 'poli-body';
        grup.items.forEach(function (it) { body.appendChild(buatKartu(it)); });
        sec.appendChild(body);
        return sec;
    }

    function buatKolom(data) {
        var copy = document.createElement('div');
        copy.className = 'page-copy';
        data.groups.forEach(function (g) { copy.appendChild(buatGrup(g)); });
        return copy;
    }

    function render(data) {
        speed     = Math.max(5, parseInt(data.settings.kecepatan_gulir, 10) || 40);
        refreshMs = Math.max(3, parseInt(data.settings.refresh_detik, 10) || 10) * 1000;

        elNamaRs.textContent = data.settings.nama_rs;
        elTanggal.textContent = data.tanggal_label;
        elJumlah.textContent = data.jumlah_dokter + ' jadwal';
        elTagPilihan.style.display = data.is_today ? 'none' : 'inline-block';
        document.title = 'Display Jadwal Dokter — ' + data.settings.nama_rs;

        updateLogo(data.settings.logo_url, data.settings.nama_rs);
        updateFooter(data.settings.footer_teks);

        track.innerHTML = '';
        if (!data.groups.length) {
            var kosong = document.createElement('div');
            kosong.className = 'empty-display';
            var b = document.createElement('strong');
            b.textContent = 'Belum ada jadwal dokter untuk ' + data.tanggal_label;
            var p = document.createElement('div');
            p.textContent = 'Silakan tambahkan jadwal melalui menu Input Jadwal.';
            kosong.appendChild(b);
            kosong.appendChild(p);
            track.appendChild(kosong);
            loopHeight = 0;
            playing = false;
            offset = 0;
            track.style.transform = 'translate3d(0,0,0)';
            return;
        }

        var copy = buatKolom(data);
        track.appendChild(copy);
        track.appendChild(copy.cloneNode(true));

        ukur();
    }

    function ukur() {
        var first = track.firstElementChild;
        if (!first) { loopHeight = 0; playing = false; return; }
        var h = first.getBoundingClientRect().height;
        loopHeight = h;
        playing = h > viewport.clientHeight && h > 0;
        if (loopHeight > 0) {
            offset = offset % loopHeight;
        } else {
            offset = 0;
        }
    }

    function updateLogo(url, namaRs) {
        var img = logoPanel.querySelector('img');
        var ph  = logoPanel.querySelector('.logo-placeholder');
        if (url) {
            if (!img) {
                if (ph) { logoPanel.removeChild(ph); }
                img = document.createElement('img');
                logoPanel.appendChild(img);
            }
            if (img.getAttribute('src') !== url) {
                img.setAttribute('src', url);
                img.setAttribute('alt', 'Logo ' + namaRs);
            }
        } else {
            if (img) { logoPanel.removeChild(img); }
            if (!ph) {
                ph = document.createElement('div');
                ph.className = 'logo-placeholder';
                ph.textContent = 'LOGO';
                logoPanel.appendChild(ph);
            }
        }
    }

    function updateFooter(teks) {
        var t = teks && teks.trim() !== '' ? teks : ' ';
        var spans = footerTrack.querySelectorAll('span');
        spans[0].textContent = t;
        spans[1].textContent = t;

        // Ukur lebar satu salinan untuk menentukan durasi gulir
        var lebarSatu = spans[0].getBoundingClientRect().width;
        if (!lebarSatu || lebarSatu <= viewport.clientWidth) {
            footerTrack.style.animation = 'none';
            footerTrack.style.justifyContent = 'center';
            footerTrack.style.width = '100%';
            footerTrack.style.paddingLeft = '26px';
            spans[1].style.display = 'none';
        } else {
            spans[1].style.display = '';
            footerTrack.style.width = 'max-content';
            footerTrack.style.justifyContent = 'flex-start';
            footerTrack.style.paddingLeft = '0';
            var durasi = Math.max(8, lebarSatu / Math.max(20, speed * 1.5));
            footerTrack.style.animation = 'marquee ' + durasi.toFixed(1) + 's linear infinite';
        }
    }

    /* ------------------------- Gulir otomatis ------------------------ */

    function frame(t) {
        if (!lastFrame) { lastFrame = t; }
        var dt = Math.min(0.15, (t - lastFrame) / 1000);
        lastFrame = t;

        if (playing && !document.hidden) {
            offset += speed * dt;
            if (loopHeight > 0 && offset >= loopHeight) { offset -= loopHeight; }
            track.style.transform = 'translate3d(0,' + (-offset).toFixed(2) + 'px,0)';
        }
        requestAnimationFrame(frame);
    }

    /* ----------------------------- Jam ------------------------------ */

    function tick() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        elClock.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        elClockDate.textContent = d.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        }) + ' • WITA';
    }

    /* ------------------------ Auto refresh data --------------------- */

    function kunci(data) {
        return JSON.stringify({ g: data.groups, s: data.settings, t: data.tanggal, j: data.jumlah_dokter });
    }

    function terapkan(data, paksa) {
        var k = kunci(data);
        if (!paksa && k === lastKey) { return; }
        lastKey = k;
        lastData = data;
        render(data);
    }

    function poll() {
        fetch('api.php?action=display_data&_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('bad payload'); }
                netWarn.style.display = 'none';

                /* Tampilan (CSS/JS) sudah diperbarui di server? Muat ulang sekali agar
                   layar TV yang lama terbuka ikut memakai tema terbaru tanpa disentuh manual. */
                var versiServer = data.asset_version ? String(data.asset_version) : '';
                if (versiServer !== '' && versiServer !== versiSudahDimuat &&
                    String(window.ASSET_VERSION || '') !== versiServer) {
                    versiSudahDimuat = versiServer;   // dicatat supaya tidak berulang
                    try {
                        var alamat = new URL(window.location.href);
                        alamat.searchParams.set('v_aset', versiServer);
                        window.location.replace(alamat.toString());
                    } catch (e) {
                        window.location.reload();
                    }
                    return;
                }

                terapkan(data, false);
                jadwalkan();
            })
            .catch(function () {
                netWarn.style.display = 'block';
                jadwalkan(5000);
            });
    }

    function jadwalkan(ms) {
        if (pollTimer) { clearTimeout(pollTimer); }
        pollTimer = setTimeout(poll, ms || refreshMs);
    }

    /* --------------------------- Fullscreen ------------------------- */

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            var el = document.documentElement;
            var req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (req) { req.call(el).catch(function () {}); }
        } else {
            document.exitFullscreen().catch(function () {});
        }
    }

    /* Tombol hanya ikon — label dijelaskan lewat tooltip & aria-label */
    function updateFullLabel() {
        var aktif = !!document.fullscreenElement;
        btnFull.setAttribute('title', aktif ? 'Keluar dari layar penuh' : 'Tampilkan layar penuh');
        btnFull.setAttribute('aria-label', btnFull.getAttribute('title'));
        btnFull.setAttribute('aria-pressed', aktif ? 'true' : 'false');
    }

    /* ---------------------------- Sembunyikan kursor ---------------- */

    var cursorTimer = null;
    function resetCursor() {
        document.body.classList.remove('hide-cursor');
        if (cursorTimer) { clearTimeout(cursorTimer); }
        cursorTimer = setTimeout(function () { document.body.classList.add('hide-cursor'); }, 5000);
    }

    /* ------------------------------ Init ---------------------------- */

    btnFull.addEventListener('click', toggleFullscreen);
    document.addEventListener('fullscreenchange', updateFullLabel);
    document.addEventListener('webkitfullscreenchange', updateFullLabel);
    document.addEventListener('mousemove', resetCursor);
    window.addEventListener('resize', function () {
        ukur();
        if (lastData) { updateFooter(lastData.settings.footer_teks); }
    });

    tick();
    setInterval(tick, 1000);
    resetCursor();

    var awal = window.INITIAL_DATA;
    if (awal && awal.ok) {
        terapkan(awal, true);
        var sisa = refreshMs - (Date.now() % refreshMs);
        jadwalkan(sisa > 1000 ? sisa : refreshMs);
    } else {
        netWarn.style.display = 'block';
        poll();
    }

    requestAnimationFrame(frame);
})();
