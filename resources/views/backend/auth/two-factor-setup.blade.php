@extends('backend.layout.app')

@section('title', 'Aktifkan 2FA')
@section('page_heading', 'Autentikasi Dua Faktor')
@section('page_subheading', 'Wajib aktif sebelum akun Anda bisa dipakai')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body p-8">

                    <div class="alert alert-primary mb-8">
                        Kata sandi yang bocor tidak cukup untuk mengambil alih akun yang punya 2FA.
                        Itu satu-satunya alasan ini diwajibkan.
                    </div>

                    <ol class="mb-8 ps-4">
                        <li class="mb-3">
                            Pasang aplikasi authenticator di HP Anda
                            (Google Authenticator, Authy, atau 1Password).
                        </li>
                        <li class="mb-3">Pindai QR di bawah, atau masukkan kunci manualnya.</li>
                        <li>Ketik kode 6 angka yang muncul untuk mengonfirmasi.</li>
                    </ol>

                    <div class="row g-6 mb-8">
                        <div class="col-md-5 text-center">
                            <div class="border rounded p-4 d-inline-block bg-white">
                                {!! $qrSvg !!}
                            </div>
                        </div>

                        <div class="col-md-7">
                            <div class="text-muted fs-8 text-uppercase fw-semibold mb-2">
                                Kunci manual
                            </div>

                            {{--
                                Kunci ditampilkan berkelompok empat karakter.

                                Sebagian aplikasi authenticator tidak bisa memindai QR
                                di layar tertentu, dan yang tersisa adalah mengetik 32
                                karakter dari layar. Tanpa pengelompokan, itu hampir
                                selalu salah.
                            --}}
                            <code class="d-block bg-light rounded p-4 fs-5 lh-lg" style="word-break: break-all">
                                {{ $secretDikelompokkan }}
                            </code>

                            <div class="text-muted fs-8 mt-3">
                                Jangan bagikan kunci ini ke siapa pun, termasuk ke tim Antaride.
                            </div>
                        </div>
                    </div>

                    <div class="separator mb-8"></div>

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            @foreach ($errors->all() as $pesan)
                                <div>{{ $pesan }}</div>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.two-factor.confirm') }}">
                        @csrf

                        <label class="form-label fw-semibold">Kode dari aplikasi authenticator</label>
                        <input type="text" name="code" class="form-control form-control-lg mb-2"
                            inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
                            placeholder="000000" />

                        <div class="form-text mb-6">
                            Kalau kodenya selalu ditolak, periksa jam di HP Anda — TOTP bergantung
                            pada waktu yang tepat.
                        </div>

                        <button type="submit" class="btn btn-primary">Aktifkan 2FA</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
