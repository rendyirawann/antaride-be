@extends('backend.layout.app')

@section('title', 'Tarif')
@section('page_heading', 'Aturan Tarif')
@section('page_subheading', 'Tarif tidak pernah ditimpa — yang baru menggantikan, yang lama tetap tersimpan')

@section('page_actions')
    <a href="{{ route('admin.pricing.simulator') }}" class="btn btn-sm btn-light-primary">Simulator</a>
    @can('pricing.propose')
        <a href="{{ route('admin.pricing.create') }}" class="btn btn-sm btn-primary">Tarif baru</a>
    @endcan
@endsection

@section('content')
    <div class="alert alert-light-primary mb-6">
        <div class="fw-bold">Kenapa daftar ini bisa panjang</div>
        <div class="fs-7 mt-1">
            Mengubah tarif berarti membuat baris baru, bukan mengedit yang lama. Kalau ada
            sengketa ongkos tiga bulan lalu, pertanyaannya adalah "berapa tarif yang berlaku
            SAAT ITU" — dan tarif yang ditimpa membuat pertanyaan itu tidak terjawab
            selamanya. Setiap order menyimpan tarif mana yang dipakainya.
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Layanan</th>
                            <th>Zona</th>
                            <th class="text-end">Dasar</th>
                            <th class="text-end">Per km</th>
                            <th class="text-end">Per menit</th>
                            <th class="text-end">Minimum</th>
                            <th class="text-end">Biaya app</th>
                            <th class="text-end">Komisi</th>
                            <th>Berlaku</th>
                            <th class="pe-6"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($aturan as $a)
                            <tr class="{{ $a->is_active ? '' : 'text-muted bg-light' }}">
                                <td class="ps-6 fw-bold">{{ $a->serviceType->name }}</td>

                                <td>
                                    @if ($a->zone)
                                        {{ $a->zone->name }}
                                    @else
                                        <span class="badge badge-light-secondary fs-9">semua zona</span>
                                    @endif
                                </td>

                                <td class="money">{{ number_format((int) $a->base_fare, 0, ',', '.') }}</td>
                                <td class="money">{{ number_format((int) $a->per_km, 0, ',', '.') }}</td>
                                <td class="money">{{ number_format((int) $a->per_minute, 0, ',', '.') }}</td>
                                <td class="money">{{ number_format((int) $a->minimum_fare, 0, ',', '.') }}</td>
                                <td class="money">{{ number_format((int) $a->platform_fee, 0, ',', '.') }}</td>
                                <td class="text-end">{{ $a->commission_percent }}%</td>

                                <td class="fs-8">
                                    <div>
                                        dari
                                        {{ \App\Domain\Shared\Support\BusinessClock::at($a->effective_from)->format('d/m/y H:i') }}
                                    </div>
                                    @if ($a->effective_until)
                                        <div class="text-muted">
                                            sampai
                                            {{ \App\Domain\Shared\Support\BusinessClock::at($a->effective_until)->format('d/m/y H:i') }}
                                        </div>
                                    @else
                                        <div class="text-success fw-semibold">tanpa batas akhir</div>
                                    @endif
                                </td>

                                <td class="pe-6 text-end">
                                    @if ($a->is_active)
                                        <span class="badge badge-light-success me-2">aktif</span>

                                        @can('pricing.approve')
                                            <form method="POST"
                                                action="{{ route('admin.pricing.deactivate', $a->id) }}"
                                                class="d-inline">
                                                @csrf
                                                <button type="button" class="btn btn-sm btn-light-danger"
                                                    data-konfirmasi-judul="Nonaktifkan tarif ini?"
                                                    data-konfirmasi="Tarifnya <b>tidak dihapus</b> — hanya berhenti dipakai untuk order baru.<br><br>Kalau tidak ada tarif aktif lain untuk kombinasi layanan dan zona ini, <b>estimasi harga akan gagal</b> untuk seluruh kombinasi itu."
                                                    data-konfirmasi-ya="Ya, nonaktifkan">
                                                    Nonaktifkan
                                                </button>
                                            </form>
                                        @endcan
                                    @else
                                        <span class="badge badge-light-secondary">nonaktif</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-15">
                                    <div class="fs-5">Belum ada aturan tarif.</div>
                                    <div class="fs-7 mt-1">
                                        Tanpa tarif aktif, estimasi harga akan gagal untuk seluruh layanan.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($aturan->hasPages())
            <div class="card-footer">{{ $aturan->links() }}</div>
        @endif
    </div>
@endsection
