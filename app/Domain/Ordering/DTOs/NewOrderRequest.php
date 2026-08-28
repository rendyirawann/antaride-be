<?php

declare(strict_types=1);

namespace App\Domain\Ordering\DTOs;

/**
 * Permintaan pembuatan order, sudah tervalidasi.
 *
 * Yang TIDAK ada di sini, dan tidak boleh pernah ada: nominal apa pun.
 *
 * Harga dibaca dari quote di Redis. Menambahkan satu field harga di sini —
 * bahkan hanya "untuk ditampilkan" atau "untuk dibandingkan" — membuka jalan
 * bagi kode berikutnya untuk memakainya, dan sejak saat itu harga bisa datang
 * dari client. Batasnya lebih mudah dijaga kalau fieldnya memang tidak ada.
 */
final readonly class NewOrderRequest
{
    /**
     * @param  array<int, array{address: string, lat: float, lng: float, note?: string|null, recipient_name?: string|null, recipient_phone?: string|null}>  $stops
     */
    public function __construct(
        public string $quoteId,
        public string $serviceCode,
        public string $paymentMethod,
        public string $pickupAddress,
        public ?string $destinationAddress = null,
        public ?string $pickupNote = null,
        public ?string $promoCode = null,
        public ?string $idempotencyKey = null,
        public array $stops = [],
    ) {}

    public function isWalletPayment(): bool
    {
        return $this->paymentMethod === 'wallet';
    }

    public function isCash(): bool
    {
        return $this->paymentMethod === 'cash';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        return new self(
            quoteId: (string) $data['quote_id'],
            serviceCode: (string) $data['service_code'],
            paymentMethod: (string) $data['payment_method'],
            pickupAddress: (string) $data['pickup_address'],
            destinationAddress: $data['destination_address'] ?? null,
            pickupNote: $data['pickup_note'] ?? null,
            promoCode: $data['promo_code'] ?? null,
            idempotencyKey: $idempotencyKey,
            stops: $data['stops'] ?? [],
        );
    }
}
