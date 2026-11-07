<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * Filtros do catálogo de projetos, lidos da query string (`/projetos` e `/api/v1/projetos`).
 *
 * Valor inválido é descartado, não dá erro: a página redireciona para a URL sem ele ({@see self::toQuery()}).
 * O período (`ano_inicial`/`ano_final`) seleciona os projetos em execução em algum ano do intervalo: começam até o
 * ano final e terminam a partir do ano inicial (sem ano de fim, em andamento conta como até hoje; os demais, só o ano
 * de início).
 */
final class ProjectFilter
{
    public const MAX_QUERY_LENGTH = 200;
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2100;

    public function __construct(
        public readonly string $q = '',
        public readonly ?string $status = null,
        public readonly ?string $nature = null,
        public readonly ?string $department = null,
        public readonly ?int $fromYear = null,
        public readonly ?int $toYear = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query Query string crua (`$request->query->all()`)
     * @param list<string> $departmentCodes Códigos de departamento válidos
     */
    public static function fromQuery(array $query, array $departmentCodes): self
    {
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';

        $q = preg_replace('/\s+/u', ' ', $text('q')) ?? '';
        if (mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_QUERY_LENGTH);
        }

        $status = strtoupper($text('situacao'));
        $nature = strtoupper($text('natureza'));
        $department = $text('departamento');
        $from = self::year($text('ano_inicial'));
        $to = self::year($text('ano_final'));
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            q: $q,
            status: isset(ProjectLabels::STATUS[$status]) ? $status : null,
            nature: isset(ProjectLabels::NATURE[$nature]) ? $nature : null,
            department: in_array($department, $departmentCodes, true) ? $department : null,
            fromYear: $from,
            toYear: $to,
        );
    }

    /**
     * Query string canônica, na ordem do formulário e só com os filtros aplicados.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->q,
            'situacao' => (string)$this->status,
            'natureza' => (string)$this->nature,
            'departamento' => (string)$this->department,
            'ano_inicial' => $this->fromYear !== null ? (string)$this->fromYear : '',
            'ano_final' => $this->toYear !== null ? (string)$this->toYear : '',
        ], static fn (string $v): bool => $v !== '');
    }

    public function isEmpty(): bool
    {
        return $this->toQuery() === [];
    }

    private static function year(string $value): ?int
    {
        if (preg_match('/^\d{4}$/', $value) !== 1) {
            return null;
        }
        $year = (int)$value;

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? $year : null;
    }
}
