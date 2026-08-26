<?php

namespace App\Twig;

use App\Entity\ProductionItem;
use App\Service\Thesaurus\JournalResolverService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Extensão Twig responsável por resolver estratos Qualis CAPES e bases científicas de indexação (Scopus, WoS, PubMed, etc.)
 * e renderizar badges coloridos e responsivos nos templates.
 *
 * Suporta passagem direta de objetos ProductionItem pré-indexados ($O(1)$ sem overhead de memória) ou strings avulsas.
 */
class QualisExtension extends AbstractExtension
{
    public function __construct(
        private readonly JournalResolverService $resolver
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('resolve_qualis', [$this, 'resolveQualis']),
            new TwigFunction('resolve_databases', [$this, 'resolveDatabases']),
            new TwigFunction('qualis_badge', [$this, 'renderQualisBadge'], ['is_safe' => ['html']]),
            new TwigFunction('database_badges', [$this, 'renderDatabaseBadges'], ['is_safe' => ['html']]),
            new TwigFunction('journal_badges', [$this, 'renderJournalBadges'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('qualis', [$this, 'resolveQualis']),
            new TwigFilter('databases', [$this, 'resolveDatabases']),
            new TwigFilter('qualis_badge', [$this, 'renderQualisBadge'], ['is_safe' => ['html']]),
            new TwigFilter('database_badges', [$this, 'renderDatabaseBadges'], ['is_safe' => ['html']]),
            new TwigFilter('journal_badges', [$this, 'renderJournalBadges'], ['is_safe' => ['html']]),
        ];
    }

    public function resolveQualis(mixed $target, ?string $issn = null): ?string
    {
        if ($target instanceof ProductionItem) {
            return $target->getQualis() ?: $this->resolver->resolveQualis($target->getJournalName(), $target->getIssn());
        }

        return is_string($target) ? $this->resolver->resolveQualis($target, $issn) : null;
    }

    /**
     * @return array<array{name: string, acronym: string, logo: ?string, url: ?string}>
     */
    public function resolveDatabases(mixed $target, ?string $issn = null): array
    {
        if ($target instanceof ProductionItem) {
            $dbs = $target->getIndexedDatabases();
            if ($dbs !== null) {
                return $dbs;
            }
            return $this->resolver->resolveDatabases($target->getJournalName(), $target->getIssn());
        }

        return is_string($target) ? $this->resolver->resolveDatabases($target, $issn) : [];
    }

    public function renderQualisBadge(mixed $target, ?string $issn = null, ?string $fallbackQualis = null): string
    {
        $qualis = null;
        if ($target instanceof ProductionItem) {
            $qualis = $target->getQualis();
            if (!$qualis) {
                $qualis = $this->resolver->resolveQualis($target->getJournalName(), $target->getIssn());
            }
        } elseif (is_string($target)) {
            $qualis = $this->resolver->resolveQualis($target, $issn);
        }

        if (!$qualis && $fallbackQualis) {
            $qualis = $fallbackQualis;
        }

        if (!$qualis) {
            return '';
        }

        $colorClasses = match ($qualis) {
            'A1' => 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-700/50 ring-1 ring-emerald-500/20',
            'A2' => 'bg-green-100 text-green-800 border-green-300 dark:bg-green-950 dark:text-green-300 dark:border-green-700/50',
            'A3' => 'bg-teal-100 text-teal-800 border-teal-300 dark:bg-teal-950 dark:text-teal-300 dark:border-teal-700/50',
            'A4' => 'bg-cyan-100 text-cyan-800 border-cyan-300 dark:bg-cyan-950 dark:text-cyan-300 dark:border-cyan-700/50',
            'B1' => 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-700/50',
            'B2' => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-950 dark:text-indigo-300 dark:border-indigo-700/50',
            'B3' => 'bg-violet-100 text-violet-800 border-violet-300 dark:bg-violet-950 dark:text-violet-300 dark:border-violet-700/50',
            'B4' => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-950 dark:text-purple-300 dark:border-purple-700/50',
            'C'  => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-700/50',
            default => 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700'
        };

        return sprintf(
            '<span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider border shadow-xs %s" title="Estrato Qualis CAPES: %s"><sl-icon name="award" class="text-[9px]"></sl-icon>Qualis %s</span>',
            $colorClasses,
            $qualis,
            $qualis
        );
    }

    public function renderDatabaseBadges(mixed $target, ?string $issn = null): string
    {
        $databases = null;
        if ($target instanceof ProductionItem) {
            $databases = $target->getIndexedDatabases();
            if ($databases === null) {
                $databases = $this->resolver->resolveDatabases($target->getJournalName(), $target->getIssn());
            }
        } elseif (is_string($target)) {
            $databases = $this->resolver->resolveDatabases($target, $issn);
        }

        if (empty($databases)) {
            return '';
        }

        $badgeHtml = [];
        foreach ($databases as $db) {
            $name = htmlspecialchars((string)($db['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $acronym = strtolower((string)($db['acronym'] ?? ''));
            $logo = $db['logo'] ?? null;

            $class = match ($acronym) {
                'scopus' => 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/60 dark:text-orange-300 dark:border-orange-800/60',
                'wos' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800/60',
                'pubmed' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800/60',
                'scielo' => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800/60',
                'doaj' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800/60',
                'latindex' => 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950/60 dark:text-teal-300 dark:border-teal-800/60',
                'openalex' => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800/60',
                'crossref' => 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800/60',
                default => 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
            };

            $icon = match ($acronym) {
                'scopus' => '<sl-icon name="journal-check" class="text-[9px] text-orange-600 dark:text-orange-400"></sl-icon>',
                'wos' => '<sl-icon name="globe" class="text-[9px] text-purple-600 dark:text-purple-400"></sl-icon>',
                'pubmed' => '<sl-icon name="heart-pulse" class="text-[9px] text-blue-600 dark:text-blue-400"></sl-icon>',
                'scielo' => '<sl-icon name="book" class="text-[9px] text-rose-600 dark:text-rose-400"></sl-icon>',
                'doaj' => '<sl-icon name="unlock" class="text-[9px] text-amber-600 dark:text-amber-400"></sl-icon>',
                'latindex' => '<sl-icon name="bookmarks" class="text-[9px] text-teal-600 dark:text-teal-400"></sl-icon>',
                'openalex' => '<sl-icon name="diagram-3" class="text-[9px] text-indigo-600 dark:text-indigo-400"></sl-icon>',
                default => '<sl-icon name="check-circle" class="text-[9px] text-slate-500"></sl-icon>',
            };

            $logoHtml = '';
            if ($logo && (str_ends_with($logo, '.svg') || str_ends_with($logo, '.png'))) {
                $logoHtml = sprintf('<img src="%s" alt="%s" class="h-3 w-auto object-contain shrink-0 inline-block" loading="lazy" />', htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'), $name);
            } else {
                $logoHtml = $icon;
            }

            $badgeHtml[] = sprintf(
                '<span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold border shadow-2xs %s" title="Indexado na base científica: %s">%s<span>%s</span></span>',
                $class,
                $name,
                $logoHtml,
                $name
            );
        }

        return implode(' ', $badgeHtml);
    }

    public function renderJournalBadges(mixed $target, ?string $issn = null, ?string $fallbackQualis = null): string
    {
        $qualisBadge = $this->renderQualisBadge($target, $issn, $fallbackQualis);
        $dbBadges = $this->renderDatabaseBadges($target, $issn);

        if (!$qualisBadge && !$dbBadges) {
            return '';
        }

        $parts = array_filter([$qualisBadge, $dbBadges]);
        return implode(' ', $parts);
    }
}
