<?php

use App\Models\Product;
use App\Models\ProductSection;
use App\Models\ProductSectionItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Force the Founding product service section onto the approved copy, even when
 * production drifted back to "Uw"/"30 dagen"/"Benelux"/"Wereldwijde" wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        $product = Product::query()->where('slug', Product::FOUNDING_SLUG)->first();

        if ($product === null) {
            return;
        }

        $section = ProductSection::query()
            ->where('product_id', $product->id)
            ->where('key', 'service')
            ->first();

        if ($section === null) {
            return;
        }

        $section->fill([
            'intro' => 'Elke zending is verzekerd en volgbaar. Retour binnen 14 dagen na levering.',
        ])->saveQuietly();

        $this->upsertTranslations($section, [
            'intro' => [
                'en' => 'Every shipment is insured and trackable. Returns within 14 days of delivery.',
                'fr' => 'Chaque envoi est assuré et suivi. Retours sous 14 jours après livraison.',
            ],
        ]);

        $items = [
            [
                'match' => fn (ProductSectionItem $item): bool => $item->title === 'Verzending'
                    || str_contains((string) $item->body, 'Benelux')
                    || str_contains((string) $item->body, 'Wereldwijd')
                    || str_contains((string) $item->title, 'Wereldwijde'),
                'title' => 'Verzending',
                'body' => 'Levering binnen de EU is inbegrepen. Elke zending is verzekerd en traceerbaar.',
                'locales' => [
                    'title' => ['en' => 'Shipping', 'fr' => 'Livraison'],
                    'body' => [
                        'en' => 'Shipping within the EU is included. Every shipment is insured and trackable.',
                        'fr' => 'La livraison est incluse dans l’UE. Chaque envoi est assuré et suivi.',
                    ],
                ],
            ],
            [
                'match' => fn (ProductSectionItem $item): bool => str_contains((string) $item->title, '30')
                    || str_contains((string) $item->title, '14 dagen')
                    || str_contains((string) $item->title, 'retour'),
                'title' => '14 dagen retour',
                'body' => 'Je hebt 14 dagen na levering om je aankoop te herroepen, zonder opgave van reden.',
                'locales' => [
                    'title' => ['en' => '14-day returns', 'fr' => 'Retours sous 14 jours'],
                    'body' => [
                        'en' => 'You may withdraw from your purchase within 14 days of delivery, without giving a reason.',
                        'fr' => 'Vous disposez de 14 jours après la livraison pour vous rétracter, sans avoir à vous justifier.',
                    ],
                ],
            ],
            [
                'match' => fn (ProductSectionItem $item): bool => str_contains((string) $item->title, 'reserveren')
                    || str_contains((string) $item->body, 'nummer wordt vastgelegd'),
                'title' => 'Veilig reserveren',
                'body' => 'Je nummer wordt vastgelegd na bevestiging. De Founding Edition wordt in één beperkte productieronde vervaardigd — volledig transparant.',
                'locales' => [
                    'title' => ['en' => 'Secure reservation', 'fr' => 'Réservation sécurisée'],
                    'body' => [
                        'en' => 'Your number is secured after confirmation. The Founding Edition is produced in a single limited production run — fully transparent.',
                        'fr' => 'Votre numéro est réservé après confirmation. La Founding Edition est produite en une seule série limitée — en toute transparence.',
                    ],
                ],
            ],
        ];

        foreach ($section->items as $item) {
            foreach ($items as $spec) {
                if (! $spec['match']($item)) {
                    continue;
                }

                $item->fill([
                    'title' => $spec['title'],
                    'body' => $spec['body'],
                ])->saveQuietly();

                $this->upsertTranslations($item, $spec['locales']);

                break;
            }
        }
    }

    public function down(): void
    {
        //
    }

    /**
     * @param  array<string, array{en: string, fr: string}>  $columns
     */
    private function upsertTranslations(object $model, array $columns): void
    {
        foreach ($columns as $column => $locales) {
            $source = (string) $model->{$column};
            $hash = hash('sha256', $source);

            foreach ($locales as $locale => $value) {
                DB::table('translations')->updateOrInsert(
                    [
                        'translatable_type' => $model::class,
                        'translatable_id' => $model->id,
                        'locale' => $locale,
                        'column' => $column,
                    ],
                    [
                        'value' => $value,
                        'source_hash' => $hash,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }
};
