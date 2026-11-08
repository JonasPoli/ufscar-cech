<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\ProductionItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filtro único da pesquisa pública: o mesmo recorte vale para a busca, o panorama, a rede de coautoria, as páginas
 * de assunto e área e a API. Cada campo vira uma condição sobre `production_items` (alias `pi`) e o docente dono do
 * currículo (alias `r`), montada por {@see ResearchSql}.
 *
 * Os nomes dos parâmetros na URL são em português e curtos, para os links poderem ser compartilhados:
 * `q`, `exata`, `departamento`, `tema`, `grande_area`, `area`, `vinculo`, `tipo`, `de`, `ate`.
 */
final class ResearchFilter
{
    /** Tipo na URL => `item_type` gravado. */
    public const TYPES = [
        'artigo' => ProductionItem::TYPE_ARTIGO,
        'livro' => ProductionItem::TYPE_LIVRO,
        'capitulo' => ProductionItem::TYPE_CAPITULO,
        'evento' => ProductionItem::TYPE_EVENTO,
    ];

    public const TYPE_LABELS = [
        'artigo' => 'Artigo',
        'livro' => 'Livro',
        'capitulo' => 'Capítulo',
        'evento' => 'Trabalho em evento',
    ];

    public const AFFILIATIONS = [
        'vigente' => 'Vínculo vigente',
        'encerrado' => 'Vínculo encerrado',
    ];

    public const MIN_YEAR = 1950;

    /** Palavras que não precisam aparecer no trabalho (a expressão completa no título ainda soma pontos). */
    private const STOPWORDS = [
        'de' => 1, 'da' => 1, 'do' => 1, 'das' => 1, 'dos' => 1, 'e' => 1, 'a' => 1, 'o' => 1, 'as' => 1, 'os' => 1,
        'em' => 1, 'no' => 1, 'na' => 1, 'nos' => 1, 'nas' => 1, 'um' => 1, 'uma' => 1, 'para' => 1, 'por' => 1,
        'com' => 1, 'ao' => 1, 'aos' => 1, 'à' => 1, 'às' => 1, 'pelo' => 1, 'pela' => 1, 'sobre' => 1,
        'the' => 1, 'of' => 1, 'and' => 1, 'in' => 1, 'on' => 1, 'for' => 1, 'to' => 1, 'an' => 1,
        'del' => 1, 'la' => 1, 'el' => 1, 'y' => 1, 'en' => 1, 'los' => 1, 'las' => 1,
    ];

    public string $q = '';
    public bool $exact = false;
    public string $department = '';
    /** Slug do termo do índice temático, como veio na URL. */
    public string $topicSlug = '';
    /** Texto do termo, resolvido pelo controller a partir do slug; é ele que filtra. */
    public string $topicText = '';
    public string $majorArea = '';
    public string $area = '';
    public string $affiliation = '';
    public string $type = '';
    public ?int $from = null;
    public ?int $to = null;
    /** Restringe a um docente (aba "Publicações da busca" do perfil); não vai para a URL pública. */
    public ?int $researcherId = null;

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;
        $f = new self();

        $f->q = self::cleanText((string)$query->get('q', ''));
        $f->exact = $query->getBoolean('exata');

        // `dept` é o nome antigo do parâmetro, mantido para os links já publicados
        $department = trim((string)$query->get('departamento', $query->get('dept', '')));
        $f->department = ($department === 'all') ? '' : mb_substr($department, 0, 80);

        $f->topicSlug = mb_substr(trim((string)$query->get('tema', '')), 0, 190);
        $f->majorArea = MajorAreaCatalog::normalizeCode((string)$query->get('grande_area', ''));
        $f->area = mb_substr(trim((string)$query->get('area', '')), 0, 150);

        $affiliation = (string)$query->get('vinculo', '');
        $f->affiliation = isset(self::AFFILIATIONS[$affiliation]) ? $affiliation : '';

        $type = (string)$query->get('tipo', '');
        $f->type = isset(self::TYPES[$type]) ? $type : '';

        $f->from = self::year($query->get('de'));
        $f->to = self::year($query->get('ate'));
        if ($f->from !== null && $f->to !== null && $f->from > $f->to) {
            [$f->from, $f->to] = [$f->to, $f->from];
        }

        return $f;
    }

    /**
     * Parâmetros de URL do filtro (sem vazios), para links de paginação, ordenação e "Voltar à busca".
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'exata' => $this->exact ? 1 : null,
            'departamento' => $this->department,
            'tema' => $this->topicSlug,
            'grande_area' => $this->majorArea,
            'area' => $this->area,
            'vinculo' => $this->affiliation,
            'tipo' => $this->type,
            'de' => $this->from,
            'ate' => $this->to,
        ], static fn($v) => $v !== null && $v !== '' && $v !== 0);
    }

    /** Há algum recorte além do conjunto inteiro? */
    public function isEmpty(): bool
    {
        return $this->q === '' && $this->department === '' && $this->topicText === '' && $this->topicSlug === ''
            && $this->majorArea === '' && $this->area === '' && $this->affiliation === '' && $this->type === ''
            && $this->from === null && $this->to === null && $this->researcherId === null;
    }

    /** Há recorte por conteúdo (texto ou tema), que dá sentido à pontuação de relevância? */
    public function hasTextCriteria(): bool
    {
        return $this->q !== '' || $this->topicText !== '';
    }

    /**
     * Pode ir para o cache.research? Texto livre (`q`) não vai: cada termo gravaria arquivos novos no disco, e o pool em
     * arquivos não apaga os vencidos sozinho. Os demais parâmetros têm valores limitados (validados na fábrica).
     */
    public function isCacheable(): bool
    {
        return $this->q === '';
    }

    /** Chave estável para cache de resultados calculados com este filtro. */
    public function cacheKey(string $prefix): string
    {
        $data = $this->toQuery();
        $data['_topic'] = $this->topicText;
        $data['_r'] = $this->researcherId;
        ksort($data);

        return $prefix . '_' . md5(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** `item_type` do filtro de tipo, ou null. */
    public function itemType(): ?string
    {
        return $this->type !== '' ? self::TYPES[$this->type] : null;
    }

    /**
     * Termos da busca: as palavras de `q` (todas precisam aparecer) ou, com `exata`, a frase inteira.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        if ($this->q === '') {
            return [];
        }

        if ($this->exact) {
            return [$this->q];
        }

        $words = preg_split('/[\s,;:!?()\[\]{}"“”«»]+/u', $this->q) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = mb_strtolower(trim($word, " \t\n\r\0\x0B.'’-"));
            if (mb_strlen($word) >= 2 && !isset(self::STOPWORDS[$word]) && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }

        return $terms !== [] ? array_slice($terms, 0, 8) : [$this->q];
    }

    private static function cleanText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, 200);
    }

    private static function year(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $year = (int)$value;
        $max = (int)date('Y') + 1;

        return ($year >= self::MIN_YEAR && $year <= $max) ? $year : null;
    }
}
