@extends('backend.layout.app')

@section('title', 'Simulator Tarif')
@section('page_heading', 'Simulator Tarif')
@section('page_subheading', 'Hitung ongkos dengan tarif yang sedang berlaku, sebelum mengubah apa pun')

@section('page_actions')
    @can('pricing.propose')
        <a href="{{ route('admin.pricing.create') }}" class="btn btn-sm btn-light-primary">Buat tarif baru</a>
    @endcan
    <a href="{{ route('admin.pricing.index') }}" class="btn btn-sm btn-light">Daftar tarif</a>
@endsection

@section('content')
    <div class="row g-5">

        {{-- Form --}}
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Skenario</h3>
                </div>

                <form method="GET" action="{{ route('admin.pricing.simulator') }}">
                    <div class="card-body">
                        <div class="mb-5">
                            <label class="form-label fw-semibold">Layanan</label>
                            <select name="service_type_id" class="form-select" required>
                                <option value="">Pilih layanan</option>
                                @foreach ($layanan as $l)
                                    <option value="{{ $l->id }}"
                                        @selected(($masukan['service_type_id'] ?? null) == $l->id)>
                                        {{ $l->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-semibold">Zona</label>
                            <select name="zone_id" class="form-select">
                                {{--
                                    "Semua zona" bukan sekadar pilihan kosong.

                                    Tarif dengan zone_id NULL berlaku untuk seluruh
                                    kota, dan tarif spesifik zona menang atas dia.
                                    Menyebutnya "semua zona" — bukan "tidak dipilih"
                                    — membuat perilaku itu jelas dari form-nya.
                                --}}
                                <option value="">Semua zona (tarif umum)</option>
                                @foreach ($zona as $z)
                                    <option value="{{ $z->id }}"
                                        @selected(($masukan['zone_id'] ?? null) == $z->id)>
                                        {{ $z->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-semibold">Jarak (km)</label>
                            <input type="number" name="distance_km" class="form-control" step="0.1" min="0.1"
                                max="200" value="{{ $masukan['distance_km'] ?? '8' }}" required />
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-semibold">Durasi (menit)</label>
                            <input type="number" name="duration_minutes" class="form-control" min="1" max="600"
                                value="{{ $masukan['duration_minutes'] ?? '25' }}" required />
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-semibold">Surge</label>
                            <input type="number" name="surge" class="form-control" step="0.05" min="1" max="3"
                                value="{{ $masukan['surge'] ?? '1.00' }}" />
                            <div class="form-text">1.00 berarti tanpa surge. Maksimum 3.00.</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Hitung</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Hasil --}}
        <div class="col-xl-8">
            @if ($galat)
                <div class="alert alert-warning">{{ $galat }}</div>
            @endif

            @if (! $hasil)
                <div class="card">
                    <div class="card-body text-center py-15">
                        <div class="text-muted fs-5">
                            Pilih layanan dan jarak, lalu tekan Hitung.
                        </div>
                        <div class="text-muted fs-7 mt-3">
                            Perhitungannya memakai <span class="fw-bold">FareCalculator yang sama</span>
                            dengan yang menghitung ongkos sungguhan — bukan salinan terpisah.
                        </div>
                    </div>
                </div>
            @else
                @php
                    $rincian = $hasil['rincian'];
                    $aturan = $hasil['aturan'];
                @endphp

                {{-- Rincian ongkos --}}
                <div class="card mb-5">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Rincian ongkos</h3>
                        <div class="card-toolbar">
                            <span class="badge badge-light-secondary">
                                Tarif #{{ $aturan->id }}
                                @if ($aturan->zone)
                                    — {{ $aturan->zone->name }}
                                @else
                                    — semua zona
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-row-dashed align-middle gy-3 mb-0">
                                <tbody>
                                    @foreach ($rincian->displayLines() as $baris)
                                        <tr>
                                            <td class="fw-semibold">{{ $baris['label'] }}</td>
                                            {{--
                                                Dipakai `formatted` dari DTO, bukan
                                                diformat ulang di sini.

                                                `displayLines()` sudah menangani tanda
                                                untuk baris diskon dan penyesuaian
                                                regulasi — keduanya negatif dan harus
                                                tampil dengan minus di depan. Memformat
                                                ulang dari `amount` akan menghilangkan
                                                penanganan itu.
                                            --}}
                                            <td class="money">{{ $baris['formatted'] }}</td>
                                        </tr>
                                    @endforeach

                                    <tr class="border-top border-2">
                                        <td class="fw-bolder fs-5">Total dibayar penumpang</td>
                                        <td class="money fw-bolder fs-4">{{ $rincian->total->format() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="separator my-5"></div>

                        {{--
                            Pembagian uangnya ditampilkan juga, bukan hanya total.

                            Yang paling sering dilupakan saat mengubah tarif adalah
                            dampaknya ke PENDAPATAN DRIVER. Menaikkan biaya aplikasi
                            tidak menaikkan pendapatan driver sama sekali; menaikkan
                            komisi justru menurunkannya. Kedua angka itu harus
                            terlihat bersamaan dengan totalnya.
                        --}}
                        <div class="row g-4">
                            <div class="col-sm-4">
                                <div class="bg-light-success rounded p-4 h-100">
                                    <div class="text-muted fs-8 text-uppercase fw-semibold">Diterima driver</div>
                                    <div class="fs-3 fw-bolder text-success mt-1">
                                        {{ $rincian->driverEarning->format() }}
                                    </div>
                                    <div class="text-muted fs-8 mt-1">
                                        {{ $rincian->total->amount > 0
                                            ? round($rincian->driverEarning->amount / $rincian->total->amount * 100, 1)
                                            : 0 }}% dari total
                                    </div>
                                </div>
                            </div>

                            <div class="col-sm-4">
                                <div class="bg-light-primary rounded p-4 h-100">
                                    <div class="text-muted fs-8 text-uppercase fw-semibold">Komisi platform</div>
                                    <div class="fs-3 fw-bolder text-primary mt-1">
                                        {{ $rincian->commission->format() }}
                                    </div>
                                    <div class="text-muted fs-8 mt-1">
                                        {{ $aturan->commission_percent }}% dari ongkos transport
                                    </div>
                                </div>
                            </div>

                            <div class="col-sm-4">
                                <div class="bg-light-info rounded p-4 h-100">
                                    <div class="text-muted fs-8 text-uppercase fw-semibold">Biaya aplikasi</div>
                                    <div class="fs-3 fw-bolder text-info mt-1">
                                        {{ $rincian->platformFee->format() }}
                                    </div>
                                    <div class="text-muted fs-8 mt-1">tetap, tidak dibagi</div>
                                </div>
                            </div>
                        </div>

                        @if (! $rincian->regulatoryAdjustment->isZero())
                            {{--
                                Penyesuaian regulasi diberi peringatan tersendiri.

                                Kalau ongkos hasil hitung menembus batas Permenhub,
                                artinya tarif per-km yang dikonfigurasi TIDAK
                                sepenuhnya berlaku pada jarak itu. Tanpa peringatan,
                                staf ops akan menaikkan tarif per-km dan bingung
                                kenapa totalnya tidak berubah.
                            --}}
                            <div class="alert alert-warning mt-5 mb-0">
                                <div class="fw-bold">Ongkos ini kena batas tarif resmi.</div>
                                <div class="fs-7 mt-1">
                                    Penyesuaiannya {{ $rincian->regulatoryAdjustment->format() }}.
                                    Pada jarak ini, tarif per-km yang Anda atur tidak sepenuhnya berlaku —
                                    menaikkannya lebih jauh tidak akan menambah ongkos.
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Kurva jarak --}}
                <div class="card">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Bentuk kurva tarif</h3>
                        <div class="card-toolbar">
                            <span class="text-muted fs-8">
                                Durasi diperkirakan pada 20 km/jam
                            </span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="text-muted fs-7 mb-4">
                            Satu angka untuk satu jarak tidak cukup untuk menilai tarif. Tarif yang
                            wajar di 5 km bisa menembus batas regulasi di 40 km, dan itu hanya terlihat
                            kalau beberapa jarak dihitung bersamaan.
                        </div>

                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle gy-3 mb-0">
                                <thead>
                                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                                        <th>Jarak</th>
                                        <th class="text-end">Total ongkos</th>
                                        <th class="text-end">Rata-rata per km</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($hasil['kurva'] as $titik)
                                        <tr>
                                            <td class="fw-semibold">{{ $titik['km'] }} km</td>
                                            <td class="money">{{ $titik['total']->format() }}</td>
                                            <td class="money">{{ $titik['per_km']?->format() ?? '—' }}</td>
                                            <td>
                                                @if ($titik['kena_regulasi'])
                                                    <span class="badge badge-light-warning">
                                                        kena batas resmi
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Angka tarif yang dipakai --}}
                <div class="card mt-5">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Angka tarif yang dipakai</h3>
                        <div class="card-toolbar">
                            <span class="text-muted fs-8">
                                Berlaku dari
                                {{ \App\Domain\Shared\Support\BusinessClock::at($aturan->effective_from)->format('d/m/Y H:i') }}
                                WIB
                            </span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row g-4">
                            @foreach ([
                                'Tarif dasar' => $aturan->base_fare,
                                'Per km' => $aturan->per_km,
                                'Per menit' => $aturan->per_minute,
                                'Tarif minimum' => $aturan->minimum_fare,
                                'Biaya aplikasi' => $aturan->platform_fee,
                                'Batas bawah resmi' => $aturan->min_fare_regulated,
                                'Batas atas resmi' => $aturan->max_fare_regulated,
                            ] as $label => $nilai)
                                <div class="col-sm-6 col-lg-3">
                                    <div class="text-muted fs-8 text-uppercase fw-semibold">{{ $label }}</div>
                                    <div class="fw-bold fs-5">
                                        {{ $nilai === null
                                            ? 'tidak diatur'
                                            : \App\Domain\Shared\ValueObjects\Money::of((int) $nilai)->format() }}
                                    </div>
                                </div>
                            @endforeach

                            <div class="col-sm-6 col-lg-3">
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Jarak gratis</div>
                                <div class="fw-bold fs-5">{{ $aturan->free_distance_m }} m</div>
                            </div>

                            <div class="col-sm-6 col-lg-3">
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Komisi</div>
                                <div class="fw-bold fs-5">{{ $aturan->commission_percent }}%</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
