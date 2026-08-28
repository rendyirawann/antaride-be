@extends('backend.layout.app')

@section('title', 'Merchant')
@section('page_heading', 'Merchant')

@section('content')
    <div class="alert alert-light-primary mb-6 fs-7">
        {{--
            Kenapa halaman ini baru berupa daftar dijelaskan, bukan dibiarkan
            terlihat setengah jadi.

            Membangun pengelolaan menu dan komisi sekarang berarti menebak alur
            kerja yang belum ada — dan alur kerja yang ditebak hampir selalu
            harus dibongkar setelah vertikal-nya benar-benar dipakai.
        --}}
        Pengelolaan menu dan komisi merchant menunggu vertikal <span class="fw-bold">AntarFood</span>
        benar-benar dipakai. Membangunnya sekarang berarti menebak alur kerja yang belum ada.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Nama</th>
                            <th>Kategori</th>
                            <th>Status</th>
                            <th class="text-end">Item menu</th>
                            <th class="text-end">Komisi</th>
                            <th class="pe-6">Buka</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($merchant as $m)
                            <tr>
                                <td class="ps-6">
                                    <div class="fw-bold">{{ $m->name }}</div>
                                    <div class="text-muted fs-9">{{ $m->address ?? '—' }}</div>
                                </td>

                                <td class="fs-7">{{ $m->category ?? '—' }}</td>

                                <td>
                                    @php
                                        $warna = match ($m->status) {
                                            'active' => 'success',
                                            'pending_review' => 'warning',
                                            'suspended', 'rejected' => 'danger',
                                            default => 'secondary',
                                        };
                                    @endphp
                                    <span class="badge badge-light-{{ $warna }}">{{ $m->status }}</span>
                                </td>

                                <td class="text-end">
                                    @if ($m->menu_items_count === 0)
                                        {{--
                                            Merchant tanpa item menu ditandai.

                                            Merchant aktif yang menunya kosong tidak akan
                                            pernah mendapat order, dan tidak ada error yang
                                            menjelaskannya. Angka nol di sini yang membuat
                                            itu terlihat.
                                        --}}
                                        <span class="badge badge-light-danger">kosong</span>
                                    @else
                                        {{ number_format($m->menu_items_count, 0, ',', '.') }}
                                    @endif
                                </td>

                                <td class="text-end">
                                    {{ $m->commission_percent !== null ? $m->commission_percent . '%' : '—' }}
                                </td>

                                <td class="pe-6">
                                    @if ($m->is_open ?? false)
                                        <span class="badge badge-light-success">buka</span>
                                    @else
                                        <span class="badge badge-light-secondary">tutup</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-15">
                                    Belum ada merchant terdaftar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($merchant->hasPages())
            <div class="card-footer">{{ $merchant->links() }}</div>
        @endif
    </div>
@endsection
