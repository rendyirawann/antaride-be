@extends('backend.layout.app')

@section('title', 'Reset Kata Sandi')
@section('page_heading', 'Reset Kata Sandi')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body p-8">
                    {{--
                        Reset kata sandi mandiri belum diimplementasikan, dan itu
                        keputusan sadar untuk Fase 1.

                        Alasannya: reset lewat email adalah jalur pengambilalihan akun
                        admin yang paling sering dipakai, dan mengamankannya dengan
                        benar menuntut lebih dari sekadar tautan bertanda tangan —
                        perlu pemberitahuan ke alamat lama, jeda sebelum berlaku, dan
                        pembatalan seluruh sesi lain.

                        Sampai itu dibangun, jalurnya lewat superadmin. Delapan akun
                        staf bukan jumlah yang membuat itu memberatkan.
                    --}}
                    <div class="alert alert-info">
                        Reset kata sandi mandiri belum tersedia.
                    </div>

                    <p class="text-gray-700">
                        Hubungi superadmin untuk menyetel ulang kata sandi Anda. Permintaan itu
                        dicatat di audit log, dan sesi Anda yang sedang berjalan akan diakhiri.
                    </p>

                    <a href="{{ route('admin.login') }}" class="btn btn-light-primary">
                        Kembali ke halaman masuk
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
