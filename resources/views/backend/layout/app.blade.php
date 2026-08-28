{{--
    Layout panel admin Antaride.

    Mengikuti Metronic 8 Demo 11: header tetap dengan menu horizontal, plus
    sidebar off-canvas. Strukturnya sengaja sama dengan startertempalt-copy
    supaya siapa pun yang pernah bekerja di template itu langsung mengenali
    tempatnya.

    Perbedaan yang disengaja dari template itu:

      - Tidak ada `$appSettings` dari database. Nama dan logo dibaca dari
        config, karena panel ini harus bisa dirender walaupun tabel pengaturan
        belum di-seed — dan halaman pertama yang dibuka orang saat memperbaiki
        database yang rusak adalah panel admin.

      - Tidak ada listener Force Logout berbasis Laravel Echo. Realtime di
        proyek ini lewat Centrifugo, dan panel admin tidak berlangganan channel
        apa pun kecuali live map.

      - Menu dibangun dari permission, bukan dari daftar tetap. Lihat menu.blade.php.
--}}
<!DOCTYPE html>
<html lang="id">

<head>
    <base href="{{ url('/') }}/" />
    <title>@yield('title', 'Panel') — {{ config('antaride.brand.name', 'Antaride') }}</title>

    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    {{--
        Panel admin TIDAK boleh diindeks mesin pencari.

        Halaman login panel yang muncul di hasil pencarian adalah undangan
        terbuka untuk mencoba kredensial. Ini bukan pengganti allowlist IP dan
        2FA, hanya menghilangkan satu cara paling mudah menemukannya.
    --}}
    <meta name="robots" content="noindex, nofollow, noarchive" />

    <link rel="shortcut icon" href="{{ asset('assets/media/logos/favicon.ico') }}" />

    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" />

    <link href="{{ asset('assets/plugins/custom/datatables/datatables.bundle.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />

    <style>
        :root {
            --bs-font-sans-serif: 'Plus Jakarta Sans', sans-serif;
            --bs-body-font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body,
        h1, h2, h3, h4, h5, h6,
        .h1, .h2, .h3, .h4, .h5, .h6 {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
        }

        /* Sidebar off-canvas, pola Demo 11. */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 104;
        }

        .sidebar-overlay.active { display: block; }

        #kt_app_sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 280px;
            z-index: 105;
            background: #fff;
            border-right: 1px solid var(--bs-gray-200);
            transform: translateX(-100%);
            transition: transform .3s ease;
            overflow-y: auto;
        }

        #kt_app_sidebar.active { transform: translateX(0); }

        [data-bs-theme="dark"] #kt_app_sidebar {
            background: #1e1e2d;
            border-right-color: rgba(255, 255, 255, .07);
        }

        /*
            Angka uang selalu tabular.

            Tanpa ini, kolom nominal di tabel tidak sejajar karena lebar setiap
            digit berbeda — dan kolom Rupiah yang tidak sejajar membuat
            perbandingan sekilas antar baris tidak mungkin, yang justru menjadi
            alasan utama tabelnya dibuka.
        */
        .money,
        td.money,
        .text-money {
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
            text-align: right;
            white-space: nowrap;
        }
    </style>

    {{-- Panel admin tidak boleh dimuat di dalam iframe milik orang lain. --}}
    <script>
        if (window.top !== window.self) {
            window.top.location.replace(window.self.location.href);
        }
    </script>

    @stack('stylesheets')
</head>

