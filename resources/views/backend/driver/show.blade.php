@extends('backend.layout.app')

@section('title', $driver->full_name)
@section('page_heading', $driver->full_name)
@section('page_subheading', 'Driver sejak ' .
    ($driver->joined_at ? \App\Domain\Shared\Support\BusinessClock::at($driver->joined_at)->format('d M Y') : '—'))

@section('page_actions')
    <span class="badge {{ $driver->status->badgeClass() }} fs-6">{{ $driver->status->label() }}</span>
    <a href="{{ route('admin.drivers.index') }}" class="btn btn-sm btn-light">Kembali</a>
@endsection

@section('content')
    @if ($orderBerjalan)
        <div class="alert alert-info mb-6 d-flex flex-stack">
            <div>
                <span class="fw-bold">Sedang mengantar order {{ $orderBerjalan->order_number }}</span>
                <span class="text-muted ms-2">({{ $orderBerjalan->status }})</span>
            </div>
            <a href="{{ route('admin.orders.show', $orderBerjalan->uuid) }}"
                class="btn btn-sm btn-light-info">Buka order</a>
        </div>
    @endif

    <div class="row g-5">
        <div class="col-xl-8">

            {{-- Kinerja 30 hari --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Kinerja 30 hari</h3>
                </div>

                <div class="card-body">
                    <div class="row g-5 mb-6">
                        <div class="col-sm-4">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Order selesai</div>
                            <div class="fs-2 fw-bolder">{{ $kinerja['order_selesai'] }}</div>
                        </div>
                        <div class="col-sm-4">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Pendapatan</div>
                            <div class="fs-2 fw-bolder">{{ $kinerja['pendapatan']->format() }}</div>
                        </div>
                        <div class="col-sm-4">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Dibatalkan sendiri</div>
                            <div class="fs-2 fw-bolder {{ $kinerja['dibatalkan_driver'] > 3 ? 'text-danger' : '' }}">
                                {{ $kinerja['dibatalkan_driver'] }}
                            </div>
                        </div>
                    </div>

                    <div class="separator mb-6"></div>

                    <div class="text-muted fs-7 mb-4">
                        Dari {{ $kinerja['ditawari'] }} penawaran yang dikirim ke dia:
                    </div>

                    <div class="row g-4">
                        @foreach ([
                            ['Diterima', $kinerja['diterima'], 'success'],
                            ['Ditolak', $kinerja['ditolak'], 'warning'],
                            ['Diabaikan', $kinerja['diabaikan'], 'danger'],
                            ['Kalah balapan', $kinerja['kalah_balapan'], 'secondary'],
                        ] as [$label, $nilai, $warna])
                            <div class="col-6 col-md-3">
                                <div class="bg-light-{{ $warna }} rounded p-3">
                                    <div class="text-muted fs-9 text-uppercase fw-semibold">{{ $label }}</div>
                                    <div class="fs-4 fw-bolder text-{{ $warna }}">{{ $nilai }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{--
                        Acceptance rate BERSIH dijelaskan, bukan hanya ditampilkan.

                        Angka yang tersimpan di kolom `acceptance_rate` bisa berbeda
                        dari yang dihitung di sini, dan bedanya penting: driver yang
                        kalah adu cepat tidak melakukan kesalahan apa pun. Tanpa
                        penjelasan, staf ops akan menilai driver dari angka yang
                        menghukumnya karena rajin.
                    --}}
                    <div class="alert alert-light-primary mt-6 mb-0">
                        <div class="fw-bold">
                            Acceptance rate bersih:
                            {{ $kinerja['acceptance_bersih'] !== null ? $kinerja['acceptance_bersih'] . '%' : '—' }}
                        </div>
                        <div class="fs-8 mt-1">
                            Dihitung tanpa memasukkan penawaran yang dia kalah adu cepat.
                            Driver yang kalah balapan tidak melakukan kesalahan, dan
                            memasukkannya akan menghukum driver yang paling aktif.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Sesi kerja --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Sesi kerja 7 hari terakhir</h3>
                </div>

                <div class="card-body p-0">
                    @if ($sesi->isEmpty())
                        <div class="p-6 text-muted">Belum ada sesi kerja dalam 7 hari terakhir.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle gy-2 mb-0">
                                <thead class="bg-light">
                                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                                        <th class="ps-6">Mulai</th>
                                        <th>Selesai</th>
                                        <th class="text-end">Lama online</th>
                                        <th class="text-end pe-6">Order</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sesi as $s)
                                        <tr>
                                            <td class="ps-6">
                                                {{ \App\Domain\Shared\Support\BusinessClock::at($s->started_at)->format('d/m H:i') }}
                                            </td>
                                            <td>
                                                @if ($s->ended_at)
                                                    {{ \App\Domain\Shared\Support\BusinessClock::at($s->ended_at)->format('d/m H:i') }}
                                                @else
                                                    <span class="badge badge-light-success">masih online</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                {{ $s->online_seconds > 0
                                                    ? floor($s->online_seconds / 3600) . 'j ' . floor(($s->online_seconds % 3600) / 60) . 'm'
                                                    : '—' }}
                                            </td>
                                            <td class="text-end pe-6">{{ $s->orders_completed }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Dokumen --}}
            <div class="card">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Dokumen</h3>
                    @can('drivers.verify_document')
                        <div class="card-toolbar">
                            <a href="{{ route('admin.drivers.verify', $driver->uuid) }}"
                                class="btn btn-sm btn-light-primary">Buka verifikasi</a>
                        </div>
                    @endcan
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-row-bordered align-middle gy-2 mb-0">
                            <tbody>
                                @forelse ($driver->documents as $dok)
                                    <tr>
                                        <td class="ps-6 fw-semibold">{{ $dok->label() }}</td>
                                        <td>
                                            @php
                                                $warnaDok = match ($dok->status) {
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'pending' => 'warning',
                                                    default => 'secondary',
                                                };
                                            @endphp
                                            <span class="badge badge-light-{{ $warnaDok }}">{{ $dok->status }}</span>
                                        </td>
                                        <td class="text-muted fs-8">
                                            @if ($dok->expires_at)
                                                berlaku sampai
                                                {{ $dok->expires_at->format('d/m/Y') }}
                                                @if ($dok->expires_at->isPast())
                                                    <span class="badge badge-danger ms-1">KADALUARSA</span>
                                                @endif
                                            @else
                                                tanpa masa berlaku
                                            @endif
                                        </td>
                                        <td class="pe-6 text-end">
                                            @can('kyc.view_masked')
                                                <a href="{{ route('admin.documents.file', $dok->id) }}"
                                                    target="_blank" class="btn btn-sm btn-light">Lihat berkas</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-8">
                                            Belum ada dokumen yang diunggah.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Kolom kanan --}}
        <div class="col-xl-4">

            {{-- Identitas --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Identitas</h3>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-stack mb-3">
                        <span class="text-muted fs-7">Nomor HP</span>
                        <span class="fw-semibold">
                            {{ $driver->user
                                ? \App\Domain\Identity\Support\PhoneNumber::forDisplay((string) $driver->user->phone)
                                : '—' }}
                        </span>
                    </div>

                    <div class="d-flex flex-stack mb-3">
                        <span class="text-muted fs-7">NIK</span>
                        <span class="fw-semibold">
                            {{--
                                NIK selalu tersamarkan di halaman ini.

                                Nomor penuh hanya bisa dibuka role dengan
                                `kyc.view_full`, lewat halaman verifikasi, dan
                                pembukaannya dicatat. Halaman profil dibuka jauh lebih
                                sering daripada yang membutuhkan NIK penuh.
                            --}}
                            {{ $driver->nikMasked() ?? '—' }}
                        </span>
                    </div>

                    <div class="d-flex flex-stack mb-3">
                        <span class="text-muted fs-7">Kota</span>
                        <span class="fw-semibold">{{ $driver->city ?? '—' }}</span>
                    </div>

                    <div class="d-flex flex-stack">
                        <span class="text-muted fs-7">Kontak darurat</span>
                        <span class="fw-semibold text-end">
                            {{ $driver->emergency_contact_name ?? '—' }}
                            @if ($driver->emergency_contact_phone)
                                <br>
                                <span class="text-muted fs-8">
                                    {{ \App\Domain\Identity\Support\PhoneNumber::masked((string) $driver->emergency_contact_phone) }}
                                </span>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Dompet --}}
            <div class="card mb-5">
                <div class="card-header min-h-45px">
                    <h3 class="card-title fw-bold">Dompet</h3>
                </div>
                <div class="card-body">
                    <div class="fs-2 fw-bolder {{ $dompet->balance < 0 ? 'text-danger' : 'text-gray-900' }}">
                        {{ $dompet->balance()->format() }}
                    </div>

                    @if ($dompet->balance < 0)
                        {{--
                            Saldo minus DIJELASKAN, bukan hanya ditampilkan merah.

                            Saldo driver boleh negatif: pada order tunai, komisi
                            platform dipotong dari saldonya setelah order selesai, dan
                            angka minus itu utang yang nyata. Tanpa penjelasan, staf
                            ops akan menyimpulkan ada yang rusak.
                        --}}
                        <div class="alert alert-warning mt-3 mb-0 fs-8">
                            Saldo minus berarti driver ini berutang komisi order tunai.
                            Dia otomatis tidak bisa menerima order tunai lagi sampai melunasi.
                        </div>
                    @endif

                    @php
                        $ambangDeposit = (int) config('antaride.wallet.driver_cash_deposit_minimum', 20000);
                    @endphp

                    <div class="separator my-4"></div>

                    <div class="d-flex flex-stack">
                        <span class="text-muted fs-7">Bisa terima order tunai</span>
                        @if ($dompet->balance >= $ambangDeposit)
                            <span class="badge badge-light-success">ya</span>
                        @else
                            <span class="badge badge-light-danger">tidak</span>
                        @endif
                    </div>

                    <div class="text-muted fs-9 mt-1">
                        Ambang deposit
                        {{ \App\Domain\Shared\ValueObjects\Money::of($ambangDeposit)->format() }}
                    </div>
                </div>
            </div>

            {{-- Tindakan --}}
            @can('drivers.suspend')
                <div class="card">
                    <div class="card-header min-h-45px">
                        <h3 class="card-title fw-bold">Tindakan</h3>
                    </div>
                    <div class="card-body">
                        @if ($driver->status->value === 'suspended')
                            <button type="button" class="btn btn-sm btn-light-success w-100"
                                data-bs-toggle="modal" data-bs-target="#modal-aktifkan">
                                Aktifkan kembali
                            </button>
                        @elseif ($driver->status->value === 'active')
                            <button type="button" class="btn btn-sm btn-light-danger w-100"
                                data-bs-toggle="modal" data-bs-target="#modal-tangguhkan">
                                Tangguhkan driver
                            </button>
                        @else
                            <div class="text-muted fs-8">
                                Status {{ $driver->status->value }} tidak bisa diubah dari halaman ini.
                            </div>
                        @endif
                    </div>
                </div>
            @endcan
        </div>
    </div>

    {{-- Modal tangguhkan --}}
    @can('drivers.suspend')
        <div class="modal fade" id="modal-tangguhkan" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.drivers.suspend', $driver->uuid) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">Tangguhkan {{ $driver->full_name }}?</h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                Driver akan <span class="fw-bold">dipaksa offline sekarang</span> dan
                                berhenti mendapat penawaran.

                                @if ($orderBerjalan)
                                    <div class="mt-2 fw-bold">
                                        Dia sedang mengantar order {{ $orderBerjalan->order_number }}.
                                        Order itu perlu ditangani terpisah.
                                    </div>
                                @endif
                            </div>

                            <label class="form-label fw-semibold">Alasan penangguhan</label>
                            <textarea name="reason" class="form-control" rows="3" required minlength="20"
                                placeholder="Contoh: tiga laporan penumpang soal perilaku dalam seminggu"></textarea>
                            <div class="form-text">Minimal 20 karakter. Masuk audit log.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Tangguhkan</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="modal-aktifkan" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.drivers.reinstate', $driver->uuid) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">Aktifkan kembali {{ $driver->full_name }}?</h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            @if ($driver->rejection_note)
                                <div class="alert alert-light-warning">
                                    <div class="fw-bold fs-8 text-uppercase">Alasan penangguhan sebelumnya</div>
                                    <div class="fs-7 mt-1">{{ $driver->rejection_note }}</div>
                                </div>
                            @endif

                            <label class="form-label fw-semibold">Alasan mengaktifkan kembali</label>
                            <textarea name="reason" class="form-control" rows="3" required minlength="20"
                                placeholder="Contoh: sudah diklarifikasi, laporan tidak terbukti"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-success">Aktifkan</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
