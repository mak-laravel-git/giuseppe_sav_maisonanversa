<?php

use App\Models\Product;
use App\Models\ProductSectionItem;
use App\Models\StoryPage;
use Illuminate\Support\Facades\Artisan;

test('the file upload demo route is gone', function () {
    $this->get('/en/file-upload-demo')->assertNotFound();
});

test('deploy copy replaces the rejected dutch product lines and the story court phrase', function () {
    Artisan::call('app:import-static-content-command');

    $product = Product::founding();
    $product->forceFill([
        'hero_eyebrow' => 'Founding Edition · 100 Stuks Wereldwijd',
    ])->saveQuietly();

    ProductSectionItem::query()
        ->where('title', 'Levering in de EU')
        ->firstOrFail()
        ->forceFill(['title' => 'Wereldwijde verzending'])
        ->saveQuietly();

    $shipping = ProductSectionItem::query()->where('title', 'Verzending')->firstOrFail();
    $shipping->forceFill([
        'body' => 'Gratis verzekerd verzonden binnen de Benelux.',
    ])->saveQuietly();

    $returns = ProductSectionItem::query()->where('title', '14 dagen retour')->firstOrFail();
    $returns->forceFill([
        'title' => '30 dagen retour',
        'body' => 'Retour binnen 30 dagen.',
    ])->saveQuietly();

    $reserve = ProductSectionItem::query()->where('title', 'Veilig reserveren')->firstOrFail();
    $reserve->forceFill([
        'body' => 'Uw nummer wordt vastgelegd na bevestiging.',
    ])->saveQuietly();

    $page = StoryPage::current();
    $page->translations()->updateOrCreate(
        ['locale' => 'en', 'column' => 'hero_body'],
        ['value' => 'Coffee. Style. The city. And then work.', 'source_hash' => 'stale'],
    );

    (require database_path('migrations/2026_11_22_113945_force_client_approved_story_and_product_copy.php'))->up();

    $product->refresh();
    $page->refresh();

    expect($product->hero_eyebrow)->toBe('Founding Edition · 100 Genummerde Stuks')
        ->and(ProductSectionItem::query()->where('title', 'Wereldwijde verzending')->exists())->toBeFalse()
        ->and(ProductSectionItem::query()->where('title', 'Levering in de EU')->exists())->toBeTrue()
        ->and(ProductSectionItem::query()->where('title', 'Verzending')->firstOrFail()->body)
        ->toBe('Levering binnen de EU is inbegrepen. Elke zending is verzekerd en traceerbaar.')
        ->and(ProductSectionItem::query()->where('title', '14 dagen retour')->exists())->toBeTrue()
        ->and(ProductSectionItem::query()->where('title', '30 dagen retour')->exists())->toBeFalse()
        ->and(ProductSectionItem::query()->where('title', 'Veilig reserveren')->firstOrFail()->body)
        ->toContain('Je nummer wordt vastgelegd na bevestiging')
        ->and($page->translated('hero_body', 'en'))->toBe('Coffee. Style. The city. And then the court.')
        ->and($page->translated('hero_eyebrow', 'fr'))->toBe('Notre histoire')
        ->and($page->translated('ritual_step_1_title', 'en'))->toBe('Getting dressed')
        ->and($page->translated('ritual_step_4_title', 'fr'))->toBe('Le terrain');
});
