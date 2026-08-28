@extends('backend.layout.app')

@section('title', 'Surge')
@section('page_heading', 'Aturan Surge')
@section('page_subheading', 'Pengali tarif otomatis berdasarkan jadwal atau rasio permintaan-pasokan')

@section('content')
    @php
        $surgeAktif = \App\Domain\Support\Models\FeatureFlag::isEnabled('surge.enabled', default: true);
    @endphp

    @if (! $surgeAktif)
        <div class="alert alert-danger mb-6">
            <div class="fw-bold">Surge sedang DIMATIKAN seluruh sistem.</div>
            <div class="fs-7 mt-1">
                Aturan di bawah tidak berlaku sama sekali sampai kill switch
                <code>surge.enabled</code> dinyalakan kembali.
            </div>
            @can('feature_flags.manage')
                <div class="mt-3">
                    <a href="{{ route('admin.settings.flags') }}" class="btn btn-sm btn-danger">
                        Buka kill switch
                    </a>
                </div>
            @endcan
        </div>
    @endif

    <div class="alert alert-light-primary mb-6">
        <div class="fw-bold">Dua sumber surge, dan yang tertinggi menang</div>
        <div class="fs-7 mt-1">
            <span class="fw-semibold">Jadwal</span> — pengali tetap pada rentang jam
            tertentu, misalnya jam pulang kerja.
            <br>
            <span class="fw-semibold">Rasio permintaan-pasokan</span> — dihitung dari jumlah
            order yang mencari driver dibagi jumlah driver tersedia di zona itu.
            <br><br>
            Batas atasnya 3.00&times;.
            Pengali di atas itu membuat penumpang berhenti memesan, dan yang tersisa hanya
            order yang tidak jadi.
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Zona</th>
                            <th>Jenis</th>
                            <th class="text-end">Pengali</th>
                            <th>Berlaku</th>
                            <th class="pe-6">Keadaan</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($aturan as $a)
                            <tr class="{{ $a->is_active && $surgeAktif ? '' : 'text-muted bg-light' }}">
                                <td class="ps-6">
                                    @if ($a->zone)
                                        <span class="fw-bold">{{ $a->zone->name }}</span>
                                    @else
                                        <span class="badge badge-light-secondary fs-9">semua zona</span>
                                    @endif
                                </td>

                                <td>
                                    <span class="badge badge-light-primary">{{ $a->trigger_type }}</span>
                                </td>

                                <td class="text-end fw-bolder fs-5">
                                    {{ $a->multiplier }}&times;
                                </td>

                                <td class="fs-8">
                                    @if ($a->day_of_week !== null)
                                        <div>
                                            hari:
                                            {{ is_array($a->day_of_week)
                                                ? implode(', ', $a->day_of_week)
                                                : $a->day_of_week }}
                                        </div>
                                    @endif

                                    @if ($a->start_time)
                                        <div>{{ $a->start_time }} &ndash; {{ $a->end_time }} WIB</div>
                                    @endif

                                    @if ($a->demand_threshold)
                                        <div class="text-muted">
                                            rasio &ge; {{ $a->demand_threshold }}
                                        </div>
                                    @endif
                                </td>

                                <td class="pe-6">
                                    @if (! $surgeAktif)
                                        <span class="badge badge-light-danger">dimatikan global</span>
                                    @elseif ($a->is_active)
                                        <span class="badge badge-light-success">aktif</span>
                                    @else
                                        <span class="badge badge-light-secondary">nonaktif</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-15">
                                    <div class="fs-5">Belum ada aturan surge.</div>
                                    <div class="fs-7 mt-1">
                                        Tanpa aturan, seluruh order dihitung dengan pengali 1.00.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
