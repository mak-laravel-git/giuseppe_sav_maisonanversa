<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/**
 * The fonts worth preloading: the two Latin WOFF2 files the first screen is set in.
 *
 * Remote providers may ship each weight as several unicode-range files (Latin,
 * Latin-ext, Cyrillic, …). Fontsource with `subsets: ['latin']` ships one file
 * per weight with no unicode-range. This reads the plugin's build manifest and
 * picks the Latin WOFF2 of just those two, whatever its hashed file name is
 * after the next build.
 */
final class FontPreloads
{
    /**
     * Family alias => the `weight:style` variant to preload.
     *
     * @var array<string, string>
     */
    private const VARIANTS = [
        'baskervville' => '400:normal',
        'montserrat' => '300:normal',
    ];

    /** The Latin subset always starts at the basic Latin block. */
    private const LATIN_RANGE_PREFIX = 'U+0000-00FF';

    /**
     * Public URLs of the Latin WOFF2 files to preload, in family order.
     *
     * Nothing is returned while the Vite dev server runs, or before a build, since
     * the plugin then serves the fonts itself.
     *
     * @return array<int, string>
     */
    public static function latin(?string $manifestPath = null): array
    {
        $manifestPath ??= public_path('build/fonts-manifest.json');

        if (Vite::isRunningHot() || ! File::exists($manifestPath)) {
            return [];
        }

        /** @var array{families?: array<string, array{variants?: array<string, array{files?: array<int, array{file?: string, format?: string, unicodeRange?: string}>}>}>}|null $manifest */
        $manifest = json_decode(File::get($manifestPath), true);

        $urls = [];

        foreach (self::VARIANTS as $family => $variant) {
            $files = $manifest['families'][$family]['variants'][$variant]['files'] ?? [];
            $path = self::latinWoff2Path($files);

            if ($path !== null) {
                $urls[] = asset('build/'.$path);
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, array{file?: string, format?: string, unicodeRange?: string}>  $files
     */
    private static function latinWoff2Path(array $files): ?string
    {
        $woff2 = array_values(array_filter(
            $files,
            fn (array $file): bool => ($file['format'] ?? null) === 'woff2' && isset($file['file']),
        ));

        if ($woff2 === []) {
            return null;
        }

        $hasRanges = collect($woff2)->contains(
            fn (array $file): bool => filled($file['unicodeRange'] ?? null),
        );

        if (! $hasRanges) {
            return $woff2[0]['file'];
        }

        foreach ($woff2 as $file) {
            if (str_starts_with($file['unicodeRange'] ?? '', self::LATIN_RANGE_PREFIX)) {
                return $file['file'];
            }
        }

        return null;
    }
}
