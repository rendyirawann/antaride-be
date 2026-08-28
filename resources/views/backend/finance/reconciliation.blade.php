@extends('backend.layout.app')

@section('title', 'Rekonsiliasi')
@section('page_heading', 'Rekonsiliasi Pembukuan')
@section('page_subheading', 'Membandingkan cache saldo dengan jumlah ledger sebenarnya')

@section('content')
    {{--
        Hasilnya ditampilkan sebagai SATU kesimpulan, bukan tabel angka.

        Halaman ini dibuka untuk menjawab satu pertanyaan: apakah pembukuannya
        masih benar. Jawabannya harus terbaca dalam dua detik; rincian hanya
        diperlukan kalau jawabannya tidak.
    --}}
    @if ($seimbang)
        <div class="card border border-success mb-6">
            <div class="card-body text-center py-10">
                <div class="fs-1 fw-bolder text-success mb-2">Pembukuan seimbang</div>
                <div class="text-muted fs-6">
                    Setiap saldo dompet cocok dengan akumulasi ledger-nya,
                    dan jumlah seluruh saldo tepat nol.
                </div>
            </div>
        </div>
    @else
        <div class="card border border-danger mb-6">
            <div class="card-body py-8">
                <div class="fs-2 fw-bolder text-danger mb-3">Ada yang tidak cocok</div>

                @if ($jumlahSeluruhSaldo->amount !== 0)
                    <div class="alert alert-danger">
                        <div class="fw-bold">
                            Jumlah seluruh saldo: {{ $jumlahSeluruhSaldo->format() }}
                        </div>
                        <div class="fs-7 mt-2">
                            {{--
                                Kenapa ini harus nol dijelaskan, bukan sekadar disebut
                                "tidak seimbang".

                                Konsekuensi aritmetika dari pembukuan berpasangan
                                tertutup: setiap peristiwa berjumlah nol, jadi jumlah
                                seluruh saldo adalah invarian — dan karena semua dompet
                                lahir di nol, invarian itu nol selamanya.
                            --}}
                            Angka ini seharusnya <span class="fw-bold">tepat nol</span>. Setiap peristiwa
                            keuangan di sistem ini berjumlah nol, jadi jumlah seluruh saldo tidak
                            pernah berubah — dan semua dompet lahir dengan saldo nol.
                            <br><br>
                            Angka yang bukan nol berarti ada uang yang muncul atau hilang tanpa
                            pasangan. Kemungkinan penyebabnya: UPDATE langsung lewat psql, atau
                            jalur penulisan baru yang melewati PostLedgerEntries.
                        </div>
                    </div>
                @endif

                @if (count($selisih) > 0)
                    <div class="fw-bold mb-3">
                        {{ count($selisih) }} dompet yang cache saldonya tidak cocok dengan ledger:
                    </div>

                    <div class="table-responsive">
                        <table class="table table-row-bordered align-middle gy-2 mb-0">
                            <thead class="bg-light">
                                <tr class="fw-bold text-muted fs-8 text-uppercase">
                                    <th class="ps-4">Dompet</th>
                                    <th>Pemilik</th>
                                    <th class="text-end">Cache</th>
                                    <th class="text-end">Menurut ledger</th>
                                    <th class="text-end pe-4">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($selisih as $baris)
                                    @php
                                        $delta = (int) $baris->cache_balance - (int) $baris->ledger_balance;
                                    @endphp
                                    <tr>
                                        <td class="ps-4">#{{ $baris->id }}</td>
                                        <td>{{ $baris->owner_type }} #{{ $baris->owner_id }}</td>
                                        <td class="money">
                                            {{ \App\Domain\Shared\ValueObjects\Money::of((int) $baris->cache_balance)->format() }}
                                        </td>
                                        <td class="money">
                                            {{ \App\Domain\Shared\ValueObjects\Money::of((int) $baris->ledger_balance)->format() }}
                                        </td>
                                        <td class="money pe-4 fw-bold text-danger">
                                            {{ \App\Domain\Shared\ValueObjects\Money::of($delta)->format() }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-warning mt-5 mb-0">
                        <div class="fw-bold">Yang benar adalah kolom "menurut ledger".</div>
                        <div class="fs-7 mt-1">
                            `wallets.balance` hanya cache; kebenarannya ada di
                            `wallet_transactions` yang append-only dan dijaga trigger
                            jumlah-nol. Memperbaiki cache-nya aman; mengubah ledger-nya
                            tidak boleh, dan trigger database akan menolaknya.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Bagaimana ini diperiksa</h3>
        </div>
        <div class="card-body">
            <div class="text-gray-700">
                <p>
                    Untuk setiap dompet, jumlah seluruh baris ledger-nya dihitung
                    (kredit dikurangi debit, mengabaikan <code>hold</code> dan
                    <code>release</code> yang hanya memindahkan antara saldo dan dana
                    tertahan di dompet yang sama), lalu dibandingkan dengan
                    <code>wallets.balance</code>.
                </p>
                <p class="mb-0">
                    Query-nya memindai seluruh ledger, jadi halaman ini
                    <span class="fw-bold">tidak dijalankan otomatis</span> — hanya saat
                    dibuka. Pada tabel yang sudah besar, menjalankannya di setiap
                    pemuatan panel akan membuat panel admin menjadi beban terbesar di
                    database.
                </p>
            </div>
        </div>
    </div>
@endsection
