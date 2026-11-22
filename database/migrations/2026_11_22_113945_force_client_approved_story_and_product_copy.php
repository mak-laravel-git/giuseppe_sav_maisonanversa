<?php

use App\Models\Product;
use App\Models\ProductSection;
use App\Models\ProductSectionItem;
use App\Models\StoryPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production still has the copy the client rejected. This runs on deploy:
 * Dutch product shipping/returns, the "100 Stuks Wereldwijd" eyebrow, and
 * Our Story EN/FR (including "And then work" → the padel court).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->forceProductCopy();
        $this->forceStoryCopy();
    }

    public function down(): void
    {
        //
    }

    private function forceProductCopy(): void
    {
        $product = Product::query()->where('slug', Product::FOUNDING_SLUG)->first();

        if ($product === null) {
            return;
        }

        if (str_contains((string) $product->hero_eyebrow, 'Wereldwijd')) {
            $product->forceFill([
                'hero_eyebrow' => 'Founding Edition · 100 Genummerde Stuks',
            ])->saveQuietly();
        }

        $this->replaceTranslation(
            $product,
            'hero_eyebrow',
            'en',
            '/wereldwijd|worldwide/i',
            'Founding Edition · 100 Numbered Pieces',
        );
        $this->replaceTranslation(
            $product,
            'hero_eyebrow',
            'fr',
            '/wereldwijd|worldwide|dans le monde|monde entier/i',
            'Founding Edition · 100 Pièces Numérotées',
        );

        ProductSectionItem::query()
            ->whereHas('section', fn ($query) => $query->where('product_id', $product->id))
            ->each(function (ProductSectionItem $item): void {
                if (! preg_match('/wereldwijd/i', (string) $item->title)) {
                    return;
                }

                $item->forceFill(['title' => 'Levering in de EU'])->saveQuietly();
                $this->upsert($item, 'title', 'en', 'Delivery within the EU');
                $this->upsert($item, 'title', 'fr', 'Livraison dans l’UE');
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

        $this->upsert($section, 'intro', 'en', 'Every shipment is insured and trackable. Returns within 14 days of delivery.');
        $this->upsert($section, 'intro', 'fr', 'Chaque envoi est assuré et suivi. Retours sous 14 jours après livraison.');

        $specs = [
            [
                'match' => fn (ProductSectionItem $item): bool => $item->title === 'Verzending'
                    || str_contains((string) $item->body, 'Benelux')
                    || str_contains((string) $item->body, 'Wereldwijd')
                    || str_contains((string) $item->title, 'Wereldwijde'),
                'title' => 'Verzending',
                'body' => 'Levering binnen de EU is inbegrepen. Elke zending is verzekerd en traceerbaar.',
                'en' => [
                    'title' => 'Shipping',
                    'body' => 'Shipping within the EU is included. Every shipment is insured and trackable.',
                ],
                'fr' => [
                    'title' => 'Livraison',
                    'body' => 'La livraison est incluse dans l’UE. Chaque envoi est assuré et suivi.',
                ],
            ],
            [
                'match' => fn (ProductSectionItem $item): bool => str_contains((string) $item->title, '30')
                    || str_contains((string) $item->title, '14 dagen')
                    || str_contains((string) $item->title, 'retour'),
                'title' => '14 dagen retour',
                'body' => 'Je hebt 14 dagen na levering om je aankoop te herroepen, zonder opgave van reden.',
                'en' => [
                    'title' => '14-day returns',
                    'body' => 'You may withdraw from your purchase within 14 days of delivery, without giving a reason.',
                ],
                'fr' => [
                    'title' => 'Retours sous 14 jours',
                    'body' => 'Vous disposez de 14 jours après la livraison pour vous rétracter, sans avoir à vous justifier.',
                ],
            ],
            [
                'match' => fn (ProductSectionItem $item): bool => str_contains((string) $item->title, 'reserveren')
                    || str_contains((string) $item->body, 'nummer wordt vastgelegd'),
                'title' => 'Veilig reserveren',
                'body' => 'Je nummer wordt vastgelegd na bevestiging. De Founding Edition wordt in één beperkte productieronde vervaardigd — volledig transparant.',
                'en' => [
                    'title' => 'Secure reservation',
                    'body' => 'Your number is secured after confirmation. The Founding Edition is produced in a single limited production run — fully transparent.',
                ],
                'fr' => [
                    'title' => 'Réservation sécurisée',
                    'body' => 'Votre numéro est réservé après confirmation. La Founding Edition est produite en une seule série limitée — en toute transparence.',
                ],
            ],
        ];

        foreach ($section->items()->get() as $item) {
            foreach ($specs as $spec) {
                if (! $spec['match']($item)) {
                    continue;
                }

                $item->forceFill([
                    'title' => $spec['title'],
                    'body' => $spec['body'],
                ])->saveQuietly();

                foreach (['title', 'body'] as $column) {
                    $this->upsert($item, $column, 'en', $spec['en'][$column]);
                    $this->upsert($item, $column, 'fr', $spec['fr'][$column]);
                }

                break;
            }
        }
    }

    private function forceStoryCopy(): void
    {
        $page = StoryPage::query()->first();

        if ($page === null) {
            return;
        }

        /** @var array{en: array<string, string>, fr: array<string, string>} $copy */
        $copy = json_decode(
            (string) file_get_contents(database_path('data/client-story-en-fr.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach (['en', 'fr'] as $locale) {
            foreach ($copy[$locale] as $column => $value) {
                $this->upsert($page, $column, $locale, $value);
            }
        }

        DB::table('translations')
            ->where('translatable_type', StoryPage::class)
            ->where('translatable_id', $page->id)
            ->orderBy('id')
            ->each(function (object $row): void {
                $fixed = strtr((string) $row->value, [
                    'And then work.' => 'And then the court.',
                    'And then work' => 'And then the court',
                    'left your job' => 'left the court',
                    'quitte son emploi' => 'quitte le terrain',
                    'Le parcours' => 'Le terrain',
                    'le parcours' => 'le terrain',
                ]);

                if ($fixed === $row->value) {
                    return;
                }

                DB::table('translations')->where('id', $row->id)->update([
                    'value' => $fixed,
                    'updated_at' => now(),
                ]);
            });
    }

    private function replaceTranslation(object $model, string $column, string $locale, string $pattern, string $value): void
    {
        $current = DB::table('translations')
            ->where('translatable_type', $model::class)
            ->where('translatable_id', $model->id)
            ->where('locale', $locale)
            ->where('column', $column)
            ->value('value');

        if (! is_string($current) || ! preg_match($pattern, $current)) {
            return;
        }

        $this->upsert($model, $column, $locale, $value);
    }

    private function upsert(object $model, string $column, string $locale, string $value): void
    {
        $source = (string) $model->getAttribute($column);

        DB::table('translations')->updateOrInsert(
            [
                'translatable_type' => $model::class,
                'translatable_id' => $model->id,
                'locale' => $locale,
                'column' => $column,
            ],
            [
                'value' => $value,
                'source_hash' => hash('sha256', $source),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
