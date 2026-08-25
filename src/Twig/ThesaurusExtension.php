<?php

namespace App\Twig;

use App\Entity\Researcher;
use App\Service\Thesaurus\AuthorResolverService;
use App\Service\Thesaurus\CountryResolverService;
use App\Service\Thesaurus\InstitutionResolverService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class ThesaurusExtension extends AbstractExtension
{
    public function __construct(
        private readonly CountryResolverService $countryResolver,
        private readonly InstitutionResolverService $institutionResolver,
        private readonly AuthorResolverService $authorResolver
    ) {}

    public function getFunctions(): array
    {
        return [
            // Country helpers
            new TwigFunction('country_flag', [$this, 'getCountryFlag']),
            new TwigFunction('country_name', [$this, 'getCountryName']),
            new TwigFunction('country_badge', [$this, 'renderCountryBadge'], ['is_safe' => ['html']]),
            new TwigFunction('resolve_country', [$this, 'resolveCountry']),

            // Institution helpers
            new TwigFunction('institution_name', [$this, 'getInstitutionName']),
            new TwigFunction('institution_badge', [$this, 'renderInstitutionBadge'], ['is_safe' => ['html']]),
            new TwigFunction('resolve_institution', [$this, 'resolveInstitution']),

            // Author helpers
            new TwigFunction('author_thesaurus_variants', [$this, 'getAuthorThesaurusVariants']),
            new TwigFunction('author_thesaurus_names_string', [$this, 'getAuthorThesaurusNamesString']),
            new TwigFunction('author_html', [$this, 'renderAuthorHtml'], ['is_safe' => ['html']]),
            new TwigFunction('render_production_authors', [$this, 'renderProductionAuthors'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('country_flag', [$this, 'getCountryFlag']),
            new TwigFilter('country_name', [$this, 'getCountryName']),
            new TwigFilter('country_badge', [$this, 'renderCountryBadge'], ['is_safe' => ['html']]),
            new TwigFilter('institution_name', [$this, 'getInstitutionName']),
            new TwigFilter('institution_badge', [$this, 'renderInstitutionBadge'], ['is_safe' => ['html']]),
            new TwigFilter('production_authors', [$this, 'renderProductionAuthors'], ['is_safe' => ['html']]),
        ];
    }

    // Country methods
    public function getCountryFlag(?string $query): string
    {
        return $this->countryResolver->getCountryFlag($query);
    }

    public function getCountryName(?string $query): string
    {
        return $this->countryResolver->getCountryDisplayName($query);
    }

    public function renderCountryBadge(?string $query, string $extraClasses = ''): string
    {
        return $this->countryResolver->renderCountryBadge($query, $extraClasses);
    }

    public function resolveCountry(?string $query)
    {
        return $this->countryResolver->resolveCountry($query);
    }

    // Institution methods
    public function getInstitutionName(?string $query): string
    {
        return $this->institutionResolver->getInstitutionDisplayName($query);
    }

    public function renderInstitutionBadge(?string $query, string $extraClasses = ''): string
    {
        return $this->institutionResolver->renderInstitutionBadge($query, $extraClasses);
    }

    public function resolveInstitution(?string $query)
    {
        return $this->institutionResolver->resolveInstitution($query);
    }

    // Author methods
    /**
     * @return array<string>
     */
    public function getAuthorThesaurusVariants(Researcher $researcher): array
    {
        return $this->authorResolver->getCitationVariantsForResearcher($researcher);
    }

    public function getAuthorThesaurusNamesString(Researcher $researcher): string
    {
        $variants = $this->authorResolver->getCitationVariantsForResearcher($researcher);
        return implode('; ', $variants);
    }

    public function renderAuthorHtml(string $authorName, ?string $citationName = null, ?int $currentResearcherId = null): string
    {
        return $this->authorResolver->renderAuthorHtml($authorName, $citationName, $currentResearcherId);
    }

    public function renderProductionAuthors(mixed $productionOrAuthors, ?int $currentResearcherId = null): string
    {
        return $this->authorResolver->renderProductionAuthors($productionOrAuthors, $currentResearcherId);
    }
}
