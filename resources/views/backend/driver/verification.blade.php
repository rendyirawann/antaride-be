@extends('backend.layout.app')

@section('title', 'Antrean Verifikasi')
@section('page_heading', 'Antrean Verifikasi Dokumen')
@section('page_subheading', 'Terlama menunggu dulu — driver yang menunggu tiga hari sudah kehilangan tiga hari pendapatan')

@section('content')
    <div class="row g-5 mb-6">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Menunggu review</div>
                    <div class="fs-2 fw-bolder text-warning">{{ $statistik['menunggu'] }}</div>

                    @if ($statistik['tertua'])
                        <div class="text-muted fs-8 mt-1">
                            Tertua sudah menunggu
                            <span class="fw-bold text-danger">
                                {{ $statistik['tertua']->diffForHumans(null, true) }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Pernah ditolak</div>
                    <div class="fs-2 fw-bolder text-danger">{{ $statistik['ditolak'] }}</div>
                    <div class="text-muted fs-8 mt-1">menunggu driver mengunggah ulang</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Disetujui tapi kadaluarsa</div>
                    <div class="fs-2 fw-bolder {{ $statistik['kadaluarsa'] > 0 ? 'text-danger' : 'text-gray-900' }}">
                        {{ $statistik['kadaluarsa'] }}
                    </div>
                    {{--
                        Kelompok ini yang paling mudah terlupakan: dokumennya pernah
                        benar, tidak ada yang menolaknya, dan drivernya tidak bisa
                        online tanpa tahu kenapa.
                    --}}
                    <div class="text-muted fs-8 mt-1">driver tidak bisa online</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Menunggu review</h3>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Menunggu</th>
                            <th>Driver</th>
                            <th>Jenis dokumen</th>
                            <th>Kendaraan</th>
                            <th class="pe-6"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dokumen as $dok)
                            <tr>
                                <td class="ps-6">
                                    @php
                                        $lama = $dok->created_at->diffInHours(now());
                                    @endphp
                                    {{--
                                        Lama menunggu diberi warna, bukan hanya angka.

                                        Antrean verifikasi yang menumpuk berarti driver
                                        baru tidak bisa mulai bekerja, dan itu langsung
                                        memotong pasokan. Warnanya yang membuat itu
                                        terlihat tanpa membaca angkanya.
                                    --}}
                                    <span
                                        class="badge badge-light-{{ $lama > 48 ? 'danger' : ($lama > 12 ? 'warning' : 'secondary') }}">
                                        {{ $dok->created_at->diffForHumans(null, true) }}
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-bold">{{ $dok->driver->full_name }}</div>
                                    <div class="text-muted fs-9">
                                        {{ $dok->driver->status->label() }}
                                    </div>
                                </td>

                                <td><span class="badge badge-light-primary">{{ $dok->label() }}</span></td>

                                <td class="text-muted fs-8">
                                    @php $kendaraan = $dok->driver->vehicles->first(); @endphp
                                    @if ($kendaraan)
                                        {{ $kendaraan->plate_number }}
                                        <br>{{ $kendaraan->brand }} {{ $kendaraan->model }}
                                    @else
                                        <span class="text-danger">belum ada kendaraan</span>
                                    @endif
                                </td>

                                <td class="pe-6 text-end">
                                    <a href="{{ route('admin.drivers.verify', $dok->driver->uuid) }}"
                                        class="btn btn-sm btn-primary">Verifikasi</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-15">
                                    <div class="fs-5">Antrean kosong.</div>
                                    <div class="fs-7 mt-1">Tidak ada dokumen yang menunggu review.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($dokumen->hasPages())
            <div class="card-footer">{{ $dokumen->links() }}</div>
        @endif
    </div>
@endsection
