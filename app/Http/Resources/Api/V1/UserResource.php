<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil pengguna, untuk pengguna itu sendiri.
 *
 * ============================================================================
 *  YANG SENGAJA TIDAK ADA DI SINI
 * ============================================================================
 *    id             auto-increment tidak pernah bocor ke publik. Yang dipakai
 *                   di API dan URL adalah uuid. Membocorkan id berurut memberi
 *                   tahu siapa pun berapa jumlah pengguna dan seberapa cepat
 *                   pertumbuhannya — informasi yang tidak perlu dibagikan.
 *    password       tidak pernah, dalam bentuk apa pun.
 *    referred_by    id pengguna lain. Kalau ikut terkirim, satu orang bisa
 *                   memetakan jaringan referral orang lain.
 *
 *  Resource INI hanya untuk pemiliknya sendiri. Bentuk yang dilihat driver
 *  tentang penumpangnya berbeda dan jauh lebih sedikit — lihat
 *  OrderPassengerResource.
 * ============================================================================
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'name' => (string) $this->name,

            // Nomor sendiri ditampilkan penuh dalam bentuk lokal yang enak
            // dibaca. Ini halaman profilnya sendiri; menyamarkan nomornya di
            // sini hanya membuat dia tidak bisa memastikan nomornya benar.
            'phone' => PhoneNumber::forDisplay((string) $this->phone),

            'email' => $this->email,
            'photo_url' => $this->photo_url,
            'gender' => $this->gender?->value,
            'birth_date' => $this->birth_date?->toDateString(),

            'status' => $this->status->value,
            'phone_verified' => $this->phone_verified_at !== null,

            'referral_code' => (string) $this->referral_code,

            /*
             * Penanda apakah profilnya masih perlu dilengkapi.
             *
             * Dihitung di sini, bukan dibiarkan aplikasi menyimpulkannya dari
             * nama yang masih berbentuk "Pengguna 7890". Kalau disimpulkan
             * aplikasi, orang yang benar-benar menamai dirinya begitu akan
             * terus-menerus diminta melengkapi profil.
             */
            'profile_complete' => $this->email !== null
                && ! str_starts_with((string) $this->name, 'Pengguna '),

            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
