<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Orientation;
use App\Entity\Researcher;
use App\Repository\OrientationRepository;
use App\Repository\ResearcherRepository;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço de importação, desduplicação e enriquecimento de Teses e Dissertações
 * a partir da base do Repositório Institucional da UFSCar (TeD-UFSCar.csv).
 */
class RepositoryImportService
{
    private const BATCH_SIZE = 150;

    /** @var array<string, Researcher> Mapa de pesquisadores indexados por ID Lattes (16 dígitos) */
    private array $researchersByLattes = [];

    /** @var array<string, Researcher> Mapa de pesquisadores indexados por ORCID */
    private array $researchersByOrcid = [];

    /** @var array<string, Researcher> Mapa de pesquisadores indexados por Nome Normalizado */
    private array $researchersByName = [];

    /** @var array<int, array<int, Orientation>> Cache em memória de orientações por ID de pesquisador */
    private array $orientationsByResearcherId = [];

    /** @var array<string, Orientation> Cache de orientações por Handle */
    private array $orientationsByHandle = [];

    /** @var array<string, Orientation> Cache de orientações por UUID */
    private array $orientationsByUuid = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearcherRepository $researcherRepository,
        private readonly OrientationRepository $orientationRepository
    ) {}

    /**
     * Inicializa os índices em memória para matching ultrarrápido O(1).
     */
    public function initializeIndex(): void
    {
        @ini_set('memory_limit', '1024M');

        $this->researchersByLattes = [];
        $this->researchersByOrcid = [];
        $this->researchersByName = [];
        $this->orientationsByResearcherId = [];
        $this->orientationsByHandle = [];
        $this->orientationsByUuid = [];

        /** @var Researcher[] $researchers */
        $researchers = $this->researcherRepository->findAll();
        foreach ($researchers as $r) {
            $lattesId = trim($r->getIdLattes());
            if ($lattesId !== '') {
                $this->researchersByLattes[$lattesId] = $r;
            }

            $orcid = trim((string)$r->getOrcid());
            if ($orcid !== '') {
                $cleanOrcid = preg_replace('/^https?:\/\/orcid\.org\//i', '', $orcid);
                if ($cleanOrcid) {
                    $this->researchersByOrcid[$cleanOrcid] = $r;
                }
            }

            $normName = StringNormalizer::normalizeString($r->getFullName(), true);
            if ($normName !== '') {
                $this->researchersByName[$normName] = $r;
            }
        }
    }

    /**
     * Obtém as orientações de Mestrado e Doutorado de um pesquisador (com cache em memória).
     *
     * @return Orientation[]
     */
    private function getResearcherOrientations(Researcher $researcher): array
    {
        $rId = $researcher->getId();
        if ($rId === null) {
            return [];
        }

        if (isset($this->orientationsByResearcherId[$rId])) {
            return $this->orientationsByResearcherId[$rId];
        }

        /** @var Orientation[] $orientations */
        $orientations = $this->orientationRepository->findBy([
            'researcher' => $researcher,
            'orientationType' => [Orientation::TYPE_MESTRADO, Orientation::TYPE_DOUTORADO],
        ]);

        $this->orientationsByResearcherId[$rId] = $orientations;

        foreach ($orientations as $o) {
            $h = trim((string)$o->getHandle());
            if ($h !== '') {
                $this->orientationsByHandle[$h] = $o;
            }
            $u = trim((string)$o->getRepositoryUuid());
            if ($u !== '') {
                $this->orientationsByUuid[$u] = $o;
            }
        }

        return $orientations;
    }

    /**
     * Executa a importação do arquivo CSV.
     *
     * @param string $csvFilePath Caminho completo do arquivo CSV
     * @param bool $dryRun Se verdadeiro, não persiste no banco
     * @param int|null $limit Limite de linhas a processar (para testes)
     * @param string|null $centerFilter Filtro opcional de centro acadêmico
     * @param callable|null $progressCallback Callback de progresso function(int $processed, int $total)
     * @return array{
     *     totalCsvRows: int,
     *     processedRows: int,
     *     matchedAdvisorRows: int,
     *     matchedCoadvisorRows: int,
     *     unmatchedRows: int,
     *     enrichedOrientations: int,
     *     newOrientationsCreated: int,
     *     skippedOrientations: int,
     *     errors: array<string>
     * }
     */
    public function import(
        string $csvFilePath,
        bool $dryRun = false,
        ?int $limit = null,
        ?string $centerFilter = null,
        ?callable $progressCallback = null
    ): array {
        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            throw new \InvalidArgumentException(sprintf('Arquivo CSV não encontrado ou sem permissão de leitura: %s', $csvFilePath));
        }

        $this->initializeIndex();

        $fp = fopen($csvFilePath, 'r');
        if ($fp === false) {
            throw new \RuntimeException(sprintf('Falha ao abrir o arquivo: %s', $csvFilePath));
        }

        // Ler cabeçalho
        $header = fgetcsv($fp, 0, ',', '"', '\\');
        if ($header === false || count($header) < 10) {
            fclose($fp);
            throw new \RuntimeException('Cabeçalho do CSV inválido ou arquivo corrompido.');
        }

        $stats = [
            'totalCsvRows' => 0,
            'processedRows' => 0,
            'matchedAdvisorRows' => 0,
            'matchedCoadvisorRows' => 0,
            'unmatchedRows' => 0,
            'enrichedOrientations' => 0,
            'newOrientationsCreated' => 0,
            'skippedOrientations' => 0,
            'errors' => [],
        ];

        $pendingFlushes = 0;
        $centerFilterNorm = $centerFilter ? mb_strtoupper(trim($centerFilter), 'UTF-8') : null;

        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            if (empty($row) || count($row) < 5) {
                continue;
            }

            $stats['totalCsvRows']++;

            if ($limit !== null && $stats['totalCsvRows'] > $limit) {
                break;
            }

            $tipoRaw = trim($row[0] ?? '');
            $titleRaw = trim($row[1] ?? '');
            $altTitleRaw = trim($row[2] ?? '');
            $studentNameRaw = trim($row[3] ?? '');
            $authorLattesRaw = trim($row[4] ?? '');
            $authorOrcidRaw = trim($row[5] ?? '');
            $advNamesRaw = trim($row[6] ?? '');
            $advLattesRaw = trim($row[7] ?? '');
            $advOrcidRaw = trim($row[8] ?? '');
            $coadvNamesRaw = trim($row[9] ?? '');
            $coadvLattesRaw = trim($row[10] ?? '');
            $coadvOrcidRaw = trim($row[11] ?? '');
            $programRaw = trim($row[15] ?? '');
            $centerRaw = trim($row[16] ?? '');
            $campusRaw = trim($row[17] ?? '');
            $defenseDateRaw = trim($row[21] ?? '');
            $abstractRaw = trim($row[22] ?? '');
            $keywordsRaw = trim($row[23] ?? '');
            $doiRaw = trim($row[26] ?? '');
            $urlPersistentRaw = trim($row[28] ?? '');
            $handleRaw = trim($row[29] ?? '');
            $uuidRaw = trim($row[30] ?? '');

            // Filtro por Centro se fornecido
            if ($centerFilterNorm !== null && $centerRaw !== '') {
                $centerUpper = mb_strtoupper($centerRaw, 'UTF-8');
                if (!str_contains($centerUpper, $centerFilterNorm)) {
                    // Se não corresponder ao centro solicitado, pula
                    continue;
                }
            }

            $stats['processedRows']++;

            // Determinar Tipo de Orientação
            $orientationType = (mb_stripos($tipoRaw, 'Tese') !== false)
                ? Orientation::TYPE_DOUTORADO
                : Orientation::TYPE_MESTRADO;

            // Extrair Ano e Data
            $year = null;
            $defenseDate = null;
            if ($defenseDateRaw !== '') {
                $cleanDate = substr($defenseDateRaw, 0, 10);
                $d = \DateTimeImmutable::createFromFormat('Y-m-d', $cleanDate);
                if ($d !== false) {
                    $defenseDate = $d;
                    $year = (int)$d->format('Y');
                } elseif (preg_match('/^(\d{4})/', $defenseDateRaw, $yMatch)) {
                    $year = (int)$yMatch[1];
                }
            }

            // Normalizar URL Handle
            $handleUrl = $urlPersistentRaw ?: ($handleRaw ? sprintf('https://repositorio.ufscar.br/handle/%s', $handleRaw) : null);

            // Identificar Orientador(es)
            $advisors = $this->resolveResearchers($advLattesRaw, $advOrcidRaw, $advNamesRaw);
            if (!empty($advisors)) {
                $stats['matchedAdvisorRows']++;
            }

            // Identificar Coorientador(es)
            $coadvisors = $this->resolveResearchers($coadvLattesRaw, $coadvOrcidRaw, $coadvNamesRaw);
            if (!empty($coadvisors)) {
                $stats['matchedCoadvisorRows']++;
            }

            if (empty($advisors) && empty($coadvisors)) {
                $stats['unmatchedRows']++;
                if ($progressCallback !== null) {
                    $progressCallback($stats['processedRows'], $stats['totalCsvRows']);
                }
                continue;
            }

            // Processar Orientadores Principais
            foreach ($advisors as $advisor) {
                $this->processOrientationItem(
                    researcher: $advisor,
                    isCoadvising: false,
                    orientationType: $orientationType,
                    studentName: $studentNameRaw,
                    title: $titleRaw,
                    alternativeTitle: $altTitleRaw,
                    year: $year,
                    defenseDate: $defenseDate,
                    programName: $programRaw,
                    centerName: $centerRaw,
                    campus: $campusRaw,
                    handle: $handleRaw,
                    handleUrl: $handleUrl,
                    uuid: $uuidRaw,
                    abstractText: $abstractRaw,
                    keywords: $keywordsRaw,
                    doi: $doiRaw,
                    studentOrcid: $authorOrcidRaw,
                    dryRun: $dryRun,
                    stats: $stats,
                    pendingFlushes: $pendingFlushes
                );
            }

            // Processar Coorientadores
            foreach ($coadvisors as $coadvisor) {
                // Evita duplicar se já foi processado como orientador principal
                if (in_array($coadvisor, $advisors, true)) {
                    continue;
                }

                $this->processOrientationItem(
                    researcher: $coadvisor,
                    isCoadvising: true,
                    orientationType: $orientationType,
                    studentName: $studentNameRaw,
                    title: $titleRaw,
                    alternativeTitle: $altTitleRaw,
                    year: $year,
                    defenseDate: $defenseDate,
                    programName: $programRaw,
                    centerName: $centerRaw,
                    campus: $campusRaw,
                    handle: $handleRaw,
                    handleUrl: $handleUrl,
                    uuid: $uuidRaw,
                    abstractText: $abstractRaw,
                    keywords: $keywordsRaw,
                    doi: $doiRaw,
                    studentOrcid: $authorOrcidRaw,
                    dryRun: $dryRun,
                    stats: $stats,
                    pendingFlushes: $pendingFlushes
                );
            }

            // Flush em lote
            if (!$dryRun && $pendingFlushes >= self::BATCH_SIZE) {
                $this->em->flush();
                $pendingFlushes = 0;
            }

            if ($progressCallback !== null) {
                $progressCallback($stats['processedRows'], $stats['totalCsvRows']);
            }
        }

        fclose($fp);

        if (!$dryRun && $pendingFlushes > 0) {
            $this->em->flush();
        }

        return $stats;
    }

    /**
     * Processa um registro de orientação para um docente identificado.
     *
     * @param array<string, mixed> $stats
     */
    private function processOrientationItem(
        Researcher $researcher,
        bool $isCoadvising,
        string $orientationType,
        string $studentName,
        string $title,
        string $alternativeTitle,
        ?int $year,
        ?\DateTimeImmutable $defenseDate,
        string $programName,
        string $centerName,
        string $campus,
        string $handle,
        ?string $handleUrl,
        string $uuid,
        string $abstractText,
        string $keywords,
        string $doi,
        string $studentOrcid,
        bool $dryRun,
        array &$stats,
        int &$pendingFlushes
    ): void {
        $rId = $researcher->getId();
        $existingOrientations = $this->getResearcherOrientations($researcher);

        // 1. Verificação por Handle ou UUID (Idempotência direta)
        if ($handle !== '') {
            foreach ($existingOrientations as $o) {
                if ($o->getHandle() === $handle) {
                    $this->updateExistingMetadata($o, $handleUrl, $uuid, $defenseDate, $programName, $centerName, $campus, $abstractText, $keywords, $doi, $studentOrcid, $alternativeTitle, $handle);
                    $stats['skippedOrientations']++;
                    $pendingFlushes++;
                    return;
                }
            }
        }

        if ($uuid !== '') {
            foreach ($existingOrientations as $o) {
                if ($o->getRepositoryUuid() === $uuid) {
                    $this->updateExistingMetadata($o, $handleUrl, $uuid, $defenseDate, $programName, $centerName, $campus, $abstractText, $keywords, $doi, $studentOrcid, $alternativeTitle, $handle);
                    $stats['skippedOrientations']++;
                    $pendingFlushes++;
                    return;
                }
            }
        }

        // 2. Verificação de Match com Orientação já importada do Lattes
        $normStudent = StringNormalizer::normalizeString($studentName, true);
        $normTitle = StringNormalizer::normalizeString($title, true);

        $matchedOrientation = null;
        foreach ($existingOrientations as $o) {
            if ($o->getOrientationType() !== $orientationType) {
                continue;
            }

            $oStudentNorm = StringNormalizer::normalizeString($o->getStudentName(), true);
            $oTitleNorm = StringNormalizer::normalizeString((string)$o->getTitle(), true);

            $studentMatches = false;
            if ($normStudent !== '' && $oStudentNorm !== '') {
                if ($normStudent === $oStudentNorm) {
                    $studentMatches = true;
                } elseif (str_contains($normStudent, $oStudentNorm) || str_contains($oStudentNorm, $normStudent)) {
                    $studentMatches = true;
                }
            }

            $titleMatches = false;
            if ($normTitle !== '' && $oTitleNorm !== '' && mb_strlen($normTitle) > 15) {
                if ($normTitle === $oTitleNorm || str_contains($normTitle, $oTitleNorm) || str_contains($oTitleNorm, $normTitle)) {
                    $titleMatches = true;
                }
            }

            if ($studentMatches || $titleMatches) {
                $matchedOrientation = $o;
                break;
            }
        }

        if ($matchedOrientation !== null) {
            // Enriquecer registro existente do Lattes
            $this->updateExistingMetadata(
                $matchedOrientation,
                $handleUrl,
                $uuid,
                $defenseDate,
                $programName,
                $centerName,
                $campus,
                $abstractText,
                $keywords,
                $doi,
                $studentOrcid,
                $alternativeTitle,
                $handle
            );
            $stats['enrichedOrientations']++;
            $pendingFlushes++;
            return;
        }

        // 3. Obra nova não presente no Lattes — Criar nova Orientation
        $newOrientation = new Orientation();
        $newOrientation->setResearcher($researcher);
        $newOrientation->setOrientationType($orientationType);
        $newOrientation->setNature(Orientation::NATURE_CONCLUIDA);
        $newOrientation->setStudentName(mb_substr($studentName, 0, 255));
        $newOrientation->setTitle($title ?: null);
        $newOrientation->setAlternativeTitle($alternativeTitle ?: null);
        $newOrientation->setYear($year);
        $newOrientation->setInstitutionName('Universidade Federal de São Carlos');
        $newOrientation->setCourseName($programName ? mb_substr($programName, 0, 255) : null);
        $newOrientation->setHandleUrl($handleUrl ? mb_substr($handleUrl, 0, 255) : null);
        $newOrientation->setHandle($handle ? mb_substr($handle, 0, 100) : null);
        $newOrientation->setRepositoryUuid($uuid ? mb_substr($uuid, 0, 64) : null);
        $newOrientation->setSource(Orientation::SOURCE_REPOSITORY_UFSCAR);
        $newOrientation->setIsCoadvising($isCoadvising);
        $newOrientation->setDefenseDate($defenseDate);
        $newOrientation->setAbstractText($abstractText ?: null);
        $newOrientation->setKeywords($keywords ?: null);
        $newOrientation->setDoi($doi ? mb_substr($doi, 0, 100) : null);
        $newOrientation->setCenterName($centerName ? mb_substr($centerName, 0, 255) : null);
        $newOrientation->setCampus($campus ? mb_substr($campus, 0, 100) : null);
        $newOrientation->setStudentOrcid($studentOrcid ? mb_substr($studentOrcid, 0, 50) : null);

        if (!$dryRun) {
            $this->em->persist($newOrientation);
        }

        // Atualizar cache em memória
        $this->orientationsByResearcherId[$rId][] = $newOrientation;
        if ($handle !== '') {
            $this->orientationsByHandle[$handle] = $newOrientation;
        }
        if ($uuid !== '') {
            $this->orientationsByUuid[$uuid] = $newOrientation;
        }

        $stats['newOrientationsCreated']++;
        $pendingFlushes++;
    }

    /**
     * Atualiza metadados adicionais em uma orientação existente.
     */
    private function updateExistingMetadata(
        Orientation $orientation,
        ?string $handleUrl,
        string $uuid,
        ?\DateTimeImmutable $defenseDate,
        string $programName,
        string $centerName,
        string $campus,
        string $abstractText,
        string $keywords,
        string $doi,
        string $studentOrcid,
        string $alternativeTitle,
        string $handle = ''
    ): void {
        // Obra catalogada no repositório oficial de teses e dissertações atesta conclusão formal da defesa
        if ($orientation->getNature() !== Orientation::NATURE_CONCLUIDA) {
            $orientation->setNature(Orientation::NATURE_CONCLUIDA);
        }

        if ($handleUrl && !$orientation->getHandleUrl()) {
            $orientation->setHandleUrl(mb_substr($handleUrl, 0, 255));
        }
        if ($handle !== '' && !$orientation->getHandle()) {
            $orientation->setHandle(mb_substr($handle, 0, 100));
        }
        if ($uuid !== '' && !$orientation->getRepositoryUuid()) {
            $orientation->setRepositoryUuid(mb_substr($uuid, 0, 64));
        }
        if ($defenseDate !== null && !$orientation->getDefenseDate()) {
            $orientation->setDefenseDate($defenseDate);
        }
        if ($programName !== '' && !$orientation->getCourseName()) {
            $orientation->setCourseName(mb_substr($programName, 0, 255));
        }
        if ($centerName !== '' && !$orientation->getCenterName()) {
            $orientation->setCenterName(mb_substr($centerName, 0, 255));
        }
        if ($campus !== '' && !$orientation->getCampus()) {
            $orientation->setCampus(mb_substr($campus, 0, 100));
        }
        if ($abstractText !== '' && !$orientation->getAbstractText()) {
            $orientation->setAbstractText($abstractText);
        }
        if ($keywords !== '' && !$orientation->getKeywords()) {
            $orientation->setKeywords($keywords);
        }
        if ($doi !== '' && !$orientation->getDoi()) {
            $orientation->setDoi(mb_substr($doi, 0, 100));
        }
        if ($studentOrcid !== '' && !$orientation->getStudentOrcid()) {
            $orientation->setStudentOrcid(mb_substr($studentOrcid, 0, 50));
        }
        if ($alternativeTitle !== '' && !$orientation->getAlternativeTitle()) {
            $orientation->setAlternativeTitle($alternativeTitle);
        }
    }

    /**
     * Resolve uma string de Lattes/ORCID/Nomes em instâncias de Researcher.
     *
     * @return Researcher[] Lista de pesquisadores encontrados
     */
    private function resolveResearchers(string $lattesStr, string $orcidStr, string $namesStr): array
    {
        $found = [];

        // 1. Extração por ID Lattes (16 dígitos)
        if ($lattesStr !== '') {
            if (preg_match_all('/\d{16}/', $lattesStr, $matches)) {
                foreach ($matches[0] as $lattesId) {
                    if (isset($this->researchersByLattes[$lattesId])) {
                        $r = $this->researchersByLattes[$lattesId];
                        $found[$r->getId()] = $r;
                    }
                }
            }
        }

        // 2. Extração por ORCID
        if ($orcidStr !== '') {
            if (preg_match_all('/\d{4}-\d{4}-\d{4}-[\dX]{4}/', $orcidStr, $matches)) {
                foreach ($matches[0] as $orcid) {
                    if (isset($this->researchersByOrcid[$orcid])) {
                        $r = $this->researchersByOrcid[$orcid];
                        $found[$r->getId()] = $r;
                    }
                }
            }
        }

        // 3. Fallback por Nome Normalizado
        if (empty($found) && $namesStr !== '') {
            $names = explode(';', $namesStr);
            foreach ($names as $name) {
                $norm = StringNormalizer::normalizeString(trim($name), true);
                if ($norm !== '' && isset($this->researchersByName[$norm])) {
                    $r = $this->researchersByName[$norm];
                    $found[$r->getId()] = $r;
                }
            }
        }

        return array_values($found);
    }
}
