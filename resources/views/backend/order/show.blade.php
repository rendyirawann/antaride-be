@extends('backend.layout.app')

@section('title', 'Order ' . $order->order_number)
@section('page_heading', $order->order_number)
@section('page_subheading',
    $order->serviceType->name . ' — ' .
    \App\Domain\Shared\Support\BusinessClock::at($order->requested_at)->format('d M Y, H:i') . ' WIB')

@section('page_actions')
    <span class="badge {{ $order->status->badgeClass() }} fs-6">{{ $order->status->label() }}</span>
    <a href="{{ route('admin.orders.index') }}" class="btn btn-sm btn-light">Kembali</a>
@endsection

@section('content')
    @if ($order->needs_fare_review)
        <div class="alert alert-warning mb-6">
            <div class="fw-bold">Order ini menunggu review ongkos.</div>
            <div class="fs-7 mt-1">
                Jarak aktual {{ number_format((int) $order->actual_distance_m, 0, ',', '.') }} m
                versus estimasi {{ number_format((int) $order->distance_m, 0, ',', '.') }} m —
                menyimpang {{ $order->distanceVariancePercent() }}%.
                Pembagian uangnya ditunda sampai diperiksa.
            </div>
        </div>
    @endif

    <div class="row g-5">
        {{-- Kolom kiri: perjalanan dan uang --}}
        <div class="col-xl-8">

            {{-- Perjalanan --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Perjalanan</h3>
                </div>

                <div class="card-body">
                    <div class="d-flex mb-5">
                        <div class="me-4 pt-1">
                            <span class="bullet bullet-vertical bg-success h-40px"></span>
                        </div>
                        <div>
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Penjemputan</div>
                            <div class="fw-semibold">{{ $order->pickup_address }}</div>
                            <div class="text-muted fs-8">
                                {{ $order->pickup_lat }}, {{ $order->pickup_lng }}
                            </div>
                            @if ($order->pickup_note)
                                <div class="text-gray-700 fs-8 fst-italic mt-1">
                                    Catatan: {{ $order->pickup_note }}
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($order->dest_address)
                        <div class="d-flex">
                            <div class="me-4 pt-1">
                                <span class="bullet bullet-vertical bg-danger h-40px"></span>
                            </div>
                            <div>
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Tujuan</div>
                                <div class="fw-semibold">{{ $order->dest_address }}</div>
                                <div class="text-muted fs-8">
                                    {{ $order->dest_lat }}, {{ $order->dest_lng }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="separator my-5"></div>

                    <div class="row g-4">
                        <div class="col-6 col-md-3">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Jarak estimasi</div>
                            <div class="fw-bold">{{ number_format((int) $order->distance_m / 1000, 2, ',', '.') }} km
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Jarak aktual</div>
                            <div class="fw-bold">
                                {{ $order->actual_distance_m === null
                                    ? '—'
                                    : number_format((int) $order->actual_distance_m / 1000, 2, ',', '.') . ' km' }}
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Durasi estimasi</div>
                            <div class="fw-bold">{{ (int) ceil((int) $order->duration_s / 60) }} menit</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Surge</div>
                            <div class="fw-bold">{{ $order->surge_multiplier }}&times;</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Uang --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Uang</h3>
                    <div class="card-toolbar">
                        <span class="badge badge-light-secondary">
                            {{ $order->payment_method }} / {{ $order->payment_status }}
                        </span>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-row-dashed align-middle gy-2 mb-0">
                            <tbody>
                                @foreach ([
                                    'Tarif dasar' => $order->base_fare,
                                    'Biaya jarak' => $order->distance_fare,
                                    'Biaya waktu' => $order->time_fare,
                                    'Jam sibuk' => $order->surge_amount,
                                    'Penyesuaian tarif resmi' => $order->regulatory_adjustment,
                                    'Biaya aplikasi' => $order->platform_fee,
                                    'Biaya layanan' => $order->service_fee,
                                    'Diskon' => -(int) $order->discount_amount,
                                ] as $label => $nilai)
                                    @if ((int) $nilai !== 0)
                                        <tr>
                                            <td class="fw-semibold">{{ $label }}</td>
                                            <td class="money">
                                                {{ \App\Domain\Shared\ValueObjects\Money::of((int) $nilai)->format() }}
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach

                                <tr class="border-top border-2">
                                    <td class="fw-bolder fs-5">Dibayar penumpang</td>
                                    <td class="money fw-bolder fs-4">{{ $order->totalFare()->format() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="separator my-5"></div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="bg-light-success rounded p-4">
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Diterima driver</div>
                                <div class="fs-3 fw-bolder text-success">{{ $order->driverEarning()->format() }}</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="bg-light-primary rounded p-4">
                                <div class="text-muted fs-8 text-uppercase fw-semibold">Komisi platform</div>
                                <div class="fs-3 fw-bolder text-primary">{{ $order->commission()->format() }}</div>
                            </div>
                        </div>
                    </div>

                    @if ($order->pricingRule)
                        <div class="text-muted fs-8 mt-4">
                            {{--
                                Tarif yang dipakai ditautkan.

                                Ini yang menjawab sengketa ongkos: bukan tarif yang
                                berlaku sekarang, tapi tarif yang berlaku SAAT ITU.
                                `pricing_rule_id` disimpan di order justru untuk ini.
                            --}}
                            Dihitung dengan tarif #{{ $order->pricingRule->id }},
                            berlaku dari
                            {{ \App\Domain\Shared\Support\BusinessClock::at($order->pricingRule->effective_from)->format('d/m/Y H:i') }}
                            WIB.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Riwayat status --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Riwayat status</h3>
                </div>

                <div class="card-body">
                    <div class="timeline">
                        @foreach ($order->statusLogs as $log)
                            <div class="d-flex mb-5">
                                <div class="me-4" style="min-width: 130px">
                                    <div class="fw-bold fs-7">
                                        {{ \App\Domain\Shared\Support\BusinessClock::at($log->created_at)->format('d/m H:i:s') }}
                                    </div>
                                    <div class="text-muted fs-9">WIB</div>
                                </div>

                                <div>
                                    <div>
                                        @if ($log->from_status)
                                            <span class="text-muted fs-8">{{ $log->from_status }} &rarr;</span>
                                        @endif
                                        <span class="fw-bold">{{ $log->to_status }}</span>
                                    </div>

                                    <div class="text-muted fs-8">
                                        oleh {{ $log->actor_type }}{{ $log->actor_id ? ' #' . $log->actor_id : '' }}

                                        @if ($log->lat !== null)
                                            {{--
                                                Koordinat transisi ditampilkan.

                                                Ini yang menjawab "apakah driver benar-benar
                                                ada di titik jemput saat menekan tiba" —
                                                pertanyaan yang paling sering muncul dalam
                                                sengketa, dan yang tanpa data ini tidak punya
                                                jawaban selain keterangan dua pihak.
                                            --}}
                                            <span class="ms-2">
                                                &middot; {{ $log->lat }}, {{ $log->lng }}
                                            </span>
                                        @endif
                                    </div>

                                    @if ($log->note)
                                        <div class="text-gray-700 fs-8 fst-italic mt-1">{{ $log->note }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Penawaran ke driver --}}
            <div class="card">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Penawaran ke driver</h3>
                    <div class="card-toolbar">
                        <span class="text-muted fs-8">{{ $penawaran->count() }} penawaran</span>
                    </div>
                </div>

                <div class="card-body p-0">
                    @if ($penawaran->isEmpty())
                        <div class="p-6 text-muted">
                            Belum ada penawaran yang dikirim.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle gy-2 mb-0">
                                <thead class="bg-light">
                                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                                        <th class="ps-6">Gelombang</th>
                                        <th>Driver</th>
                                        <th class="text-end">Jarak ke jemput</th>
                                        <th class="text-end">Skor</th>
                                        <th>Jawaban</th>
                                        <th class="pe-6">Rincian skor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($penawaran as $tawar)
                                        <tr>
                                            <td class="ps-6">{{ $tawar->wave }}</td>
                                            <td>#{{ $tawar->driver_id }}</td>
                                            <td class="text-end">
                                                {{ number_format((int) $tawar->distance_to_pickup_m, 0, ',', '.') }} m
                                            </td>
                                            <td class="text-end fw-bold">{{ $tawar->score }}</td>
                                            <td>
                                                @php
                                                    $warnaJawaban = match ($tawar->response) {
                                                        'accepted' => 'success',
                                                        'rejected' => 'danger',
                                                        'timeout' => 'warning',
                                                        'lost' => 'secondary',
                                                        'cancelled' => 'secondary',
                                                        default => 'light',
                                                    };
                                                @endphp
                                                <span class="badge badge-light-{{ $warnaJawaban }}">
                                                    {{ $tawar->response }}
                                                </span>
                                            </td>
                                            <td class="pe-6 fs-9 text-muted">
                                                {{--
                                                    Rincian skor ditampilkan APA ADANYA.

                                                    Ini satu-satunya tempat di seluruh sistem
                                                    yang menjawab "kenapa driver A yang dapat,
                                                    bukan B" dengan angka. Menyederhanakannya
                                                    menghilangkan gunanya.
                                                --}}
                                                @if ($tawar->score_breakdown)
                                                    @foreach ($tawar->score_breakdown as $faktor => $nilai)
                                                        @if (! str_starts_with($faktor, 'raw_'))
                                                            <span class="me-2">
                                                                {{ $faktor }}:
                                                                {{ number_format((float) $nilai, 3) }}
                                                            </span>
                                                        @endif
                                                    @endforeach
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Kolom kanan: pihak dan tindakan --}}
        <div class="col-xl-4">

            {{-- Penumpang --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Penumpang</h3>
                </div>
                <div class="card-body">
                    <div class="fw-bold fs-5">{{ $order->user->name }}</div>
                    <div class="text-muted fs-7">
                        {{--
                            Nomor HP DISAMARKAN secara bawaan.

                            Staf CS yang menjawab telepon perlu memastikan orangnya,
                            bukan menyalin nomornya. Nomor penuh dibuka lewat tombol
                            terpisah yang pembukaannya dicatat — dan sebagian besar
                            percakapan tidak membutuhkannya.
                        --}}
                        {{ \App\Domain\Identity\Support\PhoneNumber::masked((string) $order->user->phone) }}
                    </div>

                    @if ($order->pickup_code)
                        <div class="separator my-4"></div>
                        <div class="text-muted fs-8 text-uppercase fw-semibold">Kode jemput</div>
                        <div class="fw-bolder fs-2 text-primary">{{ $order->pickup_code }}</div>
                        <div class="text-muted fs-9">
                            Jangan dibacakan ke driver. Kode ini yang membuktikan
                            penumpangnya benar.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Driver --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Driver</h3>
                </div>
                <div class="card-body">
                    @if ($order->driver)
                        <div class="fw-bold fs-5">{{ $order->driver->full_name }}</div>
                        <div class="text-muted fs-7">
                            Rating {{ $order->driver->rating_avg }}
                            ({{ $order->driver->rating_count }} penilaian)
                        </div>

                        @php $kendaraan = $order->driver->vehicles->firstWhere('is_active', true); @endphp

                        @if ($kendaraan)
                            <div class="mt-3">
                                <span class="badge badge-light-dark fs-6">{{ $kendaraan->plate_number }}</span>
                                <div class="text-muted fs-8 mt-1">
                                    {{ $kendaraan->brand }} {{ $kendaraan->model }} — {{ $kendaraan->color }}
                                </div>
                            </div>
                        @endif

                        <div class="mt-4">
                            <a href="{{ route('admin.drivers.show', $order->driver->uuid) }}"
                                class="btn btn-sm btn-light-primary w-100">Buka profil driver</a>
                        </div>
                    @else
                        <div class="text-muted">Belum ada driver.</div>
                    @endif
                </div>
            </div>

            {{-- Tindakan --}}
            @canany(['orders.cancel', 'orders.force_assign', 'orders.intervene'])
                <div class="card">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Intervensi</h3>
                    </div>

                    <div class="card-body">
                        <div class="text-muted fs-8 mb-4">
                            Setiap tindakan di sini dicatat dengan nama Anda dan alasannya.
                        </div>

                        @can('orders.intervene')
                            @if ($order->status === \App\Domain\Ordering\Enums\OrderStatus::NoDriver)
                                <form method="POST" action="{{ route('admin.orders.retry-matching', $order->uuid) }}"
                                    class="mb-4">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-light-primary w-100">
                                        Cari driver lagi
                                    </button>
                                    <div class="form-text">
                                        Nomor ordernya tetap sama, riwayat percobaannya tidak hilang.
                                    </div>
                                </form>
                            @endif
                        @endcan

                        @can('orders.cancel')
                            @if ($order->status->isCancellable())
                                <button type="button" class="btn btn-sm btn-light-danger w-100"
                                    data-bs-toggle="modal" data-bs-target="#modal-batalkan">
                                    Batalkan order
                                </button>
                            @endif
                        @endcan
                    </div>
                </div>
            @endcanany
        </div>
    </div>

    {{-- Modal pembatalan --}}
    @can('orders.cancel')
        <div class="modal fade" id="modal-batalkan" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.orders.cancel', $order->uuid) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">Batalkan {{ $order->order_number }}?</h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            {{--
                                Dampaknya disebutkan dengan ANGKA.

                                Blueprint admin bagian 12: konfirmasi yang berbunyi
                                "Anda yakin?" akan ditekan tanpa dibaca setelah minggu
                                kedua. Yang membuatnya berfungsi adalah menyebut apa
                                yang akan terjadi.
                            --}}
                            <div class="alert alert-warning">
                                @if ($order->payment_status === 'held')
                                    Dana {{ $order->totalFare()->format() }} yang tertahan akan
                                    dilepas kembali ke saldo penumpang.
                                @else
                                    Order ini pembayarannya {{ $order->payment_method }}, tidak ada
                                    dana yang perlu dilepas.
                                @endif

                                @if ($order->driver)
                                    <div class="mt-2">
                                        Driver {{ $order->driver->full_name }} akan dikembalikan ke
                                        antrean dan bisa menerima order lain.
                                    </div>
                                @endif
                            </div>

                            <label class="form-label fw-semibold">Alasan pembatalan</label>
                            <textarea name="note" class="form-control" rows="3" required minlength="20"
                                placeholder="Contoh: penumpang menelepon CS, driver tidak bergerak 15 menit"></textarea>
                            <div class="form-text">
                                Minimal 20 karakter. Akan terbaca di audit log dan riwayat order.
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Batalkan order</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
