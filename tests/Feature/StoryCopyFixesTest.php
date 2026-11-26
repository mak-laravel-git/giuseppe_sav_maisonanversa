<?php

use App\Models\StoryPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * @return array<string, array<string, array{from: string, to: string}>>
 */
function storyFixes(): array
{
    return json_decode(File::get(database_path('data/client-story-fixes-2026-11-25.json')), true, flags: JSON_THROW_ON_ERROR);
}

function storyTranslation(StoryPage $page, string $locale, string $column): ?string
{
    return DB::table('translations')
        ->where('translatable_type', StoryPage::class)
        ->where('translatable_id', $page->id)
        ->where('locale', $locale)
        ->where('column', $column)
        ->value('value');
}

/** The live state the client compared: every listed line holds its "live now" text. */
function seedLiveStory(): StoryPage
{
    $page = StoryPage::current();

    foreach (storyFixes() as $locale => $columns) {
        foreach ($columns as $column => $fix) {
            DB::table('translations')->insert([
                'translatable_type' => StoryPage::class,
                'translatable_id' => $page->id,
                'locale' => $locale,
                'column' => $column,
                'value' => $fix['from'],
                'source_hash' => 'old',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    DB::table('translations')->insert([
        'translatable_type' => StoryPage::class,
        'translatable_id' => $page->id,
        'locale' => 'en',
        'column' => 'city_title',
        'value' => 'Antwerp (untouched)',
        'source_hash' => 'old',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $page;
}

test('the data file lists the 25 English and 27 French lines of the client list', function () {
    $fixes = storyFixes();

    // 25 English lines are 26 columns (the hero title is two columns); French step 2 changes title and body, so 27 lines are 28 columns.
    expect($fixes['en'])->toHaveCount(26)
        ->and($fixes['fr'])->toHaveCount(28)
        ->and($fixes['fr']['closing_line']['to'])->toBe('Every legacy begins with a first chapter.')
        ->and($fixes['fr']['make_button_label']['to'])->toBe('Découvrir Heritage Nº 001')
        ->and($fixes['en']['hero_title']['to'].' '.$fixes['en']['hero_title_accent']['to'])->toBe('Everything begins with a coffee.')
        ->and($fixes['en']['closing_place']['to'])->toBe('Antwerp, Belgium · Est. 2026');
});

test('a dry run changes nothing', function () {
    $page = seedLiveStory();

    $this->artisan('story:apply-copy-fixes', ['--dry-run' => true])->assertSuccessful();

    expect(storyTranslation($page, 'en', 'hero_title'))->toBe('It all starts with');
});

test('the command replaces only the listed lines and nothing else', function () {
    $page = seedLiveStory();
    $sourceBefore = $page->only(StoryPage::TRANSLATION_COLUMNS);
    $rowsBefore = DB::table('translations')->count();

    $this->artisan('story:apply-copy-fixes')->assertSuccessful();

    foreach (storyFixes() as $locale => $columns) {
        foreach ($columns as $column => $fix) {
            expect(storyTranslation($page, $locale, $column))->toBe($fix['to']);
        }
    }

    expect(storyTranslation($page, 'en', 'city_title'))->toBe('Antwerp (untouched)')
        ->and(DB::table('translations')->count())->toBe($rowsBefore)
        ->and($page->fresh()->only(StoryPage::TRANSLATION_COLUMNS))->toBe($sourceBefore);
});

test('the rows carry the source hash so DeepL leaves them alone, and a second run changes nothing', function () {
    $page = seedLiveStory();

    $this->artisan('story:apply-copy-fixes')->assertSuccessful();

    expect(DB::table('translations')
        ->where('translatable_type', StoryPage::class)
        ->where('column', 'founder_paragraph_one')
        ->where('locale', 'fr')
        ->value('source_hash'))->toBe($page->translationSourceHash('founder_paragraph_one'));

    $this->artisan('story:apply-copy-fixes')
        ->expectsOutputToContain('Updated 0 of 54 lines.')
        ->assertSuccessful();
});

test('the public story pages show the approved wording', function () {
    seedLiveStory();
    $this->artisan('story:apply-copy-fixes')->assertSuccessful();

    // The English page reads the translation rows, not the source column.
    expect(storyTranslation(StoryPage::current(), 'en', 'ritual_step_1_title'))->toBe('Dressing')
        ->and(StoryPage::current()->ritual_step_1_title)->not->toBe('Dressing');

    $this->get('/en/story')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('story.hero.title', 'Everything begins with')
            ->where('story.ritual.steps.0.title', 'Dressing'));

    $this->get('/fr/story')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('story.closing.line', 'Every legacy begins with a first chapter.')
            ->where('story.ritual.steps.1.title', 'Le café'));
});

test('the Club Corner page writes Heritage Nº 001 with the ordinal sign', function () {
    foreach (['resources/js/pages/maison/corner.tsx', 'lang/nl.json', 'lang/fr.json'] as $file) {
        expect(File::get(base_path($file)))->not->toContain('Heritage № 001');
    }
});
