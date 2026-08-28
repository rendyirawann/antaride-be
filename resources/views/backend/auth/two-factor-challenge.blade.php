{{--
    Tantangan 2FA saat masuk.

    Layout tersendiri, bukan backend.layout.app: layout itu memuat menu dan
    sidebar, dan halaman ini justru yang menghalangi akses ke keduanya.
    Menampilkan menu di sini berarti menampilkan tautan yang semuanya akan
    mengarahkan balik ke halaman ini.
--}}
<!DOCTYPE html>
<html lang="id">

<head>
    <base href="{{ url('/') }}/" />
    <title>Verifikasi 2FA — {{ config('antaride.brand.name', 'Antaride') }}</title>

    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />

    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" />
    <link href="{{ asset('assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            background-color: #f5f8fa;
        }
    </style>
</head>

<body class="app-blank">
    <div class="d-flex flex-column flex-root" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-column-fluid flex-center p-10">
            <div class="w-100 mw-450px">

                <div class="text-center mb-8">
                    <div class="fs-1 fw-bolder text-primary">
                        {{ config('antaride.brand.name', 'Antaride') }}
                    </div>
                    <div class="text-muted fw-semibold fs-6 mt-1">Verifikasi dua faktor</div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-10">

                        @if ($errors->any())
                            <div class="alert alert-danger mb-6">
                                @foreach ($errors->all() as $pesan)
                                    <div>{{ $pesan }}</div>
                                @endforeach
                            </div>
                        @endif

                        @if (session('warning'))
                            <div class="alert alert-warning mb-6">{{ session('warning') }}</div>
                        @endif

                        <form method="POST" action="{{ route('admin.two-factor.verify') }}">
                            @csrf

                            <label class="form-label fw-semibold fs-6">
                                Kode dari aplikasi authenticator
                            </label>

                            {{--
                                Field ini menerima DUA bentuk: kode TOTP 6 angka, atau
                                kode pemulihan.

                                Karena itu `inputmode` bukan numeric dan tidak ada
                                maxlength 6 — kode pemulihan berisi huruf dan tanda
                                hubung, dan membatasi input akan menolaknya justru
                                saat orangnya paling tidak punya jalan lain.
                            --}}
                            <input type="text" name="code" class="form-control form-control-lg mb-3"
                                autocomplete="one-time-code" required autofocus placeholder="000000" />

                            <div class="form-text mb-6">
                                HP hilang? Masukkan salah satu kode pemulihan Anda di kolom yang sama.
                            </div>

                            <button type="submit" class="btn btn-primary w-100 mb-5">Verifikasi</button>
                        </form>

                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-link text-muted fs-7 w-100">
                                Keluar dan masuk dengan akun lain
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/plugins/global/plugins.bundle.js') }}"></script>
    <script src="{{ asset('assets/js/scripts.bundle.js') }}"></script>
</body>

</html>
