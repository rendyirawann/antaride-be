@extends('backend.layout.app')

@section('title', 'Zona')
@section('page_heading', 'Zona Operasional')
@section('page_subheading', 'Zona menentukan tarif dan surge. Titik di luar semua zona berarti di luar area layanan')

@section('content')
    <div class="alert alert-light-primary mb-6">
        <div class="fw-bold">Zona yang lebih spesifik menang</div>
        <div class="fs-7 mt-1">
            Satu titik bisa masuk ke dua zona sekaligus — zona bandara di dalam zona kota,
            misalnya. Yang menentukan mana yang dipakai adalah kolom prioritas: angka lebih
            besar menang.
            <br><br>
            Titik yang tepat di GARIS BATAS dianggap masuk. Itu keputusan yang disengaja,
            ditegakkan dengan <code>ST_Covers</code> alih-alih <code>ST_Contains</code> —
            penumpang yang berdiri di tepi jalan pembatas tidak boleh ditolak.
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Prioritas</th>
                            <th>Nama</th>
                            <th>Kota</th>
                            <th>Pusat</th>
                            <th>Kotak batas</th>
                            <th class="pe-6">Keadaan</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($zona as $z)
                            <tr class="{{ $z->is_active ? '' : 'text-muted bg-light' }}">
                                <td class="ps-6">
                                    <span class="badge badge-light-dark">{{ $z->priority }}</span>
                                </td>

                                <td class="fw-bold">{{ $z->name }}</td>
                                <td>{{ $z->city }}</td>

                                <td class="fs-8 text-muted">
                                    {{ $z->center_lat }}, {{ $z->center_lng }}
                                </td>

                                <td class="fs-9 text-muted">
                                    lat {{ $z->min_lat }} &hellip; {{ $z->max_lat }}
                                    <br>
                                    lng {{ $z->min_lng }} &hellip; {{ $z->max_lng }}
                                </td>

                                <td class="pe-6">
                                    @if ($z->is_active)
                                        <span class="badge badge-light-success">aktif</span>
                                    @else
                                        <span class="badge badge-light-secondary">nonaktif</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-15">
                                    <div class="fs-5">Belum ada zona.</div>
                                    <div class="fs-7 mt-1">
                                        Tanpa zona aktif, setiap permintaan estimasi harga akan
                                        ditolak dengan "di luar area layanan".
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Mengubah zona</h3>
        </div>
        <div class="card-body">
            <div class="text-gray-700">
                <p>
                    Poligon zona belum bisa diedit dari panel — menggambar poligon di peta
                    menuntut editor yang belum dibangun, dan mengetik koordinat GeoJSON
                    langsung di textarea adalah cara yang hampir pasti menghasilkan zona
                    yang bentuknya salah tanpa ada yang menyadarinya.
                </p>
                <p class="mb-0">
                    Untuk sekarang, zona diubah lewat seeder atau migration. Geometry PostGIS
                    diisi otomatis dari GeoJSON-nya oleh trigger database, jadi jalur mana pun
                    yang menulis <code>zones</code> — termasuk psql langsung — tetap
                    menghasilkan geometry yang konsisten.
                </p>
            </div>
        </div>
    </div>
@endsection
