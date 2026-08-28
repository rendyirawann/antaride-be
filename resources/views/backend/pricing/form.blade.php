@extends('backend.layout.app')

@section('title', 'Tarif Baru')
@section('page_heading', 'Tarif Baru')
@section('page_subheading', 'Tarif lama untuk kombinasi yang sama akan ditutup otomatis pada saat tarif ini mulai berlaku')

@section('page_actions')
    <a href="{{ route('admin.pricing.simulator') }}" class="btn btn-sm btn-light-primary">Simulator</a>
    <a href="{{ route('admin.pricing.index') }}" class="btn btn-sm btn-light">Kembali</a>
@endsection

@section('content')
    <div class="alert alert-warning mb-6">
        <div class="fw-bold">Simulasikan dulu sebelum menyimpan.</div>
        <div class="fs-7 mt-1">
            Tarif adalah satu-satunya pengaturan di panel ini yang salahnya langsung terasa
            oleh setiap penumpang di kota, dan tidak bisa dibatalkan untuk order yang sudah
            jalan. Angka per kilometer sendiri tidak memberi tahu apa pun tentang ongkos yang
            akan ditagih — hasilnya bergantung pada enam angka yang berinteraksi.
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger mb-6">
            @foreach ($errors->all() as $pesan)
                <div>{{ $pesan }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.pricing.store') }}">
        @csrf

        <div class="row g-5">
            <div class="col-xl-6">
                <div class="card mb-5">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Berlaku untuk</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-5">
                            <label class="form-label fw-semibold">Layanan <span class="text-danger">*</span></label>
                            <select name="service_type_id" class="form-select" required>
                                <option value="">Pilih layanan</option>
                                @foreach ($layanan as $l)
                                    <option value="{{ $l->id }}" @selected(old('service_type_id') == $l->id)>
                                        {{ $l->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-semibold">Zona</label>
                            <select name="zone_id" class="form-select">
                                <option value="">Semua zona (tarif umum)</option>
                                @foreach ($zona as $z)
                                    <option value="{{ $z->id }}" @selected(old('zone_id') == $z->id)>
                                        {{ $z->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Tarif spesifik zona menang atas tarif umum untuk zona itu.
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">
                                    Mulai berlaku <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" name="effective_from" class="form-control"
                                    value="{{ old('effective_from', now()->addMinutes(5)->format('Y-m-d\TH:i')) }}"
                                    required />
                                <div class="form-text">
                                    {{--
                                        Kenapa tidak boleh retroaktif dijelaskan di form-nya.

                                        Staf ops yang mencoba mengisi tanggal lalu perlu tahu
                                        bahwa penolakannya bukan bug.
                                    --}}
                                    Tidak boleh di masa lalu — ongkos order yang sudah
                                    selesai tidak bisa diubah.
                                </div>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Sampai (opsional)</label>
                                <input type="datetime-local" name="effective_until" class="form-control"
                                    value="{{ old('effective_until') }}" />
                                <div class="form-text">Kosongkan kalau berlaku sampai diganti.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Batas tarif resmi</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-muted fs-7 mb-4">
                            Batas Permenhub untuk SELURUH ongkos transport, bukan per kilometer.
                            Kosongkan kalau layanan ini tidak diatur.
                        </div>

                        <div class="row g-4">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Batas bawah</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="min_fare_regulated" class="form-control"
                                        min="0" step="1" value="{{ old('min_fare_regulated') }}" />
                                </div>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Batas atas</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="max_fare_regulated" class="form-control"
                                        min="0" step="1" value="{{ old('max_fare_regulated') }}" />
                                </div>
                                <div class="form-text">
                                    {{--
                                        Peringatan soal batas terbalik ada di form, bukan hanya
                                        di pesan validasi.

                                        Kalau terbalik, clamp akan menaikkan ke minimum lalu
                                        menurunkan ke maksimum — dan hasil akhirnya nilai
                                        maksimum untuk SETIAP order, sebesar apa pun jaraknya.
                                    --}}
                                    Harus lebih besar dari batas bawah. Kalau terbalik, setiap
                                    order akan ditagih sebesar batas atasnya.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card mb-5">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Angka tarif</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light-warning fs-8">
                            Semua nominal dalam <span class="fw-bold">rupiah utuh, tanpa desimal
                                dan tanpa pemisah ribuan</span>. Tulis 4500, bukan 4.500 atau 4500,00.
                        </div>

                        @foreach ([
                            ['base_fare', 'Tarif dasar', 'Dikenakan untuk setiap order, sebelum jarak dihitung.', true],
                            ['per_km', 'Per kilometer', 'Dikenakan setelah jarak gratis terlampaui.', true],
                            ['per_minute', 'Per menit', 'Isi 0 kalau tidak dipakai.', true],
                            ['minimum_fare', 'Tarif minimum', 'Ongkos transport dinaikkan ke angka ini kalau di bawahnya.', true],
                            ['free_distance_m', 'Jarak gratis (meter)', 'Bagian awal perjalanan yang tidak dikenai tarif per-km. Maksimal 5000.', true],
                            ['platform_fee', 'Biaya aplikasi', 'Milik platform sepenuhnya, tidak dibagi ke driver.', true],
                        ] as [$nama, $label, $bantuan, $wajib])
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    {{ $label }}
                                    @if ($wajib)
                                        <span class="text-danger">*</span>
                                    @endif
                                </label>
                                <div class="input-group">
                                    @if ($nama !== 'free_distance_m')
                                        <span class="input-group-text">Rp</span>
                                    @endif
                                    <input type="number" name="{{ $nama }}" class="form-control" min="0"
                                        step="1" value="{{ old($nama, 0) }}" @required($wajib) />
                                    @if ($nama === 'free_distance_m')
                                        <span class="input-group-text">m</span>
                                    @endif
                                </div>
                                <div class="form-text">{{ $bantuan }}</div>
                            </div>
                        @endforeach

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Komisi platform <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" name="commission_percent" class="form-control" min="0"
                                    max="50" step="0.01" value="{{ old('commission_percent', 20) }}" required />
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">
                                Dihitung dari ongkos transport, bukan dari total. Biaya aplikasi
                                tidak ikut dibagi.
                            </div>
                        </div>

                        <div class="separator my-5"></div>

                        <div class="row g-4">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold fs-7">Biaya kemasan</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="packaging_fee" class="form-control" min="0"
                                        step="1" value="{{ old('packaging_fee') }}" />
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold fs-7">Biaya asuransi</label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="insurance_fee" class="form-control" min="0"
                                        step="1" value="{{ old('insurance_fee') }}" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn btn-primary"
                        data-konfirmasi-judul="Simpan tarif baru?"
                        data-konfirmasi="Tarif ini akan berlaku untuk <b>seluruh order baru</b> pada layanan dan zona yang dipilih, mulai waktu yang Anda tentukan.<br><br>Tarif lama untuk kombinasi yang sama akan <b>ditutup otomatis</b> pada saat yang sama."
                        data-konfirmasi-ya="Ya, simpan">
                        Simpan tarif
                    </button>

                    <a href="{{ route('admin.pricing.index') }}" class="btn btn-light">Batal</a>
                </div>
            </div>
        </div>
    </form>
@endsection
