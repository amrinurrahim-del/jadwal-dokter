/* ==========================================================================
   Helper halaman admin: dropdown dokter bertingkat + kontrol lain
   ========================================================================== */
(function () {
    'use strict';

    /* Isi dropdown nama dokter berdasarkan poli yang dipilih */
    function isiDokter(poliId, tuj, terpilih) {
        var data = window.POLI_DOKTER || {};
        tuj.innerHTML = '';
        var grup = data[String(poliId)];
        if (!grup || !grup.dokter || !grup.dokter.length) {
            var opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = poliId ? '— belum ada dokter pada poli ini —' : '— pilih poli dahulu —';
            tuj.appendChild(opt0);
            return;
        }
        var optAwal = document.createElement('option');
        optAwal.value = '';
        optAwal.textContent = '— pilih dokter —';
        tuj.appendChild(optAwal);

        grup.dokter.forEach(function (d) {
            var o = document.createElement('option');
            o.value = String(d.id);
            o.textContent = d.nama;
            if (terpilih && parseInt(terpilih, 10) === d.id) { o.selected = true; }
            tuj.appendChild(o);
        });
    }

    function pasangCascading(poliSel, dokterSel, idxData, terpilih) {
        if (!poliSel || !dokterSel) { return; }
        var dataLama = window.POLI_DOKTER;
        if (idxData) { window.POLI_DOKTER = idxData; }
        function refresh(nilaiTerpilih) {
            isiDokter(poliSel.value, dokterSel, nilaiTerpilih);
        }
        poliSel.addEventListener('change', function () { refresh(null); });
        refresh(terpilih);
        window.POLI_DOKTER = dataLama;
    }

    /* Nilai slider ke teks output */
    function pasangRange() {
        document.querySelectorAll('input[type="range"][data-output]').forEach(function (r) {
            var out = document.getElementById(r.getAttribute('data-output'));
            function update() {
                if (!out) { return; }
                var satuan = r.getAttribute('data-satuan') || '';
                out.textContent = r.value + satuan;
            }
            r.addEventListener('input', update);
            update();
        });
    }

    /* Form dengan atribut data-autosubmit dikirim otomatis saat nilainya berubah */
    function pasangAutosubmit() {
        document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.form) { el.form.submit(); }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        pasangRange();
        pasangAutosubmit();
    });

    if (document.readyState !== 'loading') {
        pasangRange();
        pasangAutosubmit();
    }

    /* Inisialisasi otomatis untuk halaman Input Jadwal */
    pasangCascading(
        document.getElementById('poli_id'),
        document.getElementById('dokter_id'),
        null,
        window.DOKTER_TERPILIH || null
    );

    /* Dipakai halaman lain (Template Mingguan, Master Dokter) */
    window.pasangCascading = pasangCascading;
    window.isiDokter = isiDokter;
})();
