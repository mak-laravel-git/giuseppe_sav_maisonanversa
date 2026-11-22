<?php

use App\Models\SeoMeta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DeepL turned Dutch "Kleedkamer" into "Changing room". Pin the brand titles
 * for the Dressing Room SEO override when one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $meta = SeoMeta::query()->where('page_key', 'dressing')->first();

        if ($meta === null) {
            return;
        }

        $titles = [
            'en' => 'Dressing Room — Maison Anversa',
            'fr' => 'Vestiaire — Maison Anversa',
        ];

        $source = (string) $meta->title;
        $hash = hash('sha256', $source);

        foreach ($titles as $locale => $value) {
            DB::table('translations')->updateOrInsert(
                [
                    'translatable_type' => SeoMeta::class,
                    'translatable_id' => $meta->id,
                    'locale' => $locale,
                    'column' => 'title',
                ],
                [
                    'value' => $value,
                    'source_hash' => $hash,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $dutch = trim($source);

        if ($dutch === '' || str_contains(mb_strtolower($dutch), 'changing')) {
            $meta->fill(['title' => 'Dressing Room — Maison Anversa'])->saveQuietly();
        }
    }

    public function down(): void
    {
        //
    }
};
