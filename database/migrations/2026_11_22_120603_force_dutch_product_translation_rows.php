<?php

use App\Models\Product;
use App\Models\ProductSection;
use App\Models\ProductSectionItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Dutch storefront reads the nl translation row when one exists.
 * Production still shows the rejected shipping copy from those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $product = Product::query()->where('slug', Product::FOUNDING_SLUG)->first();

        if ($product === null) {
            return;
        }

        if ($this->containsRejected($product->hero_eyebrow) || $this->translationContains($product, 'nl', 'hero_eyebrow')) {
            $product->forceFill([
                'hero_eyebrow' => 'Founding Edition · 100 Genummerde Stuks',
            ])->saveQuietly();
            $this->upsert($product, 'hero_eyebrow', 'nl', 'Founding Edition · 100 Genummerde Stuks');
        }

        $this->replaceIfRejected($product, 'hero_eyebrow', 'en', 'Founding Edition · 100 Numbered Pieces');
        $this->replaceIfRejected($product, 'hero_eyebrow', 'fr', 'Founding Edition · 100 Pièces Numérotées');

        ProductSectionItem::query()
            ->whereHas('section', fn ($query) => $query->where('product_id', $product->id))
            ->whereHas('section', fn ($query) => $query->where('key', '!=', 'service'))
            ->each(function (ProductSectionItem $item): void {
                if (! $this->containsRejected($item->title) && ! $this->translationContains($item, 'nl', 'title')) {
                    return;
                }

                $item->forceFill(['title' => 'Levering in de EU'])->saveQuietly();
                $this->upsert($item, 'title', 'nl', 'Levering in de EU');
                $this->replaceIfRejected($item, 'title', 'en', 'Delivery within the EU');
                $this->replaceIfRejected($item, 'title', 'fr', 'Livraison dans l’UE');
            });

        $section = ProductSection::query()
            ->where('product_id', $product->id)
            ->where('key', 'service')
            ->first();

        if ($section === null) {
            return;
        }

        $section->forceFill([
            'intro' => 'Elke zending is verzekerd en volgbaar. Retour binnen 14 dagen na levering.',
        ])->saveQuietly();
        $this->upsert($section, 'intro', 'nl', 'Elke zending is verzekerd en volgbaar. Retour binnen 14 dagen na levering.');
        $this->upsert($section, 'intro', 'en', 'Every shipment is insured and trackable. Returns within 14 days of delivery.');
        $this->upsert($section, 'intro', 'fr', 'Chaque envoi est assuré et suivi. Retours sous 14 jours après livraison.');

        foreach ($section->items()->get() as $item) {
            if ($this->isReserveItem($item)) {
                $this->writeItem(
                    $item,
                    'Veilig reserveren',
                    'Je nummer wordt vastgelegd na bevestiging. De Founding Edition wordt in één beperkte productieronde vervaardigd — volledig transparant.',
                    'Secure reservation',
                    'Your number is secured after confirmation. The Founding Edition is produced in a single limited production run — fully transparent.',
                    'Réservation sécurisée',
                    'Votre numéro est réservé après confirmation. La Founding Edition est produite en une seule série limitée — en toute transparence.',
                );

                continue;
            }

            if ($this->isReturnItem($item)) {
                $this->writeItem(
                    $item,
                    '14 dagen retour',
                    'Je hebt 14 dagen na levering om je aankoop te herroepen, zonder opgave van reden.',
                    '14-day returns',
                    'You may withdraw from your purchase within 14 days of delivery, without giving a reason.',
                    'Retours sous 14 jours',
                    'Vous disposez de 14 jours après la livraison pour vous rétracter, sans avoir à vous justifier.',
                );

                continue;
            }

            if ($this->isShippingItem($item)) {
                $this->writeItem(
                    $item,
                    'Verzending',
                    'Levering binnen de EU is inbegrepen. Elke zending is verzekerd en traceerbaar.',
                    'Shipping',
                    'Shipping within the EU is included. Every shipment is insured and trackable.',
                    'Livraison',
                    'La livraison est incluse dans l’UE. Chaque envoi est assuré et suivi.',
                );
            }
        }
    }

    public function down(): void
    {
        //
    }

    private function isReserveItem(ProductSectionItem $item): bool
    {
        $haystack = $this->haystack($item);

        return str_contains($haystack, 'reserveren')
            || str_contains($haystack, 'nummer wordt vastgelegd');
    }

    private function isReturnItem(ProductSectionItem $item): bool
    {
        $haystack = mb_strtolower($this->haystack($item));

        return str_contains($haystack, '30')
            || str_contains($haystack, '14 dagen')
            || str_contains($haystack, 'retour');
    }

    private function isShippingItem(ProductSectionItem $item): bool
    {
        $haystack = mb_strtolower($this->haystack($item));

        return str_contains($haystack, 'verzending')
            || str_contains($haystack, 'benelux')
            || str_contains($haystack, 'wereldwijd')
            || str_contains($haystack, 'binnen de eu');
    }

    private function haystack(ProductSectionItem $item): string
    {
        return implode(' ', array_filter([
            $item->title,
            $item->body,
            $this->translationValue($item, 'nl', 'title'),
            $this->translationValue($item, 'nl', 'body'),
        ]));
    }

    private function writeItem(
        ProductSectionItem $item,
        string $title,
        string $body,
        string $englishTitle,
        string $englishBody,
        string $frenchTitle,
        string $frenchBody,
    ): void {
        $item->forceFill([
            'title' => $title,
            'body' => $body,
        ])->saveQuietly();

        $this->upsert($item, 'title', 'nl', $title);
        $this->upsert($item, 'body', 'nl', $body);
        $this->upsert($item, 'title', 'en', $englishTitle);
        $this->upsert($item, 'body', 'en', $englishBody);
        $this->upsert($item, 'title', 'fr', $frenchTitle);
        $this->upsert($item, 'body', 'fr', $frenchBody);
    }

    private function containsRejected(mixed $value): bool
    {
        return is_string($value) && preg_match('/wereldwijd|benelux|30 dagen|uw nummer/i', $value) === 1;
    }

    private function translationContains(object $model, string $locale, string $column): bool
    {
        return $this->containsRejected($this->translationValue($model, $locale, $column));
    }

    private function translationValue(object $model, string $locale, string $column): ?string
    {
        $value = DB::table('translations')
            ->where('translatable_type', $model::class)
            ->where('translatable_id', $model->id)
            ->where('locale', $locale)
            ->where('column', $column)
            ->value('value');

        return is_string($value) ? $value : null;
    }

    private function replaceIfRejected(object $model, string $column, string $locale, string $value): void
    {
        if (! $this->translationContains($model, $locale, $column)) {
            return;
        }

        $this->upsert($model, $column, $locale, $value);
    }

    private function upsert(object $model, string $column, string $locale, string $value): void
    {
        DB::table('translations')->updateOrInsert(
            [
                'translatable_type' => $model::class,
                'translatable_id' => $model->id,
                'locale' => $locale,
                'column' => $column,
            ],
            [
                'value' => $value,
                'source_hash' => hash('sha256', (string) $model->getAttribute($column)),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
