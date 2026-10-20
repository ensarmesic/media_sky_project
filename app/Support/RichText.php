<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Siguran prikaz korisničkog teksta (opis zadatka, komentari): osnovni markdown
 * (bold, kod, liste, novi red), automatski klikabilni URL-ovi i @spominjanja.
 * Sirovi HTML se escape-uje, a nesigurni linkovi (javascript: i sl.) se odbacuju.
 */
class RichText
{
    private static ?MarkdownConverter $converter = null;

    /** @param  array<int, string>  $mentionableUsernames */
    public static function render(?string $text, array $mentionableUsernames = []): HtmlString
    {
        if (blank($text)) {
            return new HtmlString('');
        }

        $html = self::converter()->convert($text)->getContent();

        if ($mentionableUsernames !== []) {
            $known = array_flip(array_map('mb_strtolower', $mentionableUsernames));
            $html = preg_replace_callback(
                '/(?<![\w\/@.])@([A-Za-z0-9._-]+)/u',
                fn (array $match) => isset($known[mb_strtolower(rtrim($match[1], '.'))])
                    ? '<span class="rounded bg-blue-50 px-1 font-medium text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">@'.e(rtrim($match[1], '.')).'</span>'.(str_ends_with($match[1], '.') ? '.' : '')
                    : $match[0],
                $html,
            );
        }

        return new HtmlString($html);
    }

    /**
     * Korisnička imena spomenuta u tekstu (@korisnik).
     *
     * @return array<int, string>
     */
    public static function mentions(string $text): array
    {
        preg_match_all('/(?<![\w\/@.])@([A-Za-z0-9._-]+)/u', $text, $matches);

        return array_values(array_unique(array_map(fn (string $name) => rtrim($name, '.'), $matches[1])));
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter === null) {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 10,
                'renderer' => ['soft_break' => "<br />\n"],
                'external_link' => [
                    'internal_hosts' => [],
                    'open_in_new_window' => true,
                    'noopener' => 'external',
                    'noreferrer' => 'external',
                    'html_class' => 'text-blue-600 underline decoration-blue-600/30 hover:decoration-blue-600 dark:text-blue-400',
                ],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new AutolinkExtension);
            $environment->addExtension(new StrikethroughExtension);
            $environment->addExtension(new ExternalLinkExtension);
            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter;
    }
}