<body id="kt_body" class="header-fixed header-tablet-and-mobile-fixed toolbar-enabled">
    <script>
        var defaultThemeMode = "light";
        var themeMode;

        if (document.documentElement) {
            if (document.documentElement.hasAttribute("data-bs-theme-mode")) {
                themeMode = document.documentElement.getAttribute("data-bs-theme-mode");
            } else {
                themeMode = localStorage.getItem("data-bs-theme") ?? defaultThemeMode;
            }

            if (themeMode === "system") {
                themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
            }

            document.documentElement.setAttribute("data-bs-theme", themeMode);
        }
    </script>

    <div class="d-flex flex-column flex-root">
        <div class="page d-flex flex-row flex-column-fluid">
            <div class="wrapper d-flex flex-column flex-row-fluid" id="kt_wrapper">

                <div id="kt_header" class="header" data-kt-sticky="true" data-kt-sticky-name="header"
                    data-kt-sticky-offset="{default: '200px', lg: '300px'}">

                    <div class="container-xxl d-flex flex-grow-1 flex-stack">
                        <div class="d-flex align-items-center me-5">
                            <div class="d-lg-none btn btn-icon btn-active-color-primary w-30px h-30px ms-n2 me-3"
                                id="kt_app_sidebar_toggle">
                                <i class="ki-duotone ki-abstract-14 fs-2"><span class="path1"></span><span
                                        class="path2"></span></i>
                            </div>

                            <div class="d-lg-none btn btn-icon btn-active-color-primary w-30px h-30px me-3"
                                id="kt_header_menu_toggle">
                                <i class="ki-duotone ki-text-align-left fs-2"><span class="path1"></span><span
                                        class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                            </div>

                            <a href="{{ route('admin.dashboard') }}" class="d-flex align-items-center">
                                <span class="fs-2 fw-bolder text-primary">
                                    {{ config('antaride.brand.name', 'Antaride') }}
                                </span>
                                <span class="badge badge-light-secondary ms-2 fs-9">
                                    {{ strtoupper(app()->environment()) }}
                                </span>
                            </a>
                        </div>

                        @include('backend.layout.navbar')
                    </div>

                    <div class="separator"></div>

                    <div class="header-menu-container container-xxl d-flex flex-stack h-lg-75px w-100"
                        id="kt_header_nav">
                        @include('backend.layout.menu')
                    </div>
                </div>

                <div id="kt_content_container" class="d-flex flex-column-fluid align-items-start container-xxl">
                    @include('backend.layout.sidebar')

                    <div class="content flex-row-fluid" id="kt_content">
                        @includeWhen(
                            trim($__env->yieldContent('page_heading')) !== '',
                            'backend.layout.page-heading'
                        )

                        @include('backend.layout.kill-switch-banner')

                        @yield('content')
                    </div>
                </div>

                @include('backend.layout.footer')
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="kt_sidebar_overlay"></div>

    <script>
        var hostUrl = "{{ asset('assets/') }}";
    </script>
    <script src="{{ asset('assets/plugins/global/plugins.bundle.js') }}"></script>
    <script src="{{ asset('assets/js/scripts.bundle.js') }}"></script>
    <script src="{{ asset('assets/plugins/custom/datatables/datatables.bundle.js') }}"></script>
    <script src="{{ asset('assets/js/widgets.bundle.js') }}"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Sidebar off-canvas ---
            const sidebar = document.getElementById('kt_app_sidebar');
            const overlay = document.getElementById('kt_sidebar_overlay');

            document.querySelectorAll('#kt_app_sidebar_toggle, #kt_sidebar_toggle_desktop')
                .forEach(btn => btn.addEventListener('click', function () {
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                }));

            if (overlay) {
                overlay.addEventListener('click', function () {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                });
            }

            // --- Pemberitahuan ---
            if (window.toastr) {
                toastr.options = {
                    closeButton: true,
                    progressBar: true,
                    positionClass: 'toastr-top-right',
                    timeOut: 5000,
                };

                @if (session('success')) toastr.success(@json(session('success'))); @endif
                @if (session('error')) toastr.error(@json(session('error'))); @endif
                @if (session('warning')) toastr.warning(@json(session('warning'))); @endif
                @if (session('info')) toastr.info(@json(session('info'))); @endif
            }

            /*
                Konfirmasi untuk tindakan yang sulit dibatalkan.

                Dialognya WAJIB menyebutkan dampaknya dengan angka, bukan
                "Anda yakin?". Blueprint admin bagian 12: konfirmasi yang tidak
                menyebutkan apa yang akan terjadi akan ditekan tanpa dibaca
                setelah minggu kedua, dan sejak saat itu dia tidak melindungi
                apa pun.

                Teksnya diambil dari atribut data-konfirmasi pada elemennya,
                jadi setiap tombol menyebut dampaknya sendiri.
            */
            document.querySelectorAll('[data-konfirmasi]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();

                    const pesan = el.getAttribute('data-konfirmasi');
                    const form = el.closest('form');

                    Swal.fire({
                        title: el.getAttribute('data-konfirmasi-judul') ?? 'Konfirmasi tindakan',
                        html: pesan,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: el.getAttribute('data-konfirmasi-ya') ?? 'Ya, lanjutkan',
                        cancelButtonText: 'Batal',
                        customClass: {
                            confirmButton: 'btn btn-danger',
                            cancelButton: 'btn btn-light',
                        },
                        buttonsStyling: false,
                    }).then(function (hasil) {
                        if (!hasil.isConfirmed) {
                            return;
                        }

                        if (form) {
                            form.submit();
                            return;
                        }

                        window.location.href = el.getAttribute('href');
                    });
                });
            });
        });
    </script>

    @stack('scripts')
</body>

</html>
