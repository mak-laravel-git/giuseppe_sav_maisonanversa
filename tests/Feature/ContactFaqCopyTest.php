<?php

use App\Enums\FaqContext;
use App\Jobs\TranslateModelJob;
use App\Models\Faq;
use App\Models\Translation;
use Database\Seeders\FaqSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Puts the page back in an outdated state: six old questions, one with a stale Dutch translation. */
function seedOutdatedContactFaq(): void
{
    FaqSeeder::existingContactFaqs()->each->delete();

    $faqs = Faq::factory()
        ->contact()
        ->count(6)
        ->sequence(fn ($sequence): array => [
            'question' => "Oude vraag {$sequence->index}?",
            'answer' => "Oud antwoord {$sequence->index}.",
            'sort_order' => $sequence->index,
        ])
        ->create();

    $faqs->first()->translations()->create([
        'locale' => 'nl',
        'column' => 'question',
        'value' => 'Verouderde vertaling?',
        'source_hash' => 'old',
    ]);
}

/** @return array{faqs: array<int, object>, translations: array<int, object>} */
function faqSnapshot(): array
{
    return [
        'faqs' => DB::table('faqs')->orderBy('id')->get()->all(),
        'translations' => DB::table('translations')->where('translatable_type', Faq::class)->orderBy('id')->get()->all(),
    ];
}

test('a dry run reports the changes and writes nothing', function () {
    seedOutdatedContactFaq();
    $before = faqSnapshot();

    $this->artisan('faq:apply-contact-copy', ['--dry-run' => true, '--diff' => true])
        ->expectsOutputToContain('now: Verouderde vertaling?')
        ->expectsOutputToContain('36 of 36 texts would change. Dry run: nothing was written.')
        ->assertSuccessful();

    expect(faqSnapshot())->toEqual($before);
});

test('the command replaces the six contact questions in place without queueing DeepL', function () {
    seedOutdatedContactFaq();
    $ids = FaqSeeder::existingContactFaqs()->modelKeys();
    $productFaq = Faq::factory()->product()->create(['question' => 'Productvraag?']);
    Queue::fake([TranslateModelJob::class]);

    $this->artisan('faq:apply-contact-copy')
        ->expectsOutputToContain('Updated 36 of 36 texts.')
        ->assertSuccessful();

    $faqs = FaqSeeder::existingContactFaqs();

    expect($faqs->modelKeys())->toBe($ids)
        ->and($faqs->first()->question)->toBe('Wanneer wordt Heritage Nº 001 geleverd?')
        ->and($faqs->first()->translated('question', 'nl'))->toBe('Wanneer wordt Heritage Nº 001 geleverd?')
        ->and($productFaq->fresh()->question)->toBe('Productvraag?')
        ->and(Translation::query()->where('translatable_type', Faq::class)->whereIn('translatable_id', $ids)->count())->toBe(36);
    Queue::assertNotPushed(TranslateModelJob::class);
});

test('the contact page shows the approved wording', function (string $locale, string $question, string $answer) {
    seedOutdatedContactFaq();
    $this->artisan('faq:apply-contact-copy')->assertSuccessful();

    $this->get("/{$locale}/contact")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('faqs', 6)
            ->where('faqs.3.question', $question)
            ->where('faqs.3.answer', $answer));
})->with([
    'Dutch' => [
        'nl',
        'Is de prijs inclusief verzending?',
        'Ja. Levering binnen de Europese Unie is inbegrepen in de prijs. Woon je buiten de Europese Unie, neem dan contact met ons op.',
    ],
    'English' => [
        'en',
        'Is shipping included in the price?',
        'Yes. Delivery within the European Union is included in the price. If you live outside the European Union, please contact us.',
    ],
    'French' => [
        'fr',
        'La livraison est-elle comprise dans le prix ?',
        'Oui. La livraison au sein de l’Union européenne est comprise dans le prix. Si vous habitez en dehors de l’Union européenne, contactez-nous.',
    ],
]);

test('a second run changes nothing', function () {
    seedOutdatedContactFaq();
    $this->artisan('faq:apply-contact-copy')->assertSuccessful();

    $this->artisan('faq:apply-contact-copy')
        ->expectsOutputToContain('Updated 0 of 36 texts.')
        ->assertSuccessful();
});

test('the command creates the six questions when the page has none', function () {
    FaqSeeder::existingContactFaqs()->each->delete();

    $this->artisan('faq:apply-contact-copy')->assertSuccessful();

    expect(Faq::publishedFor(FaqContext::Contact))->toHaveCount(6);
});

test('nothing is written when there are more than six contact questions', function () {
    seedOutdatedContactFaq();
    Faq::factory()->contact()->draft()->create(['sort_order' => 6]);
    $before = faqSnapshot();

    $this->artisan('faq:apply-contact-copy')
        ->expectsOutputToContain('Found 7 contact FAQs, expected at most 6.')
        ->assertFailed();
    $this->seed(FaqSeeder::class);

    expect(faqSnapshot())->toEqual($before);
});
