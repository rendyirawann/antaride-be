{{--
    Halaman masuk panel admin.

    Layout tersendiri, tidak memakai backend.layout.app — layout itu memuat
    sidebar dan menu yang menuntut sesi admin, dan halaman ini justru yang
    dibuka sebelum sesi ada.
--}}
<!DOCTYPE html>
<html lang="id">

<head>
    <base href="{{ url('/') }}/" />
    <title>Masuk — {{ config('antaride.brand.name', 'Antaride') }}</title>

    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow, noarchive" />

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
                    <div class="text-muted fw-semibold fs-6 mt-1">Panel Backoffice</div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-10 p-lg-12">

                        {{--
                            Peringatan environment.

                            Panel produksi dan panel staging terlihat identik, dan
                            staf yang membuka dua tab akan salah menekan tombol di
                            tab yang salah. Yang paling mahal: mengubah tarif di
                            produksi sambil mengira sedang di staging.
                        --}}
                        @production
                        @else
                            <div class="alert alert-warning d-flex align-items-center mb-8">
                                <span class="fw-bold">{{ strtoupper(app()->environment()) }}</span>
                                <span class="ms-2">— ini bukan produksi.</span>
                            </div>
                        @endproduction

                        @if ($errors->any())
                            <div class="alert alert-danger mb-8">
                                @foreach ($errors->all() as $pesan)
                                    <div>{{ $pesan }}</div>
                                @endforeach
                            </div>
                        @endif

                        @if (session('success'))
                            <div class="alert alert-success mb-8">{{ session('success') }}</div>
                        @endif

                        <form method="POST" action="{{ route('admin.login.attempt') }}" novalidate>
                            @csrf

                            <div class="fv-row mb-8">
                                <label class="form-label fw-semibold text-gray-900 fs-6" for="email">Email</label>
                                <input id="email" type="email" name="email" class="form-control form-control-lg"
                                    value="{{ old('email') }}" autocomplete="username" autofocus required />
                            </div>

                            <div class="fv-row mb-8">
                                <label class="form-label fw-semibold text-gray-900 fs-6" for="password">Kata
                                    Sandi</label>
                                <input id="password" type="password" name="password" class="form-control form-control-lg"
                                    autocomplete="current-password" required />
                            </div>

                            <button type="submit" class="btn btn-primary w-100 mb-5">Masuk</button>

                            <div class="text-center">
                                <a href="{{ route('admin.password.request') }}" class="link-primary fs-7">
                                    Lupa kata sandi?
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                {{--
                    Kredensial pengembangan ditampilkan HANYA di luar produksi.

                    Ini menghilangkan satu hambatan yang selalu muncul saat orang
                    baru menyiapkan proyek: panelnya sudah jalan tapi tidak ada
                    yang tahu email apa yang di-seed, dan jawabannya harus dicari
                    di file seeder.
                --}}
                @production
                @else
                    <div class="text-center mt-8 text-muted fs-8">
                        Akun seeder: <code>superadmin@antaride.test</code>
                        &nbsp;/&nbsp;
                        <code>password</code>
                    </div>
                @endproduction
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/plugins/global/plugins.bundle.js') }}"></script>
    <script src="{{ asset('assets/js/scripts.bundle.js') }}"></script>
</body>

</html>
