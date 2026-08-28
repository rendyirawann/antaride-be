@extends('backend.layout.app')

@section('title', 'Driver')
@section('page_heading', 'Driver')

@section('page_actions')
    @can('drivers.verify_document')
        <a href="{{ route('admin.drivers.verification') }}" class="btn btn-sm btn-light-warning">
            Antrean verifikasi
            @if (($jumlahVerifikasiTertunda ?? 0) > 0)
                <span class="badge badge-warning ms-1">{{ $jumlahVerifikasiTertunda }}</span>
            @endif
        </a>
    @endcan
@endsection

@section('content')
    <div class="row g-5 mb-6">
        @foreach ([
            ['Total', $statistik['total'], 'dark'],
            ['Aktif', $statistik['aktif'], 'success'],
            ['Menunggu review', $statistik['menunggu_review'], 'warning'],
            ['Ditangguhkan', $statistik['ditangguhkan'], 'danger'],
            ['Diblokir', $statistik['diblokir'], 'secondary'],
        ] as [$label, $nilai, $warna])
            <div class="col">
                <div class="card">
                    <div class="card-body py-4">
                        <div class="text-muted fs-8 text-uppercase fw-semibold">{{ $label }}</div>
                        <div class="fs-2 fw-bolder text-{{ $warna }}">
                            {{ number_format($nilai, 0, ',', '.') }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header min-h-45px">
            <form method="GET" class="d-flex gap-2 align-items-center py-3 w-100">
                <input type="text" name="cari" value="{{ request('cari') }}"
                    class="form-control form-control-sm w-250px"
                    placeholder="Nama, nomor HP, atau plat nomor" />

                <select name="status" class="form-select form-select-sm w-175px">
                    <option value="">Semua status</option>
                    @foreach (['draft', 'pending_review', 'active', 'suspended', 'banned', 'rejected'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn btn-sm btn-primary">Cari</button>

                @if (request()->hasAny(['cari', 'status']))
                    <a href="{{ route('admin.drivers.index') }}" class="btn btn-sm btn-light">Reset</a>
                @endif
            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Nama</th>
                            <th>Nomor HP</th>
                            <th>Status</th>
                            <th class="text-end">Rating</th>
                            <th class="text-end">Order selesai</th>
                            <th class="text-end">Terima</th>
                            <th class="text-end">Batal</th>
                            <th class="pe-6"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($driver as $d)
                            <tr>
                                <td class="ps-6">
                                    <div class="fw-bold">{{ $d->full_name }}</div>
                                    <div class="text-muted fs-9">
                                        gabung
                                        {{ $d->joined_at
                                            ? \App\Domain\Shared\Support\BusinessClock::at($d->joined_at)->format('d/m/Y')
                                            : '—' }}
                                    </div>
                                </td>

                                <td class="text-muted fs-7">
                                    {{--
                                        Nomor HP disamarkan di DAFTAR.

                                        Halaman ini dibuka untuk mencari orang, bukan
                                        menyalin nomornya. Nomor penuh ada di halaman
                                        detail, dan pembukaannya dicatat.
                                    --}}
                                    {{ $d->user
                                        ? \App\Domain\Identity\Support\PhoneNumber::masked((string) $d->user->phone)
                                        : '—' }}
                                </td>

                                <td>
                                    <span class="badge {{ $d->status->badgeClass() }}">
                                        {{ $d->status->label() }}
                                    </span>
                                </td>

                                <td class="text-end">{{ $d->rating_avg }}</td>
                                <td class="text-end">{{ number_format((int) $d->completed_orders, 0, ',', '.') }}</td>
                                <td class="text-end">{{ $d->acceptance_rate }}%</td>

                                <td class="text-end">
                                    @if ((float) $d->cancellation_rate > 20)
                                        {{-- Pembatalan tinggi ditandai: ini faktor pengurang di skoring matching. --}}
                                        <span class="badge badge-light-danger">{{ $d->cancellation_rate }}%</span>
                                    @else
                                        {{ $d->cancellation_rate }}%
                                    @endif
                                </td>

                                <td class="pe-6 text-end">
                                    <a href="{{ route('admin.drivers.show', $d->uuid) }}"
                                        class="btn btn-sm btn-light-primary">Buka</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-10">
                                    Tidak ada driver yang cocok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($driver->hasPages())
            <div class="card-footer">{{ $driver->links() }}</div>
        @endif
    </div>
@endsection
