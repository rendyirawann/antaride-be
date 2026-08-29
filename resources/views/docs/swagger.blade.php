{{--
    ============================================================================
     SWAGGER UI — DAN SATU-SATUNYA CARA HALAMAN INI PUTIH POLOS
    ============================================================================
     Swagger UI menggambar SELURUH halaman dari JavaScript. Kalau skripnya gagal
     dimuat atau melempar sebelum sempat menggambar, yang tersisa adalah
     `<div id="swagger-ui">` yang kosong — halaman putih tanpa satu pun pesan.

     Orang yang membukanya menyimpulkan server mati, dan tidak ada apa pun di
     layar yang menunjuk ke penyebabnya.

     Tiga penjagaan di berkas ini, dan ketiganya untuk kegagalan yang berbeda:

       1. ASET DILAYANI SENDIRI, BUKAN DARI CDN.
          CDN adalah penyebab paling umum: server tanpa akses internet keluar,
          jaringan kantor yang memblokirnya, atau CDN-nya sendiri yang sedang
          mati. Berkasnya ada di `public/vendor/swagger-ui/` dan ikut di repo.

       2. PESAN CADANGAN YANG SUDAH TERGAMBAR DI HTML.
          Terlihat SEBELUM JavaScript berjalan, dan disembunyikan Swagger UI
          begitu dia berhasil menggambar. Jadi kegagalan apa pun meninggalkan
          pesan yang bisa dibaca, bukan layar putih.

       3. PENANGKAP GALAT JAVASCRIPT.
          `window.onerror` dan `onFailure` menampilkan pesan aslinya di layar,
          bukan hanya di console — karena yang membuka halaman ini di HP tidak
          punya console.
    ============================================================================
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Dokumentasi API tidak boleh masuk hasil pencarian. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>Dokumentasi API — {{ config('app.name') }}</title>

    <link rel="icon" type="image/png" sizes="32x32"
          href="{{ asset('vendor/swagger-ui/favicon-32x32.png') }}">

    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">

    <style>
        body { margin: 0; background: #fafafa; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }

        .antaride-bar {
            background: #0E9F6E;
            color: #fff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .antaride-bar strong { font-size: 15px; }
        .antaride-bar span {
            font-size: 12px;
            opacity: .9;
            background: rgba(255,255,255,.18);
            padding: 2px 8px;
            border-radius: 999px;
        }

        /* Pesan cadangan. Terlihat sampai Swagger UI berhasil menggambar. */
        #antaride-gagal {
            max-width: 720px;
            margin: 48px auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #E02424;
            border-radius: 8px;
            line-height: 1.6;
            color: #1A2233;
        }
        #antaride-gagal h1 { font-size: 17px; margin: 0 0 12px; color: #E02424; }
        #antaride-gagal code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
        }
        #antaride-gagal pre {
            background: #f3f4f6;
            padding: 12px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        #antaride-gagal ol { padding-left: 20px; }
        #antaride-gagal li { margin-bottom: 6px; }
    </style>
</head>
<body>

<div class="antaride-bar">
    <strong>{{ config('app.name') }} — Dokumentasi API</strong>
    <span>{{ config('app.env') }}</span>
    <span>{{ url('/api/v1') }}</span>
</div>

{{--
    Pesan ini SUDAH ADA di HTML sejak awal, bukan ditambahkan JavaScript.
    Itu intinya: kalau JavaScript-nya sendiri yang gagal, pesan ini tetap
    tampil — dan tidak ada keadaan yang menghasilkan halaman putih.
--}}
<div id="antaride-gagal">
    <h1>Dokumentasi belum tergambar</h1>

    <p>
        Kalau pesan ini masih terlihat setelah beberapa detik, Swagger UI gagal
        memuat. Pesan ini sengaja ditampilkan alih-alih halaman kosong.
    </p>

    <p><strong>Yang paling sering menyebabkannya:</strong></p>

    <ol>
        <li>
            Berkas spesifikasi belum dibuat. Jalankan di server:
            <br><code>php artisan scramble:export --path=docs/openapi/openapi.json</code>
        </li>
        <li>
            Aset Swagger UI tidak terbaca web server. Periksa bahwa
            <code>public/vendor/swagger-ui/</code> ikut ter-deploy.
        </li>
        <li>
            Cache config lama. Jalankan <code>php artisan config:cache</code>
            lalu muat ulang.
        </li>
    </ol>

    <p style="margin-bottom:6px"><strong>Detail teknis:</strong></p>
    <pre id="antaride-detail">Menunggu Swagger UI…</pre>

    <p>
        Spesifikasi mentahnya bisa dibuka langsung di
        <a href="{{ route('docs.spec') }}">{{ route('docs.spec') }}</a>.
    </p>
</div>

<div id="swagger-ui"></div>

<script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
<script src="{{ asset('vendor/swagger-ui/swagger-ui-standalone-preset.js') }}"></script>

<script>
    (function () {
        var kotak = document.getElementById('antaride-gagal');
        var detail = document.getElementById('antaride-detail');

        function gagal(pesan) {
            if (detail) { detail.textContent = String(pesan); }
        }

        // Galat JavaScript apa pun ditampilkan DI LAYAR, bukan hanya di console.
        // Yang membuka halaman ini di HP tidak punya console.
        window.addEventListener('error', function (e) {
            gagal('Galat JavaScript: ' + (e.message || 'tidak diketahui'));
        });

        if (typeof SwaggerUIBundle === 'undefined') {
            gagal(
                'Berkas swagger-ui-bundle.js tidak termuat.\n\n' +
                'Diminta dari: {{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}\n\n' +
                'Periksa apakah direktori public/vendor/swagger-ui/ ada di server.'
            );

            return;
        }

        gagal('Memuat spesifikasi dari {{ route('docs.spec') }} …');

        SwaggerUIBundle({
            // `route()`, bukan path harfiah: ini yang membawa subfolder saat
            // aplikasi di-deploy di /antaride-be. Path harfiah "/api/documentation
            // /openapi.json" akan menunjuk ke luar subfolder dan menghasilkan 404.
            url: @json(route('docs.spec')),

            dom_id: '#swagger-ui',
            deepLinking: true,
            docExpansion: 'list',
            filter: true,
            persistAuthorization: true,
            tryItOutEnabled: true,

            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset,
            ],
            plugins: [SwaggerUIBundle.plugins.DownloadUrl],
            layout: 'StandaloneLayout',

            // Berhasil: pesan cadangan disingkirkan.
            onComplete: function () {
                if (kotak) { kotak.style.display = 'none'; }
            },

            // Gagal memuat spesifikasi: pesannya ditampilkan apa adanya. Tanpa
            // ini, Swagger UI hanya menuliskannya ke console dan meninggalkan
            // halaman kosong.
            onFailure: function (e) {
                gagal(
                    'Spesifikasi gagal dimuat.\n\n' +
                    'URL   : {{ route('docs.spec') }}\n' +
                    'Galat : ' + (e && e.message ? e.message : JSON.stringify(e))
                );
            },
        });
    })();
</script>

</body>
</html>
