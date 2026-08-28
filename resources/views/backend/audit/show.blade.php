@extends('backend.layout.app')

@section('title', 'Audit ' . $baris->action)
@section('page_heading', $baris->action)
@section('page_subheading',
    \App\Domain\Shared\Support\BusinessClock::at($baris->created_at)->format('d M Y, H:i:s') . ' WIB')

@section('page_actions')
    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-light">Kembali</a>
@endsection

@section('content')
    <div class="row g-5">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Konteks</h3>
                </div>
                <div class="card-body">
                    @foreach ([
                        'Staf' => $baris->admin?->name ?? '—',
                        'Email staf' => $baris->admin?->email ?? '—',
                        'Tindakan' => $baris->action,
                        'Objek' => $baris->auditable_type
                            ? $baris->auditable_type . ' #' . $baris->auditable_id
                            : '—',
                        'IP' => $baris->ip_address ?? '—',
                    ] as $label => $nilai)
                        <div class="d-flex flex-stack mb-3">
                            <span class="text-muted fs-7">{{ $label }}</span>
                            <span class="fw-semibold text-end">{{ $nilai }}</span>
                        </div>
                    @endforeach

                    <div class="separator my-4"></div>

                    <div class="text-muted fs-8 text-uppercase fw-semibold">User agent</div>
                    <div class="fs-9 text-muted" style="word-break: break-all">
                        {{ $baris->user_agent ?? '—' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            {{--
                Nilai SEBELUM dan SESUDAH ditampilkan berdampingan.

                Ini seluruh alasan audit log menyimpan keduanya: "tarif diubah"
                tidak menjelaskan apa pun; "tarif per km dari 2500 menjadi 3500"
                menjelaskan semuanya. Menampilkannya di kolom terpisah membuat
                selisihnya terlihat tanpa membandingkan sendiri.
            --}}
            <div class="row g-5">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header min-h-45px bg-light-danger">
                            <h3 class="card-title fw-bold text-danger">Sebelum</h3>
                        </div>
                        <div class="card-body">
                            @if (empty($baris->old_values))
                                <div class="text-muted fs-7">Tidak ada nilai sebelumnya.</div>
                            @else
                                @foreach ($baris->old_values as $kunci => $nilai)
                                    <div class="mb-3">
                                        <div class="text-muted fs-9 text-uppercase fw-semibold">
                                            {{ $kunci }}
                                        </div>
                                        <div class="fw-semibold" style="word-break: break-word">
                                            {{ is_scalar($nilai) || $nilai === null
                                                ? ($nilai ?? '—')
                                                : json_encode($nilai, JSON_UNESCAPED_UNICODE) }}
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header min-h-45px bg-light-success">
                            <h3 class="card-title fw-bold text-success">Sesudah</h3>
                        </div>
                        <div class="card-body">
                            @if (empty($baris->new_values))
                                <div class="text-muted fs-7">Tidak ada nilai baru.</div>
                            @else
                                @foreach ($baris->new_values as $kunci => $nilai)
                                    <div class="mb-3">
                                        <div class="text-muted fs-9 text-uppercase fw-semibold">
                                            {{ $kunci }}
                                        </div>
                                        <div class="fw-semibold" style="word-break: break-word">
                                            {{ is_scalar($nilai) || $nilai === null
                                                ? ($nilai ?? '—')
                                                : json_encode($nilai, JSON_UNESCAPED_UNICODE) }}
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
