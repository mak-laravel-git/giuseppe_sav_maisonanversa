<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'phone',
        'whatsapp',
        'email_hello',
        'email_press',
        'instagram_url',
        'return_address',
    ];

    public static function current(): self
    {
        $existing = static::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->create([
            'phone' => null,
            'whatsapp' => '32400000000',
            'email_hello' => 'hello@maisonanversa.com',
            'email_press' => 'press@maisonanversa.com',
            'instagram_url' => 'https://www.instagram.com/',
        ]);
    }

    /**
     * @return array{
     *     phone: string|null,
     *     whatsapp: string,
     *     emailHello: string,
     *     emailPress: string,
     *     instagramUrl: string,
     *     whatsappHref: string,
     *     phoneHref: string|null,
     *     emailHelloHref: string,
     *     emailPressHref: string
     * }
     */
    public function toShare(): array
    {
        return [
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'emailHello' => $this->email_hello,
            'emailPress' => $this->email_press,
            'instagramUrl' => $this->instagram_url,
            'whatsappHref' => 'https://wa.me/'.$this->whatsapp,
            'phoneHref' => filled($this->phone) ? 'tel:'.$this->phone : null,
            'emailHelloHref' => 'mailto:'.$this->email_hello,
            'emailPressHref' => 'mailto:'.$this->email_press,
        ];
    }
}
