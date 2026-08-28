@extends('backend.layout.app')

@section('title', 'Audit Log')
@section('page_heading', 'Audit Log')
@section('page_subheading', 'Hanya bisa dibaca — tidak ada tombol yang mengubah atau menghapus')

@section('content')
    <div class="alert alert-light-primary mb-6 fs-7">
        {{--
            Kenapa halaman ini tidak punya tombol hapus dijelaskan, bukan
            dibiarkan terlihat sebagai fitur yang belum dibuat.
        --}}
        Audit log yang bisa diedit atau dihapus dari panel tidak membuktikan apa pun — dan
        justru orang yang paling ingin mengubahnya adalah orang yang punya akses ke panel.
        Penghapusan retensi dijalankan perintah di server, bukan lewat HTTP.
    </div>

    <div class="card mb-6">
        <div class="card-body py-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Staf</label>
                    <select name="admin_id" class="form-select form-select-sm">
                        <option value="">Semua staf</option>
                        @foreach ($admin as $a)
                            <option value="{{ $a->id }}" @selected(request('admin_id') == $a->id)>
                                {{ $a->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Kelompok tindakan</label>
                    <select name="action" class="form-select form-select-sm">
                        <option value="">Semua tindakan</option>
                        @foreach ($kelompokTindakan as $k)
                            <option value="{{ $k->kelompok }}" @selected(request('action') === $k->kelompok)>
                                {{ $k->kelompok }} ({{ $k->jumlah }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Tanggal (WIB)</label>
                    <div class="d-flex gap-2">
                        <input type="date" name="dari" value="{{ request('dari') }}"
                            class="form-control form-control-sm" />
                        <input type="date" name="sampai" value="{{ request('sampai') }}"
                            class="form-control form-control-sm" />
                    </div>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    @if (request()->hasAny(['admin_id', 'action', 'dari', 'sampai']))
                        <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-light">Reset</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-2 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Waktu</th>
                            <th>Staf</th>
                            <th>Tindakan</th>
                            <th>Objek</th>
                            <th>IP</th>
                            <th class="pe-6"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($log as $baris)
                            <tr>
                                <td class="ps-6 fs-8">
                                    {{ \App\Domain\Shared\Support\BusinessClock::at($baris->created_at)->format('d/m/y H:i:s') }}
                                </td>

                                <td class="fs-7">
                                    {{ $baris->admin?->name ?? '—' }}
                                </td>

                                <td>
                                    @php
                                        /*
                                         * Tindakan yang menyangkut kegagalan diberi warna
                                         * berbeda.
                                         *
                                         * Upaya masuk yang gagal dan re-autentikasi yang
                                         * gagal adalah dua hal yang paling penting untuk
                                         * ditemukan cepat di halaman ini — keduanya bisa
                                         * berarti ada yang memakai sesi orang lain.
                                         */
                                        $gagal = str_contains((string) $baris->action, 'failed');
                                    @endphp

                                    <span class="badge badge-light-{{ $gagal ? 'danger' : 'secondary' }} fs-9">
                                        {{ $baris->action }}
                                    </span>
                                </td>

                                <td class="fs-8 text-muted">
                                    @if ($baris->auditable_type)
                                        {{ $baris->auditable_type }} #{{ $baris->auditable_id }}
                                    @else
                                        —
                                    @endif
                                </td>

                                <td class="fs-9 text-muted">{{ $baris->ip_address }}</td>

                                <td class="pe-6 text-end">
                                    <a href="{{ route('admin.audit.show', $baris->uuid) }}"
                                        class="btn btn-sm btn-light">Rincian</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-10">
                                    Tidak ada catatan yang cocok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($log->hasPages())
            <div class="card-footer">{{ $log->links() }}</div>
        @endif
    </div>
@endsection
