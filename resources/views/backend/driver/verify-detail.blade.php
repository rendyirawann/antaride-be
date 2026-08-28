@extends('backend.layout.app')

@section('title', 'Verifikasi ' . $driver->full_name)
@section('page_heading', 'Verifikasi: ' . $driver->full_name)
@section('page_subheading', 'Periksa semua dokumen bersamaan — nama di KTP dan SIM harus sama')

@section('page_actions')
    <a href="{{ route('admin.drivers.verification') }}" class="btn btn-sm btn-light">Kembali ke antrean</a>
@endsection

@section('content')
    {{--
        Kelengkapan ditampilkan LEBIH DULU, sebelum daftar dokumennya.

        Dokumen yang belum diunggah tidak muncul di daftar sama sekali — dan yang
        tidak terlihat tidak akan disadari hilang. Blok ini yang membuat "SIM
        belum diunggah" sama terlihatnya dengan "SIM ditolak".
    --}}
    <div class="card mb-6">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Kelengkapan dokumen wajib</h3>
        </div>

        <div class="card-body">
            <div class="row g-4">
                @foreach ($wajib as $jenis => $info)
                    <div class="col-sm-6 col-lg-3">
                        @php
                            [$warna, $teks] = match (true) {
                                ! $info['ada'] => ['danger', 'belum diunggah'],
                                $info['sudah_kadaluarsa'] => ['danger', 'kadaluarsa'],
                                $info['status'] === 'approved' => ['success', 'disetujui'],
                                $info['status'] === 'rejected' => ['danger', 'ditolak'],
                                $info['status'] === 'pending' => ['warning', 'menunggu review'],
                                default => ['secondary', (string) $info['status']],
                            };
                        @endphp

                        <div class="border border-{{ $warna }} rounded p-4 h-100">
                            <div class="text-uppercase fs-9 fw-semibold text-muted">{{ $jenis }}</div>
                            <div class="fw-bold text-{{ $warna }} mt-1">{{ $teks }}</div>

                            @if ($info['kadaluarsa'])
                                <div class="text-muted fs-9 mt-1">
                                    sampai {{ $info['kadaluarsa']->format('d/m/Y') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @php
                $semuaLengkap = collect($wajib)->every(
                    fn ($i) => $i['status'] === 'approved' && ! $i['sudah_kadaluarsa']
                );
            @endphp

            @if ($semuaLengkap && $driver->status->value !== 'active')
                <div class="alert alert-success mt-5 mb-0">
                    Semua dokumen wajib sudah disetujui. Driver akan diaktifkan otomatis
                    setelah persetujuan terakhir tersimpan.
                </div>
            @endif
        </div>
    </div>

    <div class="row g-5">
        {{-- Data driver, untuk dibandingkan dengan dokumennya --}}
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Data yang didaftarkan</h3>
                </div>
                <div class="card-body">
                    {{--
                        Data yang diketik driver ditampilkan di sebelah dokumennya.

                        Yang diperiksa verifikator bukan keaslian dokumennya saja, tapi
                        apakah nama dan NIK yang dia ketik COCOK dengan yang tertulis
                        di KTP. Menaruh keduanya di halaman berbeda membuat perbandingan
                        itu tidak terjadi.
                    --}}
                    <div class="mb-4">
                        <div class="text-muted fs-8 text-uppercase fw-semibold">Nama lengkap</div>
                        <div class="fw-bold fs-5">{{ $driver->full_name }}</div>
                    </div>

                    <div class="mb-4">
                        <div class="text-muted fs-8 text-uppercase fw-semibold">NIK</div>
                        <div class="fw-bold">
                            @can('kyc.view_full')
                                {{ $driver->nikFull() ?? '—' }}
                            @else
                                {{ $driver->nikMasked() ?? '—' }}
                                <span class="badge badge-light-secondary ms-2 fs-9">tersamarkan</span>
                            @endcan
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="text-muted fs-8 text-uppercase fw-semibold">Nomor HP</div>
                        <div class="fw-bold">
                            {{ $driver->user
                                ? \App\Domain\Identity\Support\PhoneNumber::forDisplay((string) $driver->user->phone)
                                : '—' }}
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="text-muted fs-8 text-uppercase fw-semibold">Alamat</div>
                        <div class="fw-semibold fs-7">{{ $driver->address ?? '—' }}</div>
                        <div class="text-muted fs-8">{{ $driver->city ?? '' }}</div>
                    </div>

                    <div class="separator my-4"></div>

                    <div class="text-muted fs-8 text-uppercase fw-semibold mb-2">Kendaraan</div>

                    @forelse ($driver->vehicles as $kendaraan)
                        <div class="border rounded p-3 mb-2">
                            <div class="fw-bold">{{ $kendaraan->plate_number }}</div>
                            <div class="text-muted fs-8">
                                {{ $kendaraan->brand }} {{ $kendaraan->model }}
                                ({{ $kendaraan->year }}) — {{ $kendaraan->color }}
                            </div>
                            <div class="text-muted fs-9">
                                {{ $kendaraan->type }}, kapasitas {{ $kendaraan->capacity }}
                            </div>
                        </div>
                    @empty
                        <div class="text-danger fs-7">
                            Belum ada kendaraan terdaftar. Driver tidak bisa online tanpa kendaraan.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Dokumen, satu kartu per dokumen --}}
        <div class="col-xl-8">
            @forelse ($driver->documents as $dok)
                <div class="card mb-5">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">{{ $dok->label() }}</h3>

                        <div class="card-toolbar">
                            @php
                                $warnaDok = match ($dok->status) {
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    'pending' => 'warning',
                                    default => 'secondary',
                                };
                            @endphp
                            <span class="badge badge-light-{{ $warnaDok }}">{{ $dok->status }}</span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-4 mb-4">
                            @can('kyc.view_masked')
                                <a href="{{ route('admin.documents.file', $dok->id) }}" target="_blank"
                                    class="btn btn-sm btn-light-primary">
                                    Buka berkas
                                </a>
                                <div class="text-muted fs-9 align-self-center">
                                    {{--
                                        Peringatan bahwa pembukaannya dicatat.

                                        Bukan ancaman, tapi pengingat: staf yang tahu
                                        tindakannya berjejak berperilaku berbeda dari
                                        yang menganggapnya tidak terlihat. Itu justru
                                        yang melindungi data pribadi driver.
                                    --}}
                                    Pembukaan berkas dicatat di audit log.
                                </div>
                            @endcan
                        </div>

                        @if ($dok->number)
                            <div class="mb-4">
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Nomor dokumen</div>
                                <div class="fw-bold">{{ $dok->number }}</div>
                            </div>
                        @endif

                        @if ($dok->status === 'rejected' && $dok->reject_reason)
                            <div class="alert alert-light-danger">
                                <div class="fw-bold fs-8 text-uppercase">Alasan penolakan sebelumnya</div>
                                <div class="fs-7 mt-1">{{ $dok->reject_reason }}</div>
                            </div>
                        @endif

                        @if ($dok->status === 'pending')
                            <div class="separator my-4"></div>

                            <div class="row g-4">
                                {{-- Setujui --}}
                                <div class="col-md-6">
                                    <form method="POST" action="{{ route('admin.documents.approve', $dok->id) }}">
                                        @csrf

                                        @php
                                            $butuhKadaluarsa = in_array(
                                                $dok->type,
                                                (array) config('antaride.kyc.expiring_documents', ['sim', 'stnk']),
                                                true,
                                            );
                                        @endphp

                                        @if ($butuhKadaluarsa)
                                            <label class="form-label fw-semibold fs-7">
                                                Berlaku sampai
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="date" name="expires_at" class="form-control form-control-sm mb-2"
                                                min="{{ now()->addDay()->toDateString() }}" required />
                                            <div class="form-text mb-3">
                                                {{--
                                                    Kenapa wajib dijelaskan di form-nya.

                                                    Tanpa tanggal ini, GoOnline tidak punya
                                                    cara mengetahui SIM seorang driver sudah
                                                    habis — dan dia tetap mengambil order.
                                                --}}
                                                Tanpa tanggal ini, sistem tidak bisa tahu kapan
                                                dokumennya habis, dan driver tetap bisa online
                                                dengan dokumen kadaluarsa.
                                            </div>
                                        @endif

                                        <button type="submit" class="btn btn-sm btn-success w-100">
                                            Setujui {{ $dok->label() }}
                                        </button>
                                    </form>
                                </div>

                                {{-- Tolak --}}
                                <div class="col-md-6">
                                    <form method="POST" action="{{ route('admin.documents.reject', $dok->id) }}">
                                        @csrf

                                        <label class="form-label fw-semibold fs-7">
                                            Apa yang harus diperbaiki
                                            <span class="text-danger">*</span>
                                        </label>
                                        <textarea name="note" class="form-control form-control-sm mb-2" rows="2"
                                            required minlength="15"
                                            placeholder="Contoh: foto KTP terpotong di sisi kanan, NIK tidak terbaca"></textarea>
                                        <div class="form-text mb-3">
                                            Teks ini dikirim ke aplikasi driver. "Tidak jelas" akan
                                            menghasilkan unggahan kedua yang sama buruknya.
                                        </div>

                                        <button type="submit" class="btn btn-sm btn-light-danger w-100">
                                            Tolak {{ $dok->label() }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @else
                            <div class="text-muted fs-8">
                                Direview
                                {{ $dok->reviewed_at
                                    ? \App\Domain\Shared\Support\BusinessClock::at($dok->reviewed_at)->format('d/m/Y H:i')
                                    : '—' }}
                                @if ($dok->expires_at)
                                    &middot; berlaku sampai {{ $dok->expires_at->format('d/m/Y') }}
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card">
                    <div class="card-body text-center py-15 text-muted">
                        Driver ini belum mengunggah satu dokumen pun.
                    </div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
