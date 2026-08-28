@extends('backend.layout.app')

@section('title', 'Buku Besar')
@section('page_heading', 'Buku Besar')
@section('page_subheading', 'Append only — tidak ada UPDATE, tidak ada DELETE, ditegakkan trigger database')

@section('page_actions')
    @can('finance.reconcile')
        <a href="{{ route('admin.finance.reconciliation') }}" class="btn btn-sm btn-light-primary">
            Jalankan rekonsiliasi
        </a>
    @endcan
@endsection

@section('content')
    {{-- Saldo akun platform --}}
    <div class="card mb-6">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Saldo akun platform</h3>
        </div>

        <div class="card-body">
            <div class="row g-4">
                @foreach ($saldoPlatform as $akun)
                    <div class="col-sm-6 col-lg-4 col-xl">
                        <div class="border rounded p-4 h-100">
                            <div class="text-muted fs-9 text-uppercase fw-semibold">{{ $akun['nama'] }}</div>

                            <div class="fs-4 fw-bolder mt-1 {{ $akun['saldo']->isNegative() ? 'text-danger' : '' }}">
                                {{ $akun['saldo']->format() }}
                            </div>

                            @if ($akun['saldo']->isNegative() && $akun['wajar_negatif'])
                                {{--
                                    Saldo minus di akun kontra DIJELASKAN.

                                    Tanpa penjelasan, staf finance yang membuka halaman ini
                                    akan melihat minus ratusan juta dan menyimpulkan ada
                                    yang rusak. Yang benar: angka minus di akun settlement
                                    berarti sebanyak itu dana pengguna yang dititipkan
                                    platform — dan itu keadaan yang sehat.
                                --}}
                                <div class="text-muted fs-9 mt-1">
                                    Minus itu wajar — ini akun kontra
                                </div>
                            @elseif ($akun['saldo']->isNegative())
                                <div class="text-danger fs-9 mt-1 fw-bold">
                                    Minus di akun ini TIDAK wajar
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="alert alert-light-primary mt-5 mb-0 fs-8">
                Pembukuan ini tertutup: jumlah SELURUH saldo di sistem selalu nol.
                Saldo positif seseorang selalu diimbangi saldo negatif di akun kontra.
            </div>
        </div>
    </div>

    {{-- Filter --}}
    <div class="card mb-6">
        <div class="card-body py-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Jenis</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">Semua jenis</option>
                        @foreach ([
                            'topup', 'ride_payment', 'ride_earning', 'commission',
                            'hold', 'release', 'refund', 'withdrawal', 'bonus',
                            'incentive', 'penalty', 'adjustment', 'referral',
                            'settlement', 'reversal', 'cancellation_fee',
                        ] as $jenis)
                            <option value="{{ $jenis }}" @selected(request('type') === $jenis)>
                                {{ $jenis }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">
                        Group UUID (satu peristiwa)
                    </label>
                    {{--
                        Pencarian per group_uuid adalah yang paling berguna di halaman
                        ini.

                        Satu peristiwa keuangan menghasilkan beberapa baris yang harus
                        berjumlah nol. Melihatnya satu per satu tidak menjelaskan apa
                        pun; yang menjelaskan adalah satu KELOMPOK utuh.
                    --}}
                    <input type="text" name="group_uuid" value="{{ request('group_uuid') }}"
                        class="form-control form-control-sm" placeholder="Tempel group_uuid di sini" />
                </div>

                <div class="col-md-2">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Dompet</label>
                    <input type="number" name="wallet_id" value="{{ request('wallet_id') }}"
                        class="form-control form-control-sm" placeholder="ID" />
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    @if (request()->hasAny(['type', 'group_uuid', 'wallet_id']))
                        <a href="{{ route('admin.finance.ledger') }}" class="btn btn-sm btn-light">Reset</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- Baris ledger --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-2 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Waktu</th>
                            <th>Dompet</th>
                            <th>Jenis</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-end">Saldo sesudah</th>
                            <th>Keterangan</th>
                            <th class="pe-6">Group</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($transaksi as $t)
                            <tr>
                                <td class="ps-6 fs-8 text-muted">
                                    {{ \App\Domain\Shared\Support\BusinessClock::at($t->created_at)->format('d/m H:i:s') }}
                                </td>

                                <td class="fs-8">
                                    #{{ $t->wallet_id }}
                                    <div class="text-muted fs-9">
                                        {{ $t->wallet?->owner_type }} #{{ $t->wallet?->owner_id }}
                                    </div>
                                </td>

                                <td>
                                    <span class="badge badge-light-secondary fs-9">{{ $t->type }}</span>
                                </td>

                                <td class="money fw-bold {{ $t->direction === 'credit' ? 'text-success' : 'text-danger' }}">
                                    {{--
                                        Tanda ditampilkan dari kolom `direction`, bukan dari
                                        tanda pada `amount`.

                                        `amount` selalu positif — itu ditegakkan CHECK
                                        constraint. Mencampur tanda ke nominal adalah cara
                                        pasti menghasilkan pembukuan yang tidak bisa
                                        dijumlahkan.
                                    --}}
                                    {{ $t->direction === 'credit' ? '+' : '−' }}{{ $t->amount()->format(false) }}
                                </td>

                                <td class="money fs-8 text-muted">
                                    {{ \App\Domain\Shared\ValueObjects\Money::of((int) $t->balance_after)->format(false) }}
                                </td>

                                <td class="fs-8">{{ $t->description }}</td>

                                <td class="pe-6">
                                    {{--
                                        Group UUID bisa diklik untuk melihat seluruh
                                        pasangannya. Ini yang membuat "apakah peristiwa ini
                                        berjumlah nol" bisa diperiksa dalam satu klik.
                                    --}}
                                    <a href="{{ route('admin.finance.ledger', ['group_uuid' => $t->group_uuid]) }}"
                                        class="fs-9 text-muted" title="Lihat seluruh pasangannya">
                                        {{ Str::limit((string) $t->group_uuid, 8, '…') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-10">
                                    Tidak ada transaksi yang cocok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($transaksi->hasPages())
            <div class="card-footer">{{ $transaksi->links() }}</div>
        @endif
    </div>

    @if (request('group_uuid'))
        @php
            $jumlahKelompok = $transaksi->reject(fn ($t) => in_array($t->type, ['hold', 'release'], true))
                ->sum(fn ($t) => $t->direction === 'credit' ? (int) $t->amount : -(int) $t->amount);
        @endphp

        {{--
            Kalau difilter per group_uuid, jumlahnya ditampilkan.

            Ini pemeriksaan yang paling langsung: satu peristiwa keuangan HARUS
            berjumlah nol. Trigger database menegakkannya saat COMMIT, tapi
            melihatnya sendiri adalah cara tercepat memastikan tidak ada yang
            aneh — dan cara mengajari staf baru bagaimana pembukuan ini bekerja.
        --}}
        <div class="card mt-6 border border-{{ $jumlahKelompok === 0 ? 'success' : 'danger' }}">
            <div class="card-body py-6 text-center">
                <div class="text-muted fs-8 text-uppercase fw-semibold">
                    Jumlah kelompok ini (tanpa hold/release)
                </div>
                <div class="fs-1 fw-bolder text-{{ $jumlahKelompok === 0 ? 'success' : 'danger' }}">
                    {{ \App\Domain\Shared\ValueObjects\Money::of($jumlahKelompok)->format() }}
                </div>
                <div class="text-muted fs-7 mt-1">
                    @if ($jumlahKelompok === 0)
                        Seimbang, seperti seharusnya.
                    @else
                        TIDAK seimbang. Ini seharusnya tidak mungkin — trigger database
                        menolak peristiwa yang tidak berjumlah nol.
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection
