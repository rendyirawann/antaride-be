@extends('backend.layout.app')

@section('title', 'Dashboard')
@section('page_heading', 'Dashboard')
@section('page_subheading',
    'Hari ini, ' . \App\Domain\Shared\Support\BusinessClock::now()->translatedFormat('l d F Y') . ' — WIB')

@section('content')

    {{--
        Order macet ditampilkan PALING ATAS, sebelum angka apa pun.

        Ini satu-satunya blok di dashboard yang berarti ada penumpang sedang
        menatap layar tanpa jawaban. Menaruhnya di bawah grafik berarti dia
        ditemukan setelah orangnya sudah menutup aplikasi.
    --}}
    @if ($orderMacet->isNotEmpty())
        <div class="card border border-danger mb-6">
            <div class="card-header bg-light-danger min-h-45px">
                <h3 class="card-title text-danger fw-bold">
                    {{ $orderMacet->count() }} order belum dapat driver
                </h3>
                <div class="card-toolbar">
                    <a href="{{ route('admin.livemap.index') }}" class="btn btn-sm btn-danger">Buka Live Map</a>
                </div>
            </div>

            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table table-row-dashed align-middle gy-2 mb-0">
                        <thead>
                            <tr class="fw-bold text-muted fs-7 text-uppercase">
                                <th>Nomor</th>
                                <th>Menunggu</th>
                                <th>Layanan</th>
                                <th>Zona</th>
                                <th>Penjemputan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orderMacet as $order)
                                <tr>
                                    <td class="fw-bold">{{ $order->order_number }}</td>
                                    <td>
                                        {{--
                                            Lama menunggu ditampilkan sebagai DURASI, bukan
                                            jam pemesanan.

                                            "4 menit" langsung memberi tahu seberapa
                                            mendesak; "08:14" menuntut staf menghitung
                                            sendiri, dan pada dua puluh baris itu tidak akan
                                            dilakukan.
                                        --}}
                                        <span class="badge badge-light-danger">
                                            {{ \Illuminate\Support\Carbon::parse($order->requested_at)->diffForHumans(null, true) }}
                                        </span>
                                    </td>
                                    <td>{{ $order->layanan ?? '—' }}</td>
                                    <td>{{ $order->zona ?? '—' }}</td>
                                    <td class="text-muted">{{ Str::limit($order->pickup_address, 40) }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.orders.show', $order->uuid) }}"
                                            class="btn btn-sm btn-light-primary">Tangani</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Kartu angka hari ini --}}
    <div class="row g-5 g-xl-6 mb-6">
        @php
            /**
             * Bandingkan dengan hari sebelumnya.
             *
             * Angka tanpa pembanding tidak bisa dinilai: 240 order hari ini
             * bagus atau buruk sepenuhnya bergantung pada berapa kemarin.
             * Ini yang membuat dashboard bisa dibaca dalam lima detik.
             */
            $delta = function (int|float|null $kini, int|float|null $lalu): ?array {
                if ($kini === null || $lalu === null || $lalu == 0) {
                    return null;
                }

                $persen = round((($kini - $lalu) / $lalu) * 100, 1);

                return [
                    'persen' => abs($persen),
                    'naik' => $persen >= 0,
                ];
            };
        @endphp

        {{--
            Setiap kartu membawa TIGA hal: teks yang ditampilkan, dan dua angka
            untuk dibandingkan.

            Versi pertama mengirim nilai terformat ("Rp 1.250.000") ke closure
            pembanding, dan closure itu langsung gagal dengan TypeError karena
            menerima string di parameter int|float. Yang jatuh bukan kartunya
            saja — seluruh dashboard menjadi 500.

            Memisahkan "yang ditampilkan" dari "yang dibandingkan" membuat
            kesalahan itu tidak mungkin terulang: tidak ada satu nilai yang
            dipakai untuk dua tujuan yang berbeda.
        --}}
        @foreach ([
            ['Order dibuat', number_format($hariIni['dibuat'], 0, ',', '.'), $hariIni['dibuat'], $kemarin['dibuat'], false],
            ['Order selesai', number_format($hariIni['selesai'], 0, ',', '.'), $hariIni['selesai'], $kemarin['selesai'], false],
            ['GMV', $hariIni['gmv']->format(), $hariIni['gmv']->amount, $kemarin['gmv']->amount, true],
            ['Komisi', $hariIni['komisi']->format(), $hariIni['komisi']->amount, $kemarin['komisi']->amount, true],
        ] as [$label, $tampilan, $angkaKini, $angkaLalu, $uang])
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted fw-semibold fs-7 text-uppercase mb-2">{{ $label }}</div>

                        <div class="fs-2hx fw-bold text-gray-900">{{ $tampilan }}</div>

                        @php
                            $d = $delta($angkaKini, $angkaLalu);
                        @endphp

                        @if ($d)
                            <div class="d-flex align-items-center mt-2">
                                <span class="badge badge-light-{{ $d['naik'] ? 'success' : 'danger' }} fs-8">
                                    {{ $d['naik'] ? '+' : '−' }}{{ $d['persen'] }}%
                                </span>
                                <span class="text-muted fs-8 ms-2">vs kemarin</span>
                            </div>
                        @else
                            <div class="text-muted fs-8 mt-2">Belum ada pembanding</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-5 g-xl-6 mb-6">
        {{-- Kesehatan hari ini --}}
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Kesehatan hari ini</h3>
                </div>

                <div class="card-body">
                    @php
                        /**
                         * Ambang warna, bukan angka mentah.
                         *
                         * Tingkat penyelesaian 82% baik atau buruk tidak bisa
                         * dinilai tanpa tahu ambangnya, dan staf yang berbeda
                         * akan menilai berbeda. Warnanya yang menyamakan
                         * penilaian.
                         */
                        $warnaTingkat = function (?float $nilai, float $baik, float $waspada): string {
                            if ($nilai === null) {
                                return 'secondary';
                            }

                            return $nilai >= $baik ? 'success' : ($nilai >= $waspada ? 'warning' : 'danger');
                        };
                    @endphp

                    <div class="d-flex flex-stack mb-5">
                        <span class="text-gray-700 fw-semibold">Tingkat penyelesaian</span>
                        <span
                            class="badge badge-light-{{ $warnaTingkat($hariIni['tingkat_selesai'], 85, 70) }} fs-6">
                            {{ $hariIni['tingkat_selesai'] !== null ? $hariIni['tingkat_selesai'] . '%' : '—' }}
                        </span>
                    </div>

                    <div class="d-flex flex-stack mb-5">
                        <span class="text-gray-700 fw-semibold">Tidak dapat driver</span>
                        @php
                            $tanpaDriver = $hariIni['tingkat_tanpa_driver'];
                            $warnaTanpaDriver = $tanpaDriver === null
                                ? 'secondary'
                                : ($tanpaDriver <= 3 ? 'success' : ($tanpaDriver <= 8 ? 'warning' : 'danger'));
                        @endphp
                        <span class="badge badge-light-{{ $warnaTanpaDriver }} fs-6">
                            {{ $tanpaDriver !== null ? $tanpaDriver . '%' : '—' }}
                        </span>
                    </div>

                    <div class="separator my-4"></div>

                    <div class="d-flex flex-stack mb-3">
                        <span class="text-muted fs-7">Dibatalkan</span>
                        <span class="fw-bold">{{ number_format($hariIni['dibatalkan'], 0, ',', '.') }}</span>
                    </div>

                    <div class="d-flex flex-stack">
                        <span class="text-muted fs-7">Tanpa driver</span>
                        <span class="fw-bold">{{ number_format($hariIni['tanpa_driver'], 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Order berjalan per status --}}
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Order berjalan</h3>
                </div>

                <div class="card-body">
                    @forelse ($statusBerjalan as $status => $jumlah)
                        @php $enum = \App\Domain\Ordering\Enums\OrderStatus::from($status); @endphp
                        <div class="d-flex flex-stack mb-4">
                            <span class="badge {{ $enum->badgeClass() }}">{{ $enum->label() }}</span>
                            <span class="fw-bold fs-5">{{ number_format($jumlah, 0, ',', '.') }}</span>
                        </div>
                    @empty
                        <div class="text-muted">Tidak ada order berjalan.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Per zona --}}
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Per zona, hari ini</h3>
                </div>

                <div class="card-body py-4">
                    @if (count($perZona) === 0)
                        <div class="text-muted">Belum ada order hari ini.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-row-dashed align-middle gy-2 mb-0">
                                <thead>
                                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                                        <th>Zona</th>
                                        <th class="text-end">Order</th>
                                        <th class="text-end">Tanpa driver</th>
                                        <th class="text-end">GMV</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($perZona as $baris)
                                        <tr>
                                            <td class="fw-semibold">{{ $baris['zona'] }}</td>
                                            <td class="text-end">{{ $baris['dibuat'] }}</td>
                                            <td class="text-end">
                                                @if ($baris['tanpa_driver'] > 0)
                                                    <span
                                                        class="badge badge-light-danger">{{ $baris['tanpa_driver'] }}</span>
                                                @else
                                                    <span class="text-muted">0</span>
                                                @endif
                                            </td>
                                            <td class="money">{{ $baris['gmv']->format(false) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tren 14 hari --}}
    <div class="card">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Tren 14 hari</h3>

            @php $hariTanpaData = collect($tren)->where('ada_data', false)->count(); @endphp

            @if ($hariTanpaData > 0)
                {{--
                    Hari tanpa data agregat DIBERI TAHU, bukan digambar sebagai nol.

                    Nol yang digambar sebagai titik akan terbaca sebagai "tidak ada
                    order hari itu" — kesimpulan yang sama sekali berbeda dari
                    "job agregasi tidak jalan". Yang kedua butuh diperbaiki
                    sekarang; yang pertama tidak.
                --}}
                <div class="card-toolbar">
                    <span class="badge badge-light-warning">
                        {{ $hariTanpaData }} hari belum teragregasi
                    </span>
                </div>
            @endif
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead>
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th>Tanggal</th>
                            <th class="text-end">Dibuat</th>
                            <th class="text-end">Selesai</th>
                            <th class="text-end">GMV</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $maksimum = max(1, collect($tren)->max('dibuat')); @endphp

                        @foreach ($tren as $hari)
                            <tr class="{{ $hari['ada_data'] ? '' : 'text-muted' }}">
                                <td class="fw-semibold">{{ $hari['label'] }}</td>
                                <td class="text-end">{{ $hari['dibuat'] }}</td>
                                <td class="text-end">{{ $hari['selesai'] }}</td>
                                <td class="money">
                                    {{ \App\Domain\Shared\ValueObjects\Money::of($hari['gmv'])->format(false) }}
                                </td>
                                <td style="width: 40%">
                                    @if ($hari['ada_data'])
                                        <div class="progress h-6px bg-light">
                                            <div class="progress-bar bg-primary"
                                                style="width: {{ round($hari['dibuat'] / $maksimum * 100) }}%"></div>
                                        </div>
                                    @else
                                        <span class="fs-8">belum teragregasi</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
