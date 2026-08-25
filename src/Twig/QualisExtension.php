<?php

namespace App\Twig;

use App\Service\Thesaurus\JournalResolverService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class QualisExtension extends AbstractExtension
{
    public function __construct(
        private readonly JournalResolverService $resolver
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('resolve_qualis', [$this, 'resolveQualis']),
            new TwigFunction('qualis_badge', [$this, 'renderQualisBadge'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('qualis', [$this, 'resolveQualis']),
            new TwigFilter('qualis_badge', [$this, 'renderQualisBadge'], ['is_safe' => ['html']]),
        ];
    }

    public function resolveQualis(?string $journalName, ?string $issn = null): ?string
    {
        return $this->resolver->resolveQualis($journalName, $issn);
    }

    public function renderQualisBadge(?string $journalName, ?string $issn = null): string
    {
        $qualis = $this->resolver->resolveQualis($journalName, $issn);
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
}
