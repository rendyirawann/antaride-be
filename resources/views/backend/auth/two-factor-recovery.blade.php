@extends('backend.layout.app')

@section('title', 'Kode Pemulihan')
@section('page_heading', 'Kode Pemulihan')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body p-8">

                    {{--
                        Peringatan bahwa ini SATU-SATUNYA kesempatan.

                        Versi yang tersimpan sudah di-hash dan tidak bisa dibaca lagi
                        oleh siapa pun, termasuk superadmin. Halaman ini harus
                        mengatakannya dengan jelas — kalau tidak, orang akan
                        menutupnya dan menganggap bisa membukanya lagi nanti.
                    --}}
                    <div class="alert alert-danger mb-8">
                        <div class="fw-bolder fs-5 mb-1">Simpan sekarang. Tidak bisa dilihat lagi.</div>
                        <div class="fs-7">
                            Kode ini disimpan dalam bentuk ter-hash. Setelah Anda menutup halaman
                            ini, tidak ada seorang pun — termasuk superadmin — yang bisa
                            menampilkannya kembali.
                        </div>
                    </div>

                    <div class="text-muted fs-7 mb-4">
                        Dipakai kalau HP Anda hilang atau aplikasi authenticator-nya terhapus.
                        Setiap kode hanya berlaku satu kali.
                    </div>

                    <div class="bg-light rounded p-6 mb-6">
                        <div class="row g-3">
                            @foreach ($kodePemulihan as $kode)
                                <div class="col-6">
                                    <code class="fs-5 fw-bold">{{ $kode }}</code>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-light-primary" onclick="window.print()">
                            Cetak
                        </button>

                        <a href="{{ route('admin.dashboard') }}" class="btn btn-primary"
                            data-konfirmasi-judul="Sudah disimpan?"
                            data-konfirmasi="Kode ini tidak akan bisa ditampilkan lagi setelah Anda meninggalkan halaman ini."
                            data-konfirmasi-ya="Sudah, lanjutkan">
                            Sudah saya simpan
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
