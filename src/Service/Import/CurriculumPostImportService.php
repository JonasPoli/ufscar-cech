<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Researcher;
use App\Service\PageCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O que a importação pela web (telas de currículo e API do marcador do navegador) faz depois de gravar o currículo.
 * São os passos que o `bin/import-curriculos.sh` roda depois do `app:import:lattes`, na parte que cabe numa requisição:
 *
 * 1. reaplica as teses e dissertações do Repositório UFSCar: a importação refaz as orientações do Lattes, e com elas
 *    somem o link e o resumo que o Repositório tinha dado a elas (só roda se o CSV existir no servidor);
 * 2. limpa o cache de páginas públicas, que guarda cada página por 30 dias e mostraria o currículo antigo.
 *
 * Fica de fora o que é pesado demais para uma requisição: o índice temático (`app:index:topics`) e o pipeline de
 * periódicos (`app:journals:pipeline`). {@see self::HEAVY_STEPS_NOTICE} avisa o administrador.
 *
 * A importação já está gravada quando isto roda, então uma falha aqui não a desfaz: vira aviso na resposta.
 */
class CurriculumPostImportService
{
    /** CSV do Repositório Institucional, relativo à raiz do projeto */
    public const REPOSITORY_CSV_PATH = 'docs/banco/TeD-UFSCar.csv';

    /** Frase para a mensagem de sucesso das telas e da API: o que a importação pela web não atualiza */
    public const HEAVY_STEPS_NOTICE = 'A busca temática e o pipeline de revistas não rodam na importação pela web (são pesados). Rode `bin/import-curriculos.sh` ou `app:journals:pipeline` e `app:index:topics` para atualizar temas e rankings.';

    /**
     * Até quantos docentes ganham uma passada própria no CSV. Cada passada lê o arquivo inteiro (~100 MB); num lote maior
     * uma passada só, sobre todos os docentes, custa menos (e é o que o `app:import:repository` faz depois de um lote).
     */
    private const MAX_INDIVIDUAL_PASSES = 3;

    public function __construct(
        private readonly PageCacheService $pageCache,
        private readonly RepositoryImportService $repositoryImport,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * @param Researcher[] $researchers Docentes recém-importados (só o id é usado, então podem estar desanexados do EntityManager)
     *
     * @return array{repository: 'reaplicado'|'sem_csv'|'falhou', cacheCleared: bool, warnings: string[]}
     */
    public function run(array $researchers): array
    {
        $warnings = [];
        $repository = 'sem_csv';

        $csvPath = rtrim($this->projectDir, '/') . '/' . self::REPOSITORY_CSV_PATH;
        if ($researchers !== [] && is_readable($csvPath)) {
            try {
                $this->reapplyRepositoryTheses($researchers, $csvPath);
                $repository = 'reaplicado';
            } catch (\Throwable $e) {
                $repository = 'falhou';
                $this->logger?->error('Teses do Repositório UFSCar não reaplicadas depois da importação: ' . $e->getMessage());
                $warnings[] = 'Importado, mas as teses do Repositório UFSCar não foram reaplicadas (' . $e->getMessage() . '). Rode `app:import:repository` para recuperá-las.';
            }
        }

        // Por último: nenhuma requisição pública grava de novo uma página com o currículo antigo depois da limpeza
        $cacheCleared = $this->pageCache->clearCache();
        if (!$cacheCleared) {
            $warnings[] = 'Importado, mas não foi possível limpar o cache de páginas públicas: as páginas do docente podem mostrar o currículo antigo por até 30 dias. Limpe em /admin/cache.';
        }

        return ['repository' => $repository, 'cacheCleared' => $cacheCleared, 'warnings' => $warnings];
    }

    /**
     * @param Researcher[] $researchers
     */
    private function reapplyRepositoryTheses(array $researchers, string $csvPath): void
    {
        // Em produção (RunCloud) set_time_limit() está em disable_functions; a leitura do CSV inteiro pode passar do limite padrão.
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(300);
        }

        if (count($researchers) <= self::MAX_INDIVIDUAL_PASSES) {
            foreach ($researchers as $researcher) {
                $this->repositoryImport->import(csvFilePath: $csvPath, onlyResearcher: $researcher);
            }

            return;
        }

        $this->repositoryImport->import(csvFilePath: $csvPath);
    }
}
