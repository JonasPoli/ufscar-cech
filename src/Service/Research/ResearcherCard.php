<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Repository\ResearcherRepository;

/**
 * Dados de exibição de um docente nas listas da pesquisa pública (busca, rede, assuntos, API), a partir de uma linha
 * de `researchers`. O vínculo vem da planilha do CECH: `status` 1 = vigente; `admission_year` e `leave_year` são os
 * anos de início e fim no CECH (no vínculo vigente, o fim é o ano da planilha e não entra no período).
 */
final class ResearcherCard
{
    /**
     * @param array<string, mixed> $row colunas de `researchers` (id, full_name, slug, id_lattes, department_code, department, status, admission_year, leave_year, photo_url)
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        $code = trim((string)($row['department_code'] ?? ''));
        if ($code === 'DCSo') {
            $code = 'CS';
        }
        $department = ResearcherRepository::DEPARTMENT_MAP[$code] ?? trim((string)($row['department'] ?? ''));
        $slug = trim((string)($row['slug'] ?? ''));
        $name = trim((string)($row['full_name'] ?? ''));
        $active = (int)($row['status'] ?? 1) === 1;

        return [
            'id' => (int)$row['id'],
            'name' => $name,
            'slug' => $slug !== '' ? $slug : (string)($row['id_lattes'] ?? $row['id']),
            'initials' => self::initials($name),
            'departmentCode' => $code,
            'department' => $department,
            'photoUrl' => $row['photo_url'] ?? null,
            'affiliation' => $active ? 'vigente' : 'encerrado',
            'affiliationLabel' => $active ? 'Vínculo vigente' : 'Vínculo encerrado',
            'period' => self::period($active, $row['admission_year'] ?? null, $row['leave_year'] ?? null),
        ];
    }

    public static function period(bool $active, mixed $start, mixed $end): ?string
    {
        $start = (int)$start > 0 ? (int)$start : null;
        $end = (int)$end > 0 ? (int)$end : null;

        if ($active) {
            return $start !== null ? $start . '–hoje' : null;
        }
        if ($start !== null && $end !== null) {
            return $start . '–' . $end;
        }
        if ($end !== null) {
            return 'até ' . $end;
        }

        return $start !== null ? 'desde ' . $start : null;
    }

    public static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', $name) ?: [], static fn($p) => mb_strlen($p) > 2));
        if ($parts === []) {
            return mb_strtoupper(mb_substr($name, 0, 2));
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    /** Colunas de `researchers` que {@see fromRow()} usa, com o alias informado. */
    public static function columns(string $r = 'r'): string
    {
        return "{$r}.id, {$r}.full_name, {$r}.slug, {$r}.id_lattes, {$r}.department_code, {$r}.department, "
            . "{$r}.status, {$r}.admission_year, {$r}.leave_year, {$r}.photo_url";
    }
}
