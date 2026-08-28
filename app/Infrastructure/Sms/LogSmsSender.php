<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Shared\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim SMS yang hanya menulis ke log. Untuk pengembangan dan test.
 *
 * ============================================================================
 *  KENAPA INI BUKAN "TODO" YANG DIBIARKAN KOSONG
 * ============================================================================
 *  Alternatifnya adalah membiarkan `SmsSender` tanpa implementasi sampai
 *  provider dipilih. Yang terjadi kalau begitu: seluruh alur autentikasi tidak
 *  bisa dijalankan maupun diuji sampai ada kontrak dengan penyedia SMS —
 *  keputusan bisnis yang bisa memakan berminggu-minggu.
 *
 *  Dengan implementasi ini, alurnya lengkap sejak hari pertama, dan mengganti ke
 *  provider sungguhan cukup menukar satu binding di DomainServiceProvider.
 * ============================================================================
 *
 *  KODENYA TIDAK PERNAH DITULIS PENUH KE LOG DI PRODUKSI.
 *
 *  Log aplikasi dibaca lebih banyak orang daripada yang biasanya disadari: tim
 *  ops, alat pemantauan pihak ketiga, dan siapa pun yang punya akses server.
 *  Kode OTP di log berarti pengambilalihan akun tanpa perlu menyentuh HP
 *  siapa pun. Karena itu di produksi yang tercatat hanya bahwa kode dikirim,
 *  bukan kodenya.
 */
class LogSmsSender implements SmsSender
{
    public function sendOtp(string $phone, string $code): bool
    {
        Log::channel('sms')->info('OTP dikirim', [
            'phone' => PhoneNumber::masked($phone),
            'code' => app()->isProduction() ? '[disembunyikan]' : $code,
        ]);

        return true;
    }

    public function send(string $phone, string $message): bool
    {
        Log::channel('sms')->info('SMS dikirim', [
            'phone' => PhoneNumber::masked($phone),

            // Isi pesan dipotong. Pemberitahuan bisa memuat alamat penjemputan
            // dan nama driver, dan log bukan tempatnya.
            'message_preview' => mb_substr($message, 0, 40).(mb_strlen($message) > 40 ? '...' : ''),
            'message_length' => mb_strlen($message),
        ]);

        return true;
    }
}
