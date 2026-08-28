<?php

namespace studionoir\translatorpro\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use studionoir\translatorpro\TranslatorPro;

/**
 * Finds translatable strings by scanning the project's templates, modules and
 * local plugins for `|t`, `Craft::t()` and `Craft.t()` calls.
 *
 * Results are cached against the mtimes of the scanned files, so an unchanged
 * project never pays for a rescan.
 */
class Scanner extends Component
{
    private const CACHE_KEY = 'translator-pro:scan';

    /**
     * @var array<string,array<string,string>>|null category => [string => string]
     */
    private ?array $strings = null;

    /**
     * Strings found for a single category, as `string => string`.
     *
     * @return array<string,string>
     */
    public function getStrings(string $category = 'site'): array
    {
        return $this->getAllStrings()[$category] ?? [];
    }

    /**
     * Every string found, grouped by translation category.
     *
     * @return array<string,array<string,string>>
     */
    public function getAllStrings(): array
    {
        if ($this->strings !== null) {
            return $this->strings;
        }

        $files = $this->collectFiles();
        $cacheKey = self::CACHE_KEY . ':' . $this->fingerprint($files);
        $cached = Craft::$app->getCache()->get($cacheKey);

        if (is_array($cached)) {
            return $this->strings = $cached;
        }

        $strings = $this->scanFiles($files);

        Craft::$app->getCache()->set($cacheKey, $strings, 60 * 60 * 24);

        return $this->strings = $strings;
    }

    /**
     * Drops the cached scan so the next read walks the files again.
     */
    public function invalidate(): void
    {
        $this->strings = null;
        Craft::$app->getCache()->delete(self::CACHE_KEY . ':' . $this->fingerprint($this->collectFiles()));
    }

    /**
     * The directories that get scanned.
     *
     * @return string[]
     */
    public function getScanPaths(): array
    {
        $settings = TranslatorPro::$plugin->getSettings();

        $paths = [
            Craft::$app->getPath()->getSiteTemplatesPath(),
            Craft::getAlias('@root/modules', false),
            Craft::getAlias('@root/plugins', false),
        ];

        foreach ($settings->extraScanPaths as $path) {
            $paths[] = Craft::getAlias($path, false);
        }

        return array_values(array_unique(array_filter(
            $paths,
            static fn($p) => is_string($p) && is_dir($p),
        )));
    }

    /**
     * @return string[] absolute file paths
     */
    private function collectFiles(): array
    {
        $extensions = TranslatorPro::$plugin->getSettings()->scanExtensions;
        $files = [];

        foreach ($this->getScanPaths() as $path) {
            try {
                $found = FileHelper::findFiles($path, [
                    'only' => array_map(static fn($ext) => '*.' . ltrim($ext, '.'), $extensions),
                    'except' => [
                        '/node_modules/',
                        '/vendor/',
                        '/.git/',
                        '*.min.js',
                    ],
                    'recursive' => true,
                ]);
            } catch (\Throwable $e) {
                Craft::warning("Couldn't scan $path: {$e->getMessage()}", __METHOD__);
                continue;
            }

            $files = array_merge($files, $found);
        }

        sort($files);

        return $files;
    }

    /**
     * @param string[] $files
     */
    private function fingerprint(array $files): string
    {
        $parts = [];

        foreach ($files as $file) {
            $parts[] = $file . ':' . @filemtime($file);
        }

        return md5(implode('|', $parts));
    }

    /**
     * @param string[] $files
     * @return array<string,array<string,string>>
     */
    private function scanFiles(array $files): array
    {
        $strings = [];

        foreach ($files as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false || $contents === '') {
                continue;
            }

            foreach ($this->extract($contents) as $category => $found) {
                foreach ($found as $string) {
                    $strings[$category][$string] = $string;
                }
            }
        }

        foreach ($strings as $category => $found) {
            ksort($found, SORT_NATURAL | SORT_FLAG_CASE);
            $strings[$category] = $found;
        }

        return $strings;
    }

    /**
     * Pulls translatable strings out of one file's contents.
     *
     * @return array<string,string[]> category => strings
     */
    private function extract(string $contents): array
    {
        // A quoted string, capturing the delimiter so escapes are handled per style.
        $str = '(?P<q%1$s>[\'"])(?P<s%1$s>(?:\\\\.|(?!(?P=q%1$s))[^\\\\])*)(?P=q%1$s)';

        $results = [];

        // 1. Twig filter: "Foo"|t  /  "Foo"|t('category')  /  "Foo"|translate('category')
        $filterPattern = '/' . sprintf($str, 'a') . '\s*\|\s*(?:t|translate)\b\s*(?:\(\s*' . sprintf($str, 'b') . ')?/u';

        if (preg_match_all($filterPattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $message = $this->unescape($match['sa'], $match['qa']);
                $category = isset($match['sb']) && $match['sb'] !== ''
                    ? $this->unescape($match['sb'], $match['qb'])
                    : 'site';

                if ($this->isTranslatable($message)) {
                    $results[$category][] = $message;
                }
            }
        }

        // 2. PHP / JS: Craft::t('category', 'Foo')  /  Craft.t('category', 'Foo')
        $callPattern = '/\bCraft\s*(?:::|\.)\s*t\s*\(\s*' . sprintf($str, 'a')
            . '\s*,\s*' . sprintf($str, 'b') . '/u';

        if (preg_match_all($callPattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $category = $this->unescape($match['sa'], $match['qa']);
                $message = $this->unescape($match['sb'], $match['qb']);

                if ($this->isTranslatable($message)) {
                    $results[$category][] = $message;
                }
            }
        }

        return $results;
    }

    /**
     * Turns the raw source between the quotes back into the literal string.
     */
    private function unescape(string $raw, string $quote): string
    {
        if ($quote === "'") {
            // Twig and PHP single quotes only honour \' and \\.
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $raw);
        }

        return str_replace(
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            ['\\', '"', "\n", "\r", "\t"],
            $raw,
        );
    }

    /**
     * Filters out matches that are clearly not user-facing copy.
     */
    private function isTranslatable(string $message): bool
    {
        if (trim($message) === '') {
            return false;
        }

        // Twig string interpolation — the literal isn't the real message.
        if (str_contains($message, '#{')) {
            return false;
        }

        return true;
    }
}
