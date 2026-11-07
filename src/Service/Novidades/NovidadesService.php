<?php

declare(strict_types=1);

namespace App\Service\Novidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Histórico de manutenções do portal (config/novidades.yaml): a página /novidades, a dica de novidade no topo das
 * páginas que mudaram e o selo "Novo" no menu.
 */
final class NovidadesService
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/novidades.yaml')]
        private readonly string $file,
    ) {
    }

    /**
     * Entradas do histórico, da mais recente para a mais antiga.
     *
     * @return list<array{data: string, titulo: string, resumo: string, mudancas: list<string>, commits: list<array{hash: string, texto: string}>}>
     */
    public function entries(): array
    {
        $entries = array_map(fn(array $e) => [
            'data' => $this->date($e['data'] ?? null),
            'titulo' => (string)($e['titulo'] ?? ''),
            'resumo' => (string)($e['resumo'] ?? ''),
            'mudancas' => array_values(array_map('strval', $e['mudancas'] ?? [])),
            'commits' => array_values(array_map(static fn(array $c) => [
                'hash' => (string)($c['hash'] ?? ''),
                'texto' => (string)($c['texto'] ?? ''),
            ], $e['commits'] ?? [])),
        ], $this->load()['entradas'] ?? []);

        usort($entries, static fn($a, $b) => strcmp($b['data'], $a['data']));

        return $entries;
    }

    /** Data da atualização mais recente (AAAA-MM-DD). */
    public function latestDate(): ?string
    {
        return $this->entries()[0]['data'] ?? null;
    }

    /**
     * Dica de novidade da página: o que mudou e como era antes.
     *
     * @return array{id: string, titulo: string, antes: string, agora: string, desde: string}|null
     */
    public function tipFor(?string $route): ?array
    {
        if ($route === null || $route === '') {
            return null;
        }

        foreach ($this->load()['dicas'] ?? [] as $tip) {
            if (in_array($route, $tip['rotas'] ?? [], true)) {
                $since = $this->date($tip['desde'] ?? null);
                $first = (string)(($tip['rotas'] ?? [$route])[0]);

                return [
                    // A dica é a mesma em todas as rotas da lista: "Entendi" vale para todas
                    'id' => 'dica-' . $first . '-' . $since,
                    'titulo' => (string)($tip['titulo'] ?? ''),
                    'antes' => (string)($tip['antes'] ?? ''),
                    'agora' => (string)($tip['agora'] ?? ''),
                    'desde' => $since,
                ];
            }
        }

        return null;
    }

    /** A rota recebe o selo "Novo" no menu (até a data de `destaque_ate`)? */
    public function isNewInMenu(string $route, ?\DateTimeInterface $today = null): bool
    {
        $data = $this->load();
        $until = $this->date($data['destaque_ate'] ?? null);
        $today ??= new \DateTimeImmutable('today');

        return $until !== '' && $today->format('Y-m-d') <= $until
            && in_array($route, $data['novos_no_menu'] ?? [], true);
    }

    /**
     * Todas as rotas citadas nas dicas e no menu (para o teste conferir que existem).
     *
     * @return list<string>
     */
    public function routes(): array
    {
        $data = $this->load();
        $routes = $data['novos_no_menu'] ?? [];
        foreach ($data['dicas'] ?? [] as $tip) {
            array_push($routes, ...($tip['rotas'] ?? []));
        }

        return array_values(array_unique(array_map('strval', $routes)));
    }

    /**
     * Códigos de commit já registrados no histórico.
     *
     * @return list<string>
     */
    public function commitHashes(): array
    {
        $hashes = [];
        foreach ($this->entries() as $entry) {
            foreach ($entry['commits'] as $commit) {
                $hashes[] = $commit['hash'];
            }
        }

        return $hashes;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->data === null) {
            $this->data = is_file($this->file) ? (Yaml::parseFile($this->file, Yaml::PARSE_DATETIME) ?: []) : [];
        }

        return $this->data;
    }

    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value)) {
            return date('Y-m-d', $value);
        }

        return trim((string)$value);
    }
}
