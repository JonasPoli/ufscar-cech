<?php

namespace App\Service;

use App\Service\Thesaurus\CountryResolverService;
use App\Service\Thesaurus\InstitutionResolverService;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço analítico para consolidação e cálculo de indicadores cienciométricos e estatísticas do CECH.
 *
 * Provê dados estruturados para renderização de gráficos interativos (ApexCharts / Chart.js / Sankey / Heatmap)
 * cobrindo:
 * - Resumo executivo global (pesquisadores, artigos, livros, orientações).
 * - Trajetória de formação acadêmica (graduação, mestrado, doutorado, pós-doutorado).
 * - Séries temporais de produção e estratos Qualis CAPES (A1 a C).
 * - Matriz de calor anual de produção por tipo de item.
 * - Redes de coautoria interna entre docentes do CECH e parcerias nacionais/internacionais.
 * - Fluxos de formação e destinos acadêmicos (diagramas de Sankey).
 */
class StatisticsService
{
    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param InstitutionResolverService $institutionResolver Serviço de resolução institucional
     * @param CountryResolverService $countryResolver Serviço de resolução de países
     * @param JournalResolverService $journalResolver Serviço de resolução de periódicos Qualis
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstitutionResolverService $institutionResolver,
        private readonly CountryResolverService $countryResolver,
        private readonly JournalResolverService $journalResolver
    ) {}

    /**
     * Normaliza e consolida variações textuais de cursos de graduação e pós-graduação sob denominações canônicas.
     *
     * @param string|null $course Nome bruto do curso como veio no Lattes
     * @return string Nome canônico padronizado do curso
     */
    public static function normalizeCourseName(?string $course): string
    {
        if ($course === null || trim($course) === '') {
            return '';
        }

        $c = trim($course);
        $norm = StringNormalizer::normalizeString($c, false);

        // Regras de consolidação canônica (Thesaurus de Cursos)
        if (str_contains($norm, 'pedagogia') || str_contains($norm, 'complementacao pedagogica')) {
            return 'Pedagogia';
        }
        if (str_contains($norm, 'ciencias sociais') || str_contains($norm, 'social sciences') || str_contains($norm, 'estudos sociais') || str_contains($norm, 'ciencias politicas e sociais')) {
            return 'Ciências Sociais';
        }
        if (str_contains($norm, 'psicologia') || str_contains($norm, 'psicologo')) {
            return 'Psicologia';
        }
        if (str_contains($norm, 'filosofia') || str_contains($norm, 'philosophy')) {
            return 'Filosofia';
        }
        if (str_contains($norm, 'linguistica')) {
            return 'Linguística';
        }
        if (str_contains($norm, 'letras') || str_contains($norm, 'lingua portuguesa') || str_contains($norm, 'portugues') || str_contains($norm, 'frances') || str_contains($norm, 'espanhol') || str_contains($norm, 'ingles') || str_contains($norm, 'alemao') || str_contains($norm, 'sanscrito')) {
            return 'Letras';
        }
        if (str_contains($norm, 'historia')) {
            return 'História';
        }
        if (str_contains($norm, 'direito') || str_contains($norm, 'ciencias juridicas')) {
            return 'Direito';
        }
        if (str_contains($norm, 'biblioteconomia') || str_contains($norm, 'ciencia da informacao') || str_contains($norm, 'estudos de documentacao')) {
            return 'Biblioteconomia';
        }
        if (str_contains($norm, 'educacao especial')) {
            return 'Educação Especial';
        }
        if (str_contains($norm, 'educacao escolar')) {
            return 'Educação Escolar';
        }
        if (str_contains($norm, 'educacao fisica')) {
            return 'Educação Física';
        }
        if (str_contains($norm, 'educacao') || str_contains($norm, 'education')) {
            return 'Educação';
        }
        if (str_contains($norm, 'imagem e som') || str_contains($norm, 'cinema') || str_contains($norm, 'audiovisual')) {
            return 'Imagem e Som / Audiovisual';
        }
        if (str_contains($norm, 'musica') || str_contains($norm, 'piano') || str_contains($norm, 'clarineta') || str_contains($norm, 'flauta doce')) {
            return 'Música';
        }
        if (str_contains($norm, 'sociologia')) {
            return 'Sociologia';
        }
        if (str_contains($norm, 'ciencia politica') || str_contains($norm, 'ciencias politicas')) {
            return 'Ciência Política';
        }
        if (str_contains($norm, 'antropologia')) {
            return 'Antropologia';
        }
        if (str_contains($norm, 'engenharia de materiais')) {
            return 'Engenharia de Materiais';
        }
        if (str_contains($norm, 'engenharia mecanica')) {
            return 'Engenharia Mecânica';
        }
        if (str_contains($norm, 'engenharia de producao')) {
            return 'Engenharia de Produção';
        }
        if (str_contains($norm, 'engenharia eletrica')) {
            return 'Engenharia Elétrica';
        }
        if (str_contains($norm, 'engenharia metalurgica') || str_contains($norm, 'metalurgica')) {
            return 'Engenharia Metalúrgica';
        }
        if (str_contains($norm, 'engenharia mecatronica')) {
            return 'Engenharia Mecatrônica';
        }
        if (str_contains($norm, 'engenharia') || str_contains($norm, 'escola de engenharia')) {
            return 'Engenharia';
        }
        if (str_contains($norm, 'comunicacao social') || str_contains($norm, 'jornalismo') || str_contains($norm, 'relacoes publicas')) {
            return 'Comunicação Social / Jornalismo';
        }
        if (str_contains($norm, 'administracao')) {
            return 'Administração';
        }
        if (str_contains($norm, 'economia') || str_contains($norm, 'ciencias economicas')) {
            return 'Ciências Econômicas';
        }
        if (str_contains($norm, 'artes visuais') || str_contains($norm, 'artes plasticas') || str_contains($norm, 'artes cenicas') || str_contains($norm, 'desenho e plastica')) {
            return 'Artes Visuais & Cênicas';
        }
        if (str_contains($norm, 'fisica') || str_contains($norm, 'ciencias exatas')) {
            return 'Física';
        }
        if (str_contains($norm, 'quimica')) {
            return 'Química';
        }
        if (str_contains($norm, 'matematica')) {
            return 'Matemática';
        }
        if (str_contains($norm, 'biologia') || str_contains($norm, 'ciencias biologicas')) {
            return 'Ciências Biológicas';
        }
        if (str_contains($norm, 'ciencia da computacao') || str_contains($norm, 'processamento de dados') || str_contains($norm, 'tecnologia da informacao')) {
            return 'Ciência da Computação';
        }
        if (str_contains($norm, 'ciencia de dados')) {
            return 'Ciência de Dados';
        }
        if (str_contains($norm, 'fonoaudiologia')) {
            return 'Fonoaudiologia';
        }
        if (str_contains($norm, 'enfermagem') || str_contains($norm, 'obstetricia')) {
            return 'Enfermagem';
        }
        if (str_contains($norm, 'medicina veterinaria')) {
            return 'Medicina Veterinária';
        }
        if (str_contains($norm, 'zootecnia')) {
            return 'Zootecnia';
        }
        if (str_contains($norm, 'odontologia')) {
            return 'Odontologia';
        }
        if (str_contains($norm, 'gestao e analise ambiental')) {
            return 'Gestão e Análise Ambiental';
        }

        return ucwords(mb_strtolower($c, 'UTF-8'));
    }

    /**
     * Identifica a Grande Área do Conhecimento (CNPq) para um determinado curso normalizado.
     */
    public static function getMajorKnowledgeAreaForCourse(string $courseName): string
    {
        $norm = StringNormalizer::normalizeString($courseName, false);

        // Ciências da Saúde (verificar antes para Educação Física não colidir com Educação ou Física)
        if (str_contains($norm, 'educacao fisica') || str_contains($norm, 'fonoaudiologia') || str_contains($norm, 'enfermagem') || str_contains($norm, 'medicina') || str_contains($norm, 'odontologia') || str_contains($norm, 'fisioterapia') || str_contains($norm, 'terapia ocupacional') || str_contains($norm, 'nutricao') || str_contains($norm, 'farmacia') || str_contains($norm, 'saude')) {
            return 'Ciências da Saúde';
        }

        // Engenharias
        if (str_contains($norm, 'engenharia') || str_contains($norm, 'mecanica') || str_contains($norm, 'eletrica') || str_contains($norm, 'materiais') || str_contains($norm, 'metalurgica') || str_contains($norm, 'mecatronica') || str_contains($norm, 'producao')) {
            return 'Engenharias';
        }

        // Linguística, Letras e Artes
        if (str_contains($norm, 'letras') || str_contains($norm, 'linguistica') || str_contains($norm, 'musica') || str_contains($norm, 'artes') || str_contains($norm, 'desenho') || str_contains($norm, 'cinema') || str_contains($norm, 'audiovisual') || str_contains($norm, 'teatro') || str_contains($norm, 'danca')) {
            return 'Linguística, Letras e Artes';
        }

        // Ciências Sociais Aplicadas
        if (str_contains($norm, 'direito') || str_contains($norm, 'juridica') || str_contains($norm, 'biblioteconomia') || str_contains($norm, 'comunicacao') || str_contains($norm, 'jornalismo') || str_contains($norm, 'administracao') || str_contains($norm, 'economia') || str_contains($norm, 'economica') || str_contains($norm, 'relacoes publicas') || str_contains($norm, 'publicidade') || str_contains($norm, 'arquitetura') || str_contains($norm, 'servico social')) {
            return 'Ciências Sociais Aplicadas';
        }

        // Ciências Humanas
        if (str_contains($norm, 'pedagogia') || str_contains($norm, 'ciencias sociais') || str_contains($norm, 'sociologia') || str_contains($norm, 'filosofia') || str_contains($norm, 'psicologia') || str_contains($norm, 'historia') || str_contains($norm, 'educacao especial') || str_contains($norm, 'educacao escolar') || str_contains($norm, 'educacao') || str_contains($norm, 'antropologia') || str_contains($norm, 'ciencia politica') || str_contains($norm, 'geografia') || str_contains($norm, 'teologia')) {
            return 'Ciências Humanas';
        }

        // Ciências Exatas e da Terra
        if (str_contains($norm, 'fisica') || str_contains($norm, 'matematica') || str_contains($norm, 'quimica') || str_contains($norm, 'computacao') || str_contains($norm, 'dados') || str_contains($norm, 'geologia') || str_contains($norm, 'estatistica') || str_contains($norm, 'informatica')) {
            return 'Ciências Exatas e da Terra';
        }

        // Ciências Biológicas
        if (str_contains($norm, 'biolog') || str_contains($norm, 'biomedicina') || str_contains($norm, 'ecologia') || str_contains($norm, 'genetica') || str_contains($norm, 'botanica') || str_contains($norm, 'zoologia')) {
            return 'Ciências Biológicas';
        }

        // Ciências Agrárias
        if (str_contains($norm, 'agraria') || str_contains($norm, 'agronomia') || str_contains($norm, 'veterinaria') || str_contains($norm, 'zootecnia') || str_contains($norm, 'florestal') || str_contains($norm, 'pesca')) {
            return 'Ciências Agrárias';
        }

        return 'Outras';
    }

    /**
     * Resumo quantitativo global do centro (com contagem bruta declarada e contagem única deduplicada institucional).
     */
    public function getGlobalSummary(): array
    {
        $conn = $this->em->getConnection();

        $researchersCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM researchers WHERE status = 1");
        $allResearchersCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM researchers");
        $productionsCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items");
        $articlesCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO'");
        $articlesQualisCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO' AND qualis IS NOT NULL AND qualis != ''");
        $orientationsCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE nature = 'CONCLUIDA'");
        $booksCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'LIVRO'");
        $chaptersCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'CAPITULO'");
        $booksAndChaptersCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type IN ('LIVRO', 'CAPITULO')");
        $eventsCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'EVENTO'");

        // Unique deduplicated counts (Institutional CECH View)
        $uniqueProductionsCount = (int)$conn->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM production_items
                GROUP BY 
                    CASE 
                        WHEN (doi IS NOT NULL AND TRIM(doi) != '') 
                        THEN CONCAT('DOI:', LOWER(TRIM(doi)))
                        ELSE CONCAT('TITLE:', item_type, ':', COALESCE(`year`, ''), ':', LOWER(TRIM(title)))
                    END
            ) as unique_prods
        ");

        $uniqueArticlesQualisCount = (int)$conn->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM production_items
                WHERE item_type = 'ARTIGO' AND qualis IS NOT NULL AND qualis != ''
                GROUP BY 
                    CASE 
                        WHEN (doi IS NOT NULL AND TRIM(doi) != '') 
                        THEN CONCAT('DOI:', LOWER(TRIM(doi)))
                        ELSE CONCAT('TITLE:', COALESCE(`year`, ''), ':', LOWER(TRIM(title)))
                    END
            ) as unique_qualis
        ");

        $uniqueBooksCount = (int)$conn->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT 1
                FROM production_items
                WHERE item_type IN ('LIVRO', 'CAPITULO')
                GROUP BY 
                    CASE 
                        WHEN (isbn IS NOT NULL AND TRIM(isbn) != '') 
                        THEN CONCAT('ISBN:', item_type, ':', LOWER(TRIM(isbn)), ':', LOWER(TRIM(title)))
                        ELSE CONCAT('TITLE:', item_type, ':', COALESCE(`year`, ''), ':', LOWER(TRIM(title)))
                    END
            ) as unique_books
        ");

        return [
            'totalResearchers' => $researchersCount,
            'allResearchers' => $allResearchersCount,
            'totalProductions' => $productionsCount,
            'uniqueProductions' => $uniqueProductionsCount,
            'totalArticles' => $articlesCount,
            'totalArticlesQualis' => $articlesQualisCount,
            'uniqueArticlesQualis' => $uniqueArticlesQualisCount,
            'totalBooks' => $booksCount,
            'totalChapters' => $chaptersCount,
            'totalBooksAndChapters' => $booksAndChaptersCount,
            'uniqueBooksAndChapters' => $uniqueBooksCount,
            'totalOrientations' => $orientationsCount,
            'totalEvents' => $eventsCount,
        ];
    }

    /**
     * Fig. 1: Vínculos institucionais e departamentais do corpo docente.
     */
    public function getFig1InstitutionalAffiliations(): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                COALESCE(NULLIF(department_code, ''), department, 'Outros') as deptCode,
                COALESCE(department, department_code, 'Outros Departamentos') as deptName,
                COUNT(*) as total
            FROM researchers
            WHERE status = 1
            GROUP BY deptCode, deptName
            ORDER BY total DESC
        ";

        $rows = $conn->fetchAllAssociative($sql);

        $departmentNames = [
            'PS' => 'Departamento de Psicologia (DPsi)',
            'DPsi' => 'Departamento de Psicologia (DPsi)',
            'LE' => 'Departamento de Letras (DL)',
            'DL' => 'Departamento de Letras (DL)',
            'CS' => 'Departamento de Ciências Sociais (DCSo)',
            'DCSo' => 'Departamento de Ciências Sociais (DCSo)',
            'DCS' => 'Departamento de Ciências Sociais (DCSo)',
            'AC' => 'Departamento de Artes e Comunicação (DAC)',
            'DAC' => 'Departamento de Artes e Comunicação (DAC)',
            'IFD' => 'Departamento de Metodologia de Ensino / Formação Docente',
            'DME' => 'Departamento de Metodologia de Ensino (DME)',
            'ED' => 'Departamento de Educação (DEd)',
            'DEd' => 'Departamento de Educação (DEd)',
            'DEC' => 'Departamento de Educação e Comunicação (DEC)',
            'TPP' => 'Departamento de Teoria e Prática Pedagógica (DTPP)',
            'DTPP' => 'Departamento de Teoria e Prática Pedagógica (DTPP)',
            'DTE' => 'Departamento de Teoria e Prática da Educação (DTE)',
            'CI' => 'Departamento de Ciência da Informação (DCI)',
            'DCI' => 'Departamento de Ciência da Informação (DCI)',
            'FI' => 'Departamento de Filosofia (DFil)',
            'FIL' => 'Departamento de Filosofia (DFil)',
            'DFil' => 'Departamento de Filosofia (DFil)',
            'SO' => 'Departamento de Sociologia (DSo)',
            'DSo' => 'Departamento de Sociologia (DSo)',
            'CA' => 'Departamento de Ciências Ambientais (DCAm)',
            'DCA' => 'Departamento de Ciências Ambientais (DCAm)',
            'DCAm' => 'Departamento de Ciências Ambientais (DCAm)',
            'Outros' => 'Outros Departamentos / Sem Lotação Direta',
        ];

        $aggregated = [];
        foreach ($rows as $r) {
            $code = trim((string)$r['deptCode']);
            if ($code === 'DCSo') {
                $code = 'CS';
            }
            if ($code === '') {
                $code = 'Outros';
            }

            $rawName = trim((string)$r['deptName']);
            $name = $departmentNames[$code] ?? $rawName;
            if (preg_match('/^Departamento \(([A-Za-z]+)\)$/', $name, $m)) {
                $name = $departmentNames[$m[1]] ?? $name;
            }

            if (!isset($aggregated[$code])) {
                $aggregated[$code] = [
                    'deptCode' => $code,
                    'deptName' => $name,
                    'total' => 0,
                ];
            }
            $aggregated[$code]['total'] += (int)$r['total'];
        }

        usort($aggregated, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_values($aggregated);
    }

    /**
     * Fig. 2: Formação de graduação do corpo docente (Com Agrupamento por Grande Área).
     * Retorna lista estruturada: [ ['area' => '...', 'formacao' => '...', 'quantidade' => N], ... ]
     */
    public function getFig2UndergraduateDegrees(int $limit = 0): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT course_name as courseName, COUNT(*) as cnt
            FROM educations
            WHERE level = 'GRADUACAO' 
              AND course_name IS NOT NULL 
              AND TRIM(course_name) != ''
            GROUP BY course_name
        ";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];

        foreach ($rows as $r) {
            $canonical = self::normalizeCourseName($r['courseName']);
            if ($canonical === '') continue;
            if (!isset($aggregated[$canonical])) {
                $aggregated[$canonical] = 0;
            }
            $aggregated[$canonical] += (int)$r['cnt'];
        }

        $itemsByArea = [];
        foreach ($aggregated as $name => $total) {
            $area = self::getMajorKnowledgeAreaForCourse($name);
            if (!isset($itemsByArea[$area])) {
                $itemsByArea[$area] = [];
            }
            $itemsByArea[$area][] = [
                'area' => $area,
                'formacao' => $name,
                'quantidade' => $total,
            ];
        }

        // Definir ordem canônica das grandes áreas
        $areaPriority = [
            'Ciências Humanas' => 1,
            'Ciências Sociais Aplicadas' => 2,
            'Linguística, Letras e Artes' => 3,
            'Engenharias' => 4,
            'Ciências Exatas e da Terra' => 5,
            'Ciências da Saúde' => 6,
            'Ciências Biológicas' => 7,
            'Ciências Agrárias' => 8,
            'Outras' => 9,
        ];

        uksort($itemsByArea, function ($a, $b) use ($areaPriority) {
            $pA = $areaPriority[$a] ?? 99;
            $pB = $areaPriority[$b] ?? 99;
            if ($pA !== $pB) {
                return $pA <=> $pB;
            }
            return strcasecmp($a, $b);
        });

        $result = [];
        foreach ($itemsByArea as $area => $items) {
            usort($items, function ($a, $b) {
                if ($b['quantidade'] !== $a['quantidade']) {
                    return $b['quantidade'] <=> $a['quantidade'];
                }
                return strcasecmp($a['formacao'], $b['formacao']);
            });

            if ($limit > 0) {
                $items = array_slice($items, 0, $limit);
            }

            foreach ($items as $item) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Fig. 3: Formação de doutorado do corpo docente (Com Tesauro de Cursos).
     */
    public function getFig3DoctorateDegrees(int $limit = 12): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT course_name as courseName, COUNT(*) as cnt
            FROM educations
            WHERE level = 'DOUTORADO' 
              AND course_name IS NOT NULL 
              AND TRIM(course_name) != ''
            GROUP BY course_name
        ";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];

        foreach ($rows as $r) {
            $canonical = self::normalizeCourseName($r['courseName']);
            if ($canonical === '') continue;
            if (!isset($aggregated[$canonical])) {
                $aggregated[$canonical] = 0;
            }
            $aggregated[$canonical] += (int)$r['cnt'];
        }

        arsort($aggregated);

        $result = [];
        foreach (array_slice($aggregated, 0, $limit, true) as $name => $total) {
            $result[] = [
                'courseName' => $name,
                'total' => $total
            ];
        }

        return $result;
    }

    /**
     * Fig. 4: Áreas de atuação dos docentes (Áreas do Conhecimento CNPq).
     */
    public function getFig4KnowledgeAreas(): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                COALESCE(NULLIF(major_area, ''), 'Outras') as majorArea,
                COUNT(*) as total
            FROM knowledge_areas
            GROUP BY majorArea
            ORDER BY total DESC
        ";

        $rows = $conn->fetchAllAssociative($sql);

        $areaNames = [
            'CIENCIAS_HUMANAS' => 'Ciências Humanas',
            'LINGUISTICA_LETRAS_E_ARTES' => 'Linguística, Letras e Artes',
            'CIENCIAS_SOCIAIS_APLICADAS' => 'Ciências Sociais Aplicadas',
            'CIENCIAS_DA_SAUDE' => 'Ciências da Saúde',
            'CIENCIAS_EXATAS_E_DA_TERRA' => 'Ciências Exatas e da Terra',
            'ENGENHARIAS' => 'Engenharias',
            'CIENCIAS_BIOLOGICAS' => 'Ciências Biológicas',
            'Ciências Agrárias' => 'Ciências Agrárias',
            'OUTROS' => 'Outras / Multidisciplinar',
        ];

        $result = [];
        foreach ($rows as $r) {
            $key = $r['majorArea'];
            $label = $areaNames[$key] ?? ucwords(strtolower(str_replace('_', ' ', $key)));
            $result[] = [
                'areaKey' => $key,
                'areaName' => $label,
                'total' => (int)$r['total']
            ];
        }

        return $result;
    }

    /**
     * Fig. 5: Formação dos estudantes orientados (Com Tesauro de Cursos).
     */
    public function getFig5StudentsUndergradCourses(int $limit = 12): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT course_name as courseName, COUNT(*) as cnt
            FROM orientations
            WHERE course_name IS NOT NULL 
              AND TRIM(course_name) != ''
            GROUP BY course_name
        ";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];

        foreach ($rows as $r) {
            $canonical = self::normalizeCourseName($r['courseName']);
            if ($canonical === '') continue;
            if (!isset($aggregated[$canonical])) {
                $aggregated[$canonical] = 0;
            }
            $aggregated[$canonical] += (int)$r['cnt'];
        }

        arsort($aggregated);

        $result = [];
        foreach (array_slice($aggregated, 0, $limit, true) as $name => $total) {
            $result[] = [
                'courseName' => $name,
                'total' => $total
            ];
        }

        return $result;
    }

    /**
     * Fig. 6: Distribuição geográfica dos pesquisadores por estado (UF).
     */
    public function getFig6GeographicalDistribution(): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                birth_state as uf,
                COUNT(*) as total
            FROM researchers
            WHERE birth_state IS NOT NULL 
              AND TRIM(birth_state) != ''
            GROUP BY birth_state
            ORDER BY total DESC
            LIMIT 15
        ";

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Fig. 7: Formação de mestres e doutores (Evolução Anual de Orientações Concluídas).
     */
    public function getFig7OrientationsConcludedByYear(int $startYear = 2010, int $endYear = 2026): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                `year`,
                orientation_type as orientType,
                COUNT(*) as total
            FROM orientations
            WHERE nature = 'CONCLUIDA'
              AND orientation_type IN ('MESTRADO', 'DOUTORADO', 'POS_DOUTORADO', 'INICIACAO_CIENTIFICA')
              AND `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`, orientType
            ORDER BY `year` ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);

        $years = range($startYear, $endYear);
        $types = ['MESTRADO' => 'Mestrado', 'DOUTORADO' => 'Doutorado', 'POS_DOUTORADO' => 'Pós-Doutorado', 'INICIACAO_CIENTIFICA' => 'Iniciação Científica'];
        
        $dataMatrix = [];
        foreach ($types as $tKey => $tLabel) {
            $dataMatrix[$tKey] = array_fill_keys($years, 0);
        }

        foreach ($rows as $r) {
            $y = (int)$r['year'];
            $t = $r['orientType'];
            if (isset($dataMatrix[$t][$y])) {
                $dataMatrix[$t][$y] = (int)$r['total'];
            }
        }

        return [
            'years' => $years,
            'series' => $dataMatrix,
            'labels' => $types
        ];
    }

    /**
     * Fig. 8: Inserção profissional e tipos de vínculos funcionais.
     */
    public function getFig8ProfessionalExperiences(): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                COALESCE(NULLIF(contract_type, ''), 'Outro Vínculo') as contractType,
                COUNT(*) as total
            FROM professional_experiences
            GROUP BY contractType
            ORDER BY total DESC
            LIMIT 8
        ";

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Fig. 9: Pirâmide / Fluxo de Formação Acadêmica (IC -> Graduação -> Mestrado -> Doutorado -> Pós-Doc).
     */
    public function getFig9AcademicLevelsPyramid(): array
    {
        $conn = $this->em->getConnection();

        $levels = [
            'Iniciação Científica' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'INICIACAO_CIENTIFICA'"),
            'TCC / Graduação' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'TCC_GRADUACAO'"),
            'Especialização' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'ESPECIALIZACAO'"),
            'Mestrado' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'MESTRADO'"),
            'Doutorado' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'DOUTORADO'"),
            'Pós-Doutorado' => (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE orientation_type = 'POS_DOUTORADO'"),
        ];

        return $levels;
    }

    /**
     * Fig. 10: Matriz Geral de Produção Científica, Técnica e Artística (Heatmap Matrix).
     */
    public function getFig10ProductionHeatmapMatrix(int $startYear = 2010, int $endYear = 2026): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                `year`,
                item_type as itemType,
                COUNT(*) as total
            FROM production_items
            WHERE `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`, itemType
            ORDER BY `year` DESC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);

        $typeLabels = [
            'ARTIGO' => 'Artigos em Periódicos',
            'LIVRO' => 'Livros Publicados',
            'CAPITULO' => 'Capítulos de Livros',
            'EVENTO' => 'Trabalhos em Eventos',
            'TRABALHO_TECNICO' => 'Trabalhos Técnicos',
            'TEXTO_JORNAL' => 'Textos em Jornais/Revistas',
            'PRODUCAO_ARTISTICA' => 'Produção Artística/Cultural',
            'SOFTWARE' => 'Softwares & Patentes',
            'OUTRA' => 'Outras Produções'
        ];

        $years = range($endYear, $startYear); // desc
        $matrix = [];
        $columnTotals = array_fill_keys(array_keys($typeLabels), 0);
        $rowTotals = [];

        foreach ($years as $y) {
            $matrix[$y] = array_fill_keys(array_keys($typeLabels), 0);
            $rowTotals[$y] = 0;
        }

        foreach ($rows as $r) {
            $y = (int)$r['year'];
            $t = $r['itemType'];
            $cnt = (int)$r['total'];
            if (isset($matrix[$y][$t])) {
                $matrix[$y][$t] = $cnt;
                $columnTotals[$t] += $cnt;
                $rowTotals[$y] += $cnt;
            }
        }

        return [
            'years' => $years,
            'types' => $typeLabels,
            'matrix' => $matrix,
            'columnTotals' => $columnTotals,
            'rowTotals' => $rowTotals,
            'grandTotal' => array_sum($rowTotals),
        ];
    }

    /**
     * Fig. 11: Produção Científica: Qualis vs Sem Qualis (Com Tesauro Qualis Periódicos).
     */
    public function getFig11QualisVsNonQualisTimeline(int $startYear = 2010, int $endYear = 2026): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                `year`,
                CASE WHEN (qualis IS NOT NULL AND qualis != '') THEN 'COM_QUALIS' ELSE 'SEM_QUALIS' END as statusQualis,
                COUNT(*) as total
            FROM production_items
            WHERE item_type = 'ARTIGO'
              AND `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`, statusQualis
            ORDER BY `year` ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);
        $years = range($startYear, $endYear);
        
        $withQualis = array_fill_keys($years, 0);
        $withoutQualis = array_fill_keys($years, 0);

        foreach ($rows as $r) {
            $y = (int)$r['year'];
            if ($r['statusQualis'] === 'COM_QUALIS') {
                $withQualis[$y] = (int)$r['total'];
            } else {
                $withoutQualis[$y] = (int)$r['total'];
            }
        }

        return [
            'years' => $years,
            'withQualis' => array_values($withQualis),
            'withoutQualis' => array_values($withoutQualis),
        ];
    }

    /**
     * Fig. 12: Distribuição dos Artigos por Estrato Qualis A1 a C (Com Tesauro Qualis CAPES).
     */
    public function getFig12QualisStratumTimeline(int $startYear = 2010, int $endYear = 2026): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                `year`,
                qualis,
                COUNT(*) as total
            FROM production_items
            WHERE item_type = 'ARTIGO'
              AND qualis IS NOT NULL AND qualis != ''
              AND `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`, qualis
            ORDER BY `year` ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);
        $years = range($startYear, $endYear);
        $strata = ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C'];
        
        $series = [];
        foreach ($strata as $st) {
            $series[$st] = array_fill_keys($years, 0);
        }

        foreach ($rows as $r) {
            $y = (int)$r['year'];
            $st = strtoupper(trim($r['qualis']));
            if (isset($series[$st][$y])) {
                $series[$st][$y] = (int)$r['total'];
            }
        }

        $strataTotals = [];
        foreach ($strata as $st) {
            $strataTotals[$st] = array_sum($series[$st]);
        }

        return [
            'years' => $years,
            'strata' => $strata,
            'series' => $series,
            'strataTotals' => $strataTotals,
        ];
    }

    /**
     * Relatório de Ranking Dinâmico de Pesquisadores por Estratos Qualis A1 a A4 e Indexadores (Bases de Dados).
     *
     * Retorna pesquisadores com a lista detalhada de seus artigos (qualis + bases associadas) para recalcular
     * dinamicamente combinações arbitrárias de Qualis (OR) e Indexadores (OR / AND).
     *
     * @return array{
     *     databases: list<array{id: int, name: string, acronym: string}>,
     *     researchers: list<array{id: int, name: string, department: string, departmentCode: string, slug: string, lattesId: string, photoUrl: ?string, articles: list<array{id: int, qualis: string, dbIds: list<int>}>}>
     * }
     */
    public function getFigQualisResearchersRanking(): array
    {
        $conn = $this->em->getConnection();

        // 1. Obter apenas as bases de dados ativas que possuem artigos A1-A4 vinculados (total > 0)
        $dbRows = $conn->fetchAllAssociative("
            SELECT DISTINCT ad.id, ad.name, ad.acronym
            FROM academic_database ad
            JOIN qualis_journal_academic_database qb ON qb.academic_database_id = ad.id
            JOIN production_items pi ON pi.qualis_journal_id = qb.qualis_journal_id
            WHERE pi.item_type = 'ARTIGO'
              AND pi.qualis IN ('A1', 'A2', 'A3', 'A4')
            ORDER BY ad.name ASC
        ");
        $databases = [];
        foreach ($dbRows as $db) {
            $databases[] = [
                'id' => (int)$db['id'],
                'name' => (string)$db['name'],
                'acronym' => (string)$db['acronym'],
            ];
        }

        // 2. Consulta de pesquisadores que possuem ao menos 1 artigo A1..A4
        $sqlResearchers = "
            SELECT DISTINCT
                r.id,
                r.full_name as name,
                r.department,
                r.department_code as departmentCode,
                r.slug,
                r.id_lattes as lattesId,
                r.photo_url as photoUrl
            FROM researchers r
            JOIN production_items pi ON pi.researcher_id = r.id
            WHERE pi.item_type = 'ARTIGO'
              AND pi.qualis IN ('A1', 'A2', 'A3', 'A4')
            ORDER BY r.full_name ASC
        ";

        $researcherRows = $conn->fetchAllAssociative($sqlResearchers);

        // 3. Consulta de artigos A1..A4 com agregador GROUP_CONCAT de bases de dados acadêmicas
        $sqlArticles = "
            SELECT 
                pi.researcher_id as researcherId,
                pi.id as articleId,
                pi.qualis,
                GROUP_CONCAT(DISTINCT qb.academic_database_id) as dbIdsStr
            FROM production_items pi
            LEFT JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = pi.qualis_journal_id
            WHERE pi.item_type = 'ARTIGO'
              AND pi.qualis IN ('A1', 'A2', 'A3', 'A4')
            GROUP BY pi.researcher_id, pi.id, pi.qualis
        ";

        $articleRows = $conn->fetchAllAssociative($sqlArticles);

        $articlesByResearcher = [];
        foreach ($articleRows as $art) {
            $rId = (int)$art['researcherId'];
            if (!isset($articlesByResearcher[$rId])) {
                $articlesByResearcher[$rId] = [];
            }

            $dbIds = [];
            if (!empty($art['dbIdsStr'])) {
                $dbIds = array_map('intval', explode(',', $art['dbIdsStr']));
            }

            $articlesByResearcher[$rId][] = [
                'id' => (int)$art['articleId'],
                'qualis' => (string)$art['qualis'],
                'dbIds' => $dbIds
            ];
        }

        $researchers = [];
        foreach ($researcherRows as $r) {
            $rId = (int)$r['id'];
            $articles = $articlesByResearcher[$rId] ?? [];

            // Contagens totais base
            $a1 = 0; $a2 = 0; $a3 = 0; $a4 = 0;
            foreach ($articles as $a) {
                if ($a['qualis'] === 'A1') $a1++;
                elseif ($a['qualis'] === 'A2') $a2++;
                elseif ($a['qualis'] === 'A3') $a3++;
                elseif ($a['qualis'] === 'A4') $a4++;
            }

            $researchers[] = [
                'id' => $rId,
                'name' => (string)$r['name'],
                'department' => (string)($r['department'] ?? 'Não informado'),
                'departmentCode' => (string)($r['departmentCode'] ?? ''),
                'slug' => (string)($r['slug'] ?: $r['lattesId']),
                'lattesId' => (string)($r['lattesId'] ?? ''),
                'photoUrl' => $r['photoUrl'] ? (string)$r['photoUrl'] : null,
                'A1' => $a1,
                'A2' => $a2,
                'A3' => $a3,
                'A4' => $a4,
                'totalA' => count($articles),
                'articles' => $articles
            ];
        }

        return [
            'databases' => $databases,
            'researchers' => $researchers,
        ];
    }

    /**
     * Fig. 15 & 16: Produção Científica Indexada por Base de Dados Internacional (Scopus, Web of Science, PubMed, SciELO, etc.).
     *
     * Cruza os artigos dos docentes com a revista em que foram publicados e as bases de indexação científicas vinculadas.
     *
     * @param int $startYear Ano inicial
     * @param int $endYear Ano final
     * @return array{
     *     years: list<int>,
     *     ranking: list<array{name: string, acronym: string, logo: ?string, color: string, total: int, percentage: float, indexedPercentage: float}>,
     *     timelineSeries: list<array{name: string, acronym: string, color: string, data: list<int>}>,
     *     totalArticles: int,
     *     totalIndexedArticles: int,
     *     indexedPercentage: float,
     *     indexedByYear: list<int>,
     *     nonIndexedByYear: list<int>
     * }
     */
    public function getFigAcademicDatabases(int $startYear = 2010, int $endYear = 2026): array
    {
        $conn = $this->em->getConnection();
        $years = range($startYear, $endYear);

        // Paleta de cores temática das bases
        $colorMap = [
            'scopus' => '#ea580c',        // Laranja Scopus
            'wos' => '#7c3aed',           // Violeta Clarivate / Web of Science
            'latindex' => '#0d9488',      // Teal / Catálogo Latindex
            'scielo' => '#e11d48',        // Rosa/Vermelho SciELO
            'pubmed' => '#2563eb',        // Azul PubMed
            'doaj' => '#d97706',          // Âmbar DOAJ
            'openalex' => '#6366f1',      // Índigo OpenAlex
            'lens' => '#059669',          // Verde Lens.org
            'crossref' => '#0284c7',      // Azul Crossref
        ];

        $totalArticles = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO'");
        
        $totalIndexedArticles = (int)$conn->fetchOne("
            SELECT COUNT(DISTINCT pi.id)
            FROM production_items pi
            JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = pi.qualis_journal_id
            WHERE pi.item_type = 'ARTIGO'
        ");

        // 1. Ranking por base
        $rankingRows = $conn->fetchAllAssociative("
            SELECT 
                ad.id,
                ad.name,
                ad.acronym,
                ad.logo,
                COUNT(DISTINCT pi.id) AS total
            FROM production_items pi
            JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = pi.qualis_journal_id
            JOIN academic_database ad ON ad.id = qb.academic_database_id
            WHERE pi.item_type = 'ARTIGO'
            GROUP BY ad.id, ad.name, ad.acronym, ad.logo
            ORDER BY total DESC
        ");

        $ranking = [];
        $totalsByDb = [];
        $dbMeta = [];

        foreach ($rankingRows as $r) {
            $name = $r['name'];
            $acronym = strtolower((string)($r['acronym'] ?: $name));
            $count = (int)$r['total'];
            $totalsByDb[$name] = $count;
            $dbMeta[$name] = [
                'name' => $name,
                'acronym' => $acronym,
                'logo' => $r['logo'],
                'color' => $colorMap[$acronym] ?? '#64748b',
            ];

            $ranking[] = [
                'name' => $name,
                'acronym' => $acronym,
                'logo' => $r['logo'],
                'color' => $colorMap[$acronym] ?? '#64748b',
                'total' => $count,
                'percentage' => $totalArticles > 0 ? round(($count / $totalArticles) * 100, 1) : 0.0,
                'indexedPercentage' => $totalIndexedArticles > 0 ? round(($count / $totalIndexedArticles) * 100, 1) : 0.0,
            ];
        }

        // 2. Linha do tempo por base
        $timelineRows = $conn->fetchAllAssociative("
            SELECT 
                ad.name,
                pi.year,
                COUNT(DISTINCT pi.id) AS total_year
            FROM production_items pi
            JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = pi.qualis_journal_id
            JOIN academic_database ad ON ad.id = qb.academic_database_id
            WHERE pi.item_type = 'ARTIGO' AND pi.year >= :startYear AND pi.year <= :endYear
            GROUP BY ad.name, pi.year
            ORDER BY ad.name, pi.year ASC
        ", [
            'startYear' => $startYear,
            'endYear' => $endYear,
        ]);

        $timelineByDb = [];
        foreach (array_keys($totalsByDb) as $dbName) {
            $timelineByDb[$dbName] = array_fill_keys($years, 0);
        }

        foreach ($timelineRows as $tr) {
            $name = $tr['name'];
            $y = (int)$tr['year'];
            if (isset($timelineByDb[$name][$y])) {
                $timelineByDb[$name][$y] = (int)$tr['total_year'];
            }
        }

        $timelineSeries = [];
        foreach (array_keys($totalsByDb) as $name) {
            $timelineSeries[] = [
                'name' => $name,
                'acronym' => $dbMeta[$name]['acronym'],
                'color' => $dbMeta[$name]['color'],
                'data' => array_values($timelineByDb[$name]),
            ];
        }

        return [
            'years' => $years,
            'ranking' => $ranking,
            'timelineSeries' => $timelineSeries,
            'totalArticles' => $totalArticles,
            'totalIndexedArticles' => $totalIndexedArticles,
            'indexedPercentage' => $totalArticles > 0 ? round(($totalIndexedArticles / $totalArticles) * 100, 1) : 0.0,
        ];
    }

    /**
     * Fig. 13: Rede de Coautoria e Colaboração Docente (Obras Únicas Conjuntas Deduplicadas via DOI/Título).
     *
     * Cruza todos os trabalhos em parceria (de todos os tipos de produção) entre pesquisadores do CECH
     * utilizando os vínculos resolvidos no Tesauro (matched_researcher_id).
     *
     * @param int $limit Se 0, retorna a matriz completa para exportação VOSviewer / relatórios ilimitados.
     * @return array<int, array{author1: string, slug1: string, dept1: ?string, photo1: ?string, author2: string, slug2: string, dept2: ?string, photo2: ?string, collaborations: int}>
     */
    public function getFig13CoauthorshipNetwork(int $limit = 0): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                r1.full_name as author1,
                COALESCE(NULLIF(r1.slug, ''), CAST(r1.id AS CHAR)) as slug1,
                r1.department_code as dept1,
                r1.photo_url as photo1,
                r2.full_name as author2,
                COALESCE(NULLIF(r2.slug, ''), CAST(r2.id AS CHAR)) as slug2,
                r2.department_code as dept2,
                r2.photo_url as photo2,
                COUNT(DISTINCT 
                    CASE 
                        WHEN (pi.doi IS NOT NULL AND TRIM(pi.doi) != '') 
                        THEN CONCAT('DOI:', LOWER(TRIM(pi.doi)))
                        ELSE CONCAT('TITLE:', pi.item_type, ':', LOWER(TRIM(pi.title)))
                    END
                ) as collaborations
            FROM production_authors pa1
            JOIN production_authors pa2 
                ON pa1.production_item_id = pa2.production_item_id 
               AND pa1.matched_researcher_id < pa2.matched_researcher_id
            JOIN production_items pi ON pi.id = pa1.production_item_id
            JOIN researchers r1 ON r1.id = pa1.matched_researcher_id
            JOIN researchers r2 ON r2.id = pa2.matched_researcher_id
            GROUP BY r1.id, r1.full_name, r1.slug, r1.department_code, r1.photo_url,
                     r2.id, r2.full_name, r2.slug, r2.department_code, r2.photo_url
            ORDER BY collaborations DESC
        ";

        if ($limit > 0) {
            $sql .= " LIMIT :lim";
            return $conn->fetchAllAssociative($sql, ['lim' => $limit], ['lim' => \PDO::PARAM_INT]);
        }

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Retorna a Matriz N x N completa de coautoria entre todos os docentes do CECH envolvidos em parcerias.
     *
     * @return array{nodes: array<int, array{id: int, name: string, dept: ?string, slug: string}>, matrix: array<int, array<int, int>>}
     */
    public function getCoauthorshipMatrixPayload(): array
    {
        $conn = $this->em->getConnection();

        // 1. Obter todos os docentes que possuem pelo menos 1 parceria interna
        $nodesSql = "
            SELECT DISTINCT 
                r.id, 
                r.full_name as name, 
                r.department_code as dept,
                COALESCE(NULLIF(r.slug, ''), CAST(r.id AS CHAR)) as slug
            FROM researchers r
            JOIN production_authors pa ON pa.matched_researcher_id = r.id
            JOIN production_authors pa2 
                ON pa2.production_item_id = pa.production_item_id 
               AND pa2.matched_researcher_id != pa.matched_researcher_id
            ORDER BY r.full_name ASC
        ";
        $nodes = $conn->fetchAllAssociative($nodesSql);

        // 2. Obter todas as parcerias cruzadas por ID
        $linksSql = "
            SELECT 
                pa1.matched_researcher_id as id1,
                pa2.matched_researcher_id as id2,
                COUNT(DISTINCT 
                    CASE 
                        WHEN (pi.doi IS NOT NULL AND TRIM(pi.doi) != '') 
                        THEN CONCAT('DOI:', LOWER(TRIM(pi.doi)))
                        ELSE CONCAT('TITLE:', pi.item_type, ':', LOWER(TRIM(pi.title)))
                    END
                ) as cnt
            FROM production_authors pa1
            JOIN production_authors pa2 
                ON pa1.production_item_id = pa2.production_item_id 
               AND pa1.matched_researcher_id < pa2.matched_researcher_id
            JOIN production_items pi ON pi.id = pa1.production_item_id
            GROUP BY pa1.matched_researcher_id, pa2.matched_researcher_id
        ";
        $links = $conn->fetchAllAssociative($linksSql);

        // 3. Montar mapa simétrico de parcerias [id1][id2] => cnt
        $matrix = [];
        foreach ($links as $link) {
            $id1 = (int)$link['id1'];
            $id2 = (int)$link['id2'];
            $cnt = (int)$link['cnt'];

            $matrix[$id1][$id2] = $cnt;
            $matrix[$id2][$id1] = $cnt;
        }

        return [
            'nodes' => $nodes,
            'matrix' => $matrix,
        ];
    }

    /**
     * Fig. 14: Ranking de Parcerias Institucionais Nacionais (Processado via Tesauro de Instituições).
     */
    public function getFig14NationalPartners(int $limit = 10): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT institution_name as name, COUNT(*) as cnt
            FROM professional_experiences
            WHERE institution_name IS NOT NULL 
              AND TRIM(institution_name) != ''
              AND institution_name NOT LIKE '%UFSCar%'
              AND institution_name NOT LIKE '%Federal de São Carlos%'
            GROUP BY institution_name
            ORDER BY cnt DESC
            LIMIT 100
        ";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];

        foreach ($rows as $r) {
            $rawName = $r['name'];
            $cnt = (int)$r['cnt'];
            $data = $this->institutionResolver->resolveInstitutionData($rawName);

            $countryIso = $data ? ($data['countryIso'] ?? 'BR') : 'BR';
            if ($countryIso !== 'BR' && !empty($data['countryIso'])) {
                continue; // Pertence ao internacional
            }

            $displayName = $data ? (!empty($data['acronym']) && !str_contains($data['officialName'], $data['acronym']) ? "{$data['acronym']} - {$data['officialName']}" : $data['officialName']) : $rawName;

            if (!isset($aggregated[$displayName])) {
                $aggregated[$displayName] = 0;
            }
            $aggregated[$displayName] += $cnt;
        }

        arsort($aggregated);

        $result = [];
        foreach (array_slice($aggregated, 0, $limit, true) as $name => $total) {
            $result[] = [
                'name' => $name,
                'total' => $total
            ];
        }

        return $result;
    }

    /**
     * Fig. 15: Ranking de Parcerias Institucionais Internacionais (Processado via Tesauro de Instituições e Países).
     */
    public function getFig15InternationalPartners(int $limit = 10): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT institution_name as name, COUNT(*) as cnt
            FROM educations
            WHERE institution_name IS NOT NULL 
              AND TRIM(institution_name) != ''
              AND institution_name NOT LIKE '%UFSCar%'
              AND institution_name NOT LIKE '%São Carlos%'
            GROUP BY institution_name
            ORDER BY cnt DESC
            LIMIT 200
        ";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];

        foreach ($rows as $r) {
            $rawName = $r['name'];
            $cnt = (int)$r['cnt'];
            $data = $this->institutionResolver->resolveInstitutionData($rawName);

            $isForeign = false;
            $countryIso = $data ? ($data['countryIso'] ?? null) : null;

            if ($countryIso !== null && $countryIso !== 'BR') {
                $isForeign = true;
            } elseif (
                str_contains(strtolower($rawName), 'université') ||
                str_contains(strtolower($rawName), 'university of') ||
                str_contains(strtolower($rawName), 'universidad de') ||
                str_contains(strtolower($rawName), 'universitat') ||
                str_contains(strtolower($rawName), 'university, ') ||
                str_contains(strtolower($rawName), 'oxford') ||
                str_contains(strtolower($rawName), 'harvard') ||
                str_contains(strtolower($rawName), 'sorbonne') ||
                str_contains(strtolower($rawName), 'paris') ||
                str_contains(strtolower($rawName), 'lyon') ||
                str_contains(strtolower($rawName), 'coimbra') ||
                str_contains(strtolower($rawName), 'porto') ||
                str_contains(strtolower($rawName), 'lisboa') ||
                str_contains(strtolower($rawName), 'buenos aires')
            ) {
                $isForeign = true;
            }

            if (!$isForeign) {
                continue;
            }

            $displayName = $data ? (!empty($data['acronym']) && !str_contains($data['officialName'], $data['acronym']) ? "{$data['acronym']} - {$data['officialName']}" : $data['officialName']) : $rawName;

            if (!isset($aggregated[$displayName])) {
                $aggregated[$displayName] = 0;
            }
            $aggregated[$displayName] += $cnt;
        }

        arsort($aggregated);

        $result = [];
        foreach (array_slice($aggregated, 0, $limit, true) as $name => $total) {
            $result[] = [
                'name' => $name,
                'total' => $total
            ];
        }

        return $result;
    }

    /**
     * Normaliza e resolve o nome canônico de uma instituição de ensino para o diagrama Sankey de trajetórias.
     */
    public static function normalizeTrajectoryInstitution(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return 'Não informado';
        }

        $norm = StringNormalizer::normalizeString($raw, false);

        // UFSCar / São Carlos
        if ((str_contains($norm, 'sao carlos') && (str_contains($norm, 'federal') || str_contains($norm, 'ufscar') || str_contains($norm, 'universidade federal'))) ||
            str_contains($norm, 'ufscar') ||
            str_contains($norm, 'departamento de engenharia de materiais') ||
            str_contains($norm, 'escola de biblioteconomia e documentacao de sao carlos')) {
            return 'UFSCar';
        }

        // USP
        if (str_contains($norm, 'universidade de sao paulo') ||
            $norm === 'usp' || str_starts_with($norm, 'usp ') || str_ends_with($norm, ' usp') ||
            str_contains($norm, 'fflch') || str_contains($norm, 'fearp') ||
            str_contains($norm, 'escola de engenharia de sao carlos') ||
            str_contains($norm, 'escola de comunicacao e artes') ||
            str_contains($norm, 'faculdade de educacao da universidade de sao paulo') ||
            str_contains($norm, 'faculdade de educacao da usp') ||
            str_contains($norm, 'escola superior de agricultura luiz de queiroz') ||
            str_contains($norm, 'esalq') || str_contains($norm, 'fdrp') ||
            str_contains($norm, 'sanfran') || str_contains($norm, 'ribeirao preto sp') ||
            str_contains($norm, 'usp rp')) {
            return 'USP';
        }

        // UNICAMP
        if (str_contains($norm, 'unicamp') || str_contains($norm, 'estadual de campinas')) {
            return 'UNICAMP';
        }

        // UNESP
        if (str_contains($norm, 'unesp') || str_contains($norm, 'estadual paulista') ||
            str_contains($norm, 'ilha solteira') || str_contains($norm, 'araraquara') ||
            str_contains($norm, 'marilia') || str_contains($norm, 'rio claro') ||
            str_contains($norm, 'bauru') || str_contains($norm, 'franca') ||
            str_contains($norm, 'assis') || str_contains($norm, 'presidente prudente') ||
            str_contains($norm, 'jaboticabal') || str_contains($norm, 'botucatu') ||
            str_contains($norm, 'ibrc')) {
            return 'UNESP';
        }

        // PUCs
        if (str_contains($norm, 'puc sp') || str_contains($norm, 'pucsp') || (str_contains($norm, 'pontificia') && str_contains($norm, 'sao paulo'))) {
            return 'PUC-SP';
        }
        if (str_contains($norm, 'puc campinas') || str_contains($norm, 'puccamp') || (str_contains($norm, 'pontificia') && str_contains($norm, 'campinas'))) {
            return 'PUC-Campinas';
        }
        if (str_contains($norm, 'puc rio') || str_contains($norm, 'puc rj') || (str_contains($norm, 'pontificia') && str_contains($norm, 'rio de janeiro'))) {
            return 'PUC-Rio';
        }
        if (str_contains($norm, 'puc rs') || str_contains($norm, 'pucrs') || (str_contains($norm, 'pontificia') && str_contains($norm, 'rio grande do sul'))) {
            return 'PUC-RS';
        }
        if (str_contains($norm, 'puc minas') || str_contains($norm, 'puc mg') || (str_contains($norm, 'pontificia') && str_contains($norm, 'minas'))) {
            return 'PUC-Minas';
        }

        // Federais
        if (str_contains($norm, 'ufmg') || str_contains($norm, 'federal de minas')) return 'UFMG';
        if (str_contains($norm, 'ufrj') || str_contains($norm, 'federal do rio de janeiro') || str_contains($norm, 'universidade do brasil')) return 'UFRJ';
        if (str_contains($norm, 'ufsc') || str_contains($norm, 'federal de santa catarina')) return 'UFSC';
        if (str_contains($norm, 'ufrgs') || str_contains($norm, 'federal do rio grande do sul')) return 'UFRGS';
        if (str_contains($norm, 'unb') || str_contains($norm, 'universidade de brasilia')) return 'UnB';
        if (str_contains($norm, 'ufpr') || str_contains($norm, 'federal do parana')) return 'UFPR';
        if (str_contains($norm, 'ufu') || str_contains($norm, 'federal de uberlandia')) return 'UFU';
        if (str_contains($norm, 'uff') || str_contains($norm, 'federal fluminense')) return 'UFF';
        if (str_contains($norm, 'ufpe') || str_contains($norm, 'federal de pernambuco')) return 'UFPE';
        if (str_contains($norm, 'ufba') || str_contains($norm, 'federal da bahia')) return 'UFBA';
        if (str_contains($norm, 'ufpa') || str_contains($norm, 'federal do para')) return 'UFPA';
        if (str_contains($norm, 'ufpb') || str_contains($norm, 'federal da paraiba')) return 'UFPB';
        if (str_contains($norm, 'ufc') || str_contains($norm, 'federal do ceara')) return 'UFC';
        if (str_contains($norm, 'ufsm') || str_contains($norm, 'federal de santa maria')) return 'UFSM';
        if (str_contains($norm, 'ufms') || str_contains($norm, 'federal de mato grosso do sul')) return 'UFMS';
        if (str_contains($norm, 'ufmt') || str_contains($norm, 'federal de mato grosso')) return 'UFMT';
        if (str_contains($norm, 'ufg') || str_contains($norm, 'federal de goias')) return 'UFG';
        if (str_contains($norm, 'ufal') || str_contains($norm, 'federal de alagoas')) return 'UFAL';
        if (str_contains($norm, 'unifesp') || str_contains($norm, 'federal de sao paulo') || str_contains($norm, 'paulista de medicina')) return 'UNIFESP';
        if (str_contains($norm, 'unimep') || str_contains($norm, 'metodista de piracicaba')) return 'UNIMEP';
        if (str_contains($norm, 'mackenzie') || str_contains($norm, 'presbiteriana mackenzie')) return 'Mackenzie';
        if (str_contains($norm, 'uel') || str_contains($norm, 'estadual de londrina')) return 'UEL';
        if (str_contains($norm, 'uem') || str_contains($norm, 'estadual de maringa')) return 'UEM';
        if (str_contains($norm, 'uerj') || str_contains($norm, 'estadual do rio de janeiro')) return 'UERJ';
        if (str_contains($norm, 'uniso') || str_contains($norm, 'universidade de sorocaba')) return 'UNISO';
        if (str_contains($norm, 'unifran') || str_contains($norm, 'universidade de franca')) return 'UNIFRAN';
        if (str_contains($norm, 'usf') || str_contains($norm, 'universidade sao francisco')) return 'USF';
        if (str_contains($norm, 'fadisc') || str_contains($norm, 'direito de sao carlos')) return 'FADISC';
        if (str_contains($norm, 'fespsp') || str_contains($norm, 'fundacao escola de sociologia e politica')) return 'FESPSP';
        if (str_contains($norm, 'univesp')) return 'UNIVESP';

        // Estrangeiras
        if (str_contains($norm, 'paris') || str_contains($norm, 'sorbonne') || str_contains($norm, 'ehess') || str_contains($norm, 'ecole des hautes') || str_contains($norm, 'strasbourg') || str_contains($norm, 'toulouse') || str_contains($norm, 'lille') || str_contains($norm, 'rennes') || str_contains($norm, 'aix marseille') || str_contains($norm, 'montpellier') || str_contains($norm, 'lyon') || str_contains($norm, 'bordeaux')) return 'Exterior (França)';
        if (str_contains($norm, 'vanderbilt') || str_contains($norm, 'new york') || str_contains($norm, 'harvard') || str_contains($norm, 'texas') || str_contains($norm, 'illinois') || str_contains($norm, 'california') || str_contains($norm, 'berkeley') || str_contains($norm, 'wisconsin') || str_contains($norm, 'michigan') || str_contains($norm, 'columbia') || str_contains($norm, 'stanford') || str_contains($norm, 'princeton') || str_contains($norm, 'florida') || str_contains($norm, 'chicago') || str_contains($norm, 'purdue') || str_contains($norm, 'indiana') || str_contains($norm, 'rutgers') || str_contains($norm, 'pennsylvania') || str_contains($norm, 'yale') || str_contains($norm, 'mit ') || $norm === 'mit') return 'Exterior (EUA)';
        if (str_contains($norm, 'oxford') || str_contains($norm, 'cambridge') || str_contains($norm, 'london') || str_contains($norm, 'leeds') || str_contains($norm, 'birmingham') || str_contains($norm, 'sheffield') || str_contains($norm, 'manchester') || str_contains($norm, 'warwick') || str_contains($norm, 'sussex') || str_contains($norm, 'edinburgh') || str_contains($norm, 'glasgow') || str_contains($norm, 'nottingham') || str_contains($norm, 'southampton') || str_contains($norm, 'bristol')) return 'Exterior (Reino Unido)';
        if (str_contains($norm, 'coimbra') || str_contains($norm, 'porto') || str_contains($norm, 'lisboa') || str_contains($norm, 'minho') || str_contains($norm, 'aveiro') || str_contains($norm, 'evora') || str_contains($norm, 'nova de lisboa')) return 'Exterior (Portugal)';
        if (str_contains($norm, 'madrid') || str_contains($norm, 'barcelona') || str_contains($norm, 'salamanca') || str_contains($norm, 'valencia') || str_contains($norm, 'granada') || str_contains($norm, 'sevilla') || str_contains($norm, 'alcala') || str_contains($norm, 'complutense') || str_contains($norm, 'autonoma de barcelona')) return 'Exterior (Espanha)';
        if (str_contains($norm, 'buenos aires') || str_contains($norm, 'cordoba') || str_contains($norm, 'la plata') || str_contains($norm, 'flacso') || str_contains($norm, 'quilmes') || str_contains($norm, 'rosario') || str_contains($norm, 'cuyo')) return 'Exterior (América Latina)';
        if (str_contains($norm, 'moscou') || str_contains($norm, 'lomonosov') || str_contains($norm, 'russia')) return 'Exterior (Rússia)';
        if (str_contains($norm, 'berlin') || str_contains($norm, 'heidelberg') || str_contains($norm, 'munchen') || str_contains($norm, 'frankfurt') || str_contains($norm, 'koln') || str_contains($norm, 'humboldt') || str_contains($norm, 'freiburg') || str_contains($norm, 'bonn') || str_contains($norm, 'tubingen')) return 'Exterior (Alemanha)';
        if (str_contains($norm, 'bologna') || str_contains($norm, 'roma') || str_contains($norm, 'sapienza') || str_contains($norm, 'milano') || str_contains($norm, 'firenze') || str_contains($norm, 'padova') || str_contains($norm, 'torino') || str_contains($norm, 'pisa')) return 'Exterior (Itália)';
        if (str_contains($norm, 'toronto') || str_contains($norm, 'montreal') || str_contains($norm, 'mcgill') || str_contains($norm, 'ubc') || str_contains($norm, 'british columbia') || str_contains($norm, 'quebec') || str_contains($norm, 'ottawa') || str_contains($norm, 'alberta')) return 'Exterior (Canadá)';

        $clean = ucwords(mb_strtolower(trim($raw), 'UTF-8'));
        return mb_strlen($clean) > 30 ? mb_substr($clean, 0, 27) . '...' : $clean;
    }

    /**
     * Fig. 16: Trajetória acadêmica e fluxo de formação dos docentes (Diagrama Sankey D3.js).
     * Mapeia: Graduação -> Mestrado -> Doutorado -> Destino CECH/UFSCar.
     */
    public function getFig16AcademicTrajectoriesSankey(): array
    {
        $conn = $this->em->getConnection();

        $researchers = $conn->fetchAllAssociative("
            SELECT r.id, r.full_name, r.slug, r.id_lattes, r.department_code, r.department
            FROM researchers r
            WHERE r.status = 1
            ORDER BY r.full_name ASC
        ");

        $departmentNames = [
            'PS' => 'Psicologia (DPPSI)',
            'LE' => 'Letras (DL)',
            'CS' => 'Ciências Sociais (DCMSO)',
            'AC' => 'Artes e Comunicação (DAC)',
            'IFD' => 'Educação Física (DEF)',
            'ED' => 'Educação (DED)',
            'TPP' => 'Teoria e Prática Pedagógica (DTE)',
            'CI' => 'Ciência da Informação (DCI)',
            'FI' => 'Filosofia (DF)',
            'SO' => 'Sociologia (DSo)',
            'CA' => 'Metodologia e Ensino (DEC)',
            'CECH' => 'Geral CECH',
            'DCSo' => 'Ciências Sociais (DCMSO)'
        ];

        $allEdus = $conn->fetchAllAssociative("
            SELECT researcher_id, level, institution_name, start_year, end_year
            FROM educations
            WHERE level IN ('GRADUACAO', 'MESTRADO', 'MESTRADO-PROFISSIONALIZANTE', 'DOUTORADO')
            ORDER BY start_year ASC, id ASC
        ");

        $edusByResearcher = [];
        foreach ($allEdus as $e) {
            $edusByResearcher[$e['researcher_id']][] = $e;
        }

        $trajectories = [];
        $instFrequencies = [];
        $departmentsMap = [];

        foreach ($researchers as $r) {
            $deptCode = $r['department_code'] ?: ($r['department'] ?: 'CECH');
            $deptName = $departmentNames[$deptCode] ?? ($r['department'] ?: $deptCode);

            if (!isset($departmentsMap[$deptCode])) {
                $departmentsMap[$deptCode] = [
                    'code' => $deptCode,
                    'name' => $deptName,
                    'count' => 0
                ];
            }
            $departmentsMap[$deptCode]['count']++;

            $edus = $edusByResearcher[$r['id']] ?? [];

            $grad = null;
            $mest = null;
            $doc = null;

            foreach ($edus as $e) {
                $inst = self::normalizeTrajectoryInstitution($e['institution_name']);
                if ($e['level'] === 'GRADUACAO' && !$grad) {
                    $grad = $inst;
                } elseif (($e['level'] === 'MESTRADO' || $e['level'] === 'MESTRADO-PROFISSIONALIZANTE') && !$mest) {
                    $mest = $inst;
                } elseif ($e['level'] === 'DOUTORADO' && !$doc) {
                    $doc = $inst;
                }
            }

            $grad = $grad ?: 'Graduação Não Inf.';
            $mest = $mest ?: ($doc ? 'Doutorado Direto' : 'Mestrado Não Inf.');
            $doc = $doc ?: 'Doutorado Não Inf.';

            @$instFrequencies[$grad]++;
            @$instFrequencies[$mest]++;
            @$instFrequencies[$doc]++;

            $trajectories[] = [
                'researcherId' => (int)$r['id'],
                'name' => (string)$r['full_name'],
                'slug' => (string)($r['slug'] ?: ($r['id_lattes'] ?? '')),
                'deptCode' => $deptCode,
                'deptName' => $deptName,
                'grad' => $grad,
                'mest' => $mest,
                'doc' => $doc,
            ];
        }

        // Ordenar departamentos por contagem decrescente
        uasort($departmentsMap, fn($a, $b) => $b['count'] <=> $a['count']);

        // Calcular estatísticas de síntese
        $totalResearchers = count($trajectories);
        $spUniversities = ['USP', 'UFSCar', 'UNICAMP', 'UNESP', 'PUC-SP', 'PUC-Campinas', 'UNIFESP', 'Mackenzie', 'UNIMEP', 'UEL', 'UEM', 'USF', 'UNISO', 'FADISC', 'FESPSP', 'UNIVESP'];
        
        $gradSP = 0;
        $docSP = 0;
        $docForeign = 0;
        $gradUFSCar = 0;
        $mestUFSCar = 0;
        $docUFSCar = 0;

        foreach ($trajectories as $t) {
            if (in_array($t['grad'], $spUniversities, true)) $gradSP++;
            if (in_array($t['doc'], $spUniversities, true)) $docSP++;
            if (str_starts_with($t['doc'], 'Exterior')) $docForeign++;
            if ($t['grad'] === 'UFSCar') $gradUFSCar++;
            if ($t['mest'] === 'UFSCar') $mestUFSCar++;
            if ($t['doc'] === 'UFSCar') $docUFSCar++;
        }

        $summary = [
            'totalResearchers' => $totalResearchers,
            'gradSPPercent' => $totalResearchers > 0 ? round(($gradSP / $totalResearchers) * 100, 1) : 0,
            'docSPPercent' => $totalResearchers > 0 ? round(($docSP / $totalResearchers) * 100, 1) : 0,
            'docForeignPercent' => $totalResearchers > 0 ? round(($docForeign / $totalResearchers) * 100, 1) : 0,
            'docForeignCount' => $docForeign,
            'gradUFSCarPercent' => $totalResearchers > 0 ? round(($gradUFSCar / $totalResearchers) * 100, 1) : 0,
            'mestUFSCarPercent' => $totalResearchers > 0 ? round(($mestUFSCar / $totalResearchers) * 100, 1) : 0,
            'docUFSCarPercent' => $totalResearchers > 0 ? round(($docUFSCar / $totalResearchers) * 100, 1) : 0,
        ];

        return [
            'summary' => $summary,
            'departments' => array_values($departmentsMap),
            'trajectories' => $trajectories,
            'instFrequencies' => $instFrequencies
        ];
    }

    /**
     * Fig. 17: Destino institucional e mobilidade acadêmica dos pesquisadores/docentes egressos do CECH.
     * Mapeia: Departamento de Origem no CECH -> Esfera/Categoria Institucional -> Instituição de Destino.
     *
     * @return array{
     *     summary: array{
     *         totalExits: int,
     *         totalMapped: int,
     *         mappedPercent: float,
     *         totalPublicHigherEd: int,
     *         publicHigherEdPercent: float,
     *         totalOutStateOrIntl: int,
     *         outStateOrIntlPercent: float
     *     },
     *     destinationsRanking: list<array{name: string, category: string, count: int, percentage: float, researchers: list<array{name: string, dept: string, leaveYear: ?int, role: ?string}>}>,
     *     categoriesDistribution: array<string, int>,
     *     departmentsDistribution: array<string, int>,
     *     sankey: array{
     *         nodes: list<array{id: string, name: string, type: string}>,
     *         links: list<array{source: int, target: int, value: int, sourceName: string, targetName: string, researchers: list<string>}>
     *     },
     *     records: list<array{
     *         id: int,
     *         name: string,
     *         slug: string,
     *         lattesId: string,
     *         department: string,
     *         admissionYear: ?int,
     *         leaveYear: ?int,
     *         destinationInstitution: string,
     *         rawInstitution: ?string,
     *         institutionCategory: string,
     *         role: string,
     *         period: string,
     *         isCurrent: bool,
     *         contractType: ?string
     *     }>
     * }
     */
    public function getFig17FacultyExitsAndDestinations(): array
    {
        $conn = $this->em->getConnection();

        $researchers = $conn->fetchAllAssociative("
            SELECT id, full_name, slug, id_lattes, department, department_code, admission_year, leave_year, work_agency
            FROM researchers
            WHERE status = 0 OR (leave_year IS NOT NULL AND leave_year < 2026)
            ORDER BY full_name ASC
        ");

        $expsByResearcher = [];
        if (!empty($researchers)) {
            $rIds = array_column($researchers, 'id');
            $allExps = $conn->fetchAllAssociative("
                SELECT pe.researcher_id, pe.institution_name, pe.role_name, pe.contract_type, pe.start_year, pe.end_year, pe.is_current
                FROM professional_experiences pe
                WHERE pe.researcher_id IN (" . implode(',', array_map('intval', $rIds)) . ")
                  AND pe.institution_name IS NOT NULL AND TRIM(pe.institution_name) != ''
                ORDER BY pe.is_current DESC, pe.end_year DESC, pe.start_year DESC
            ");

            foreach ($allExps as $exp) {
                $expsByResearcher[$exp['researcher_id']][] = $exp;
            }
        }

        $departmentNames = [
            'PS' => 'Psicologia (DPsi)',
            'LE' => 'Letras (DL)',
            'CS' => 'Ciências Sociais (DCS)',
            'AC' => 'Artes e Comunicação (DAC)',
            'IFD' => 'Metodologia de Ensino (DME)',
            'ED' => 'Educação (DEd)',
            'TPP' => 'Teorias e Práticas Pedagógicas (DTPP)',
            'CI' => 'Ciência da Informação (DCI)',
            'FI' => 'Filosofia (DFIL)',
            'SO' => 'Sociologia (DSo)',
            'CA' => 'Ciências Ambientais (DCAm)',
        ];

        $cleanDeptName = function(?string $dept, ?string $code) use ($departmentNames): string {
            if ($code && isset($departmentNames[$code])) {
                return $departmentNames[$code];
            }
            $d = $dept ?? '';
            return match(true) {
                str_contains($d, 'Letras') => 'Letras (DL)',
                str_contains($d, 'Ciências Sociais') => 'Ciências Sociais (DCS)',
                str_contains($d, '(FI)') || str_contains($d, 'Filosofia') => 'Filosofia (DFIL)',
                str_contains($d, '(PS)') || str_contains($d, 'Psicologia') => 'Psicologia (DPsi)',
                str_contains($d, '(CI)') || str_contains($d, 'Informação') => 'Ciência da Informação (DCI)',
                str_contains($d, '(AC)') || str_contains($d, 'Artes') => 'Artes e Comunicação (DAC)',
                str_contains($d, '(ED)') || str_contains($d, 'Educação') => 'Educação (DEd)',
                str_contains($d, '(IFD)') || str_contains($d, 'Metodologia') => 'Metodologia de Ensino (DME)',
                str_contains($d, '(TPP)') || str_contains($d, 'Teorias') => 'Teorias e Práticas Pedagógicas (DTPP)',
                str_contains($d, '(CA)') => 'Ciências Ambientais (DCAm)',
                default => $d ?: 'Outro Departamento'
            };
        };

        $isEditorialOrJournal = function(string $name, ?string $role, ?string $contract): bool {
            $norm = mb_strtolower($name . ' ' . ($role ?? '') . ' ' . ($contract ?? ''));
            if (
                str_contains($norm, 'revista') || str_contains($norm, 'journal') || str_contains($norm, 'cadernos de') ||
                str_contains($norm, 'corpo editorial') || str_contains($norm, 'editora') || str_contains($norm, 'issn') ||
                str_contains($norm, 'editorial') || str_contains($norm, 'parecerista') || str_contains($norm, 'consultor') ||
                str_contains($norm, 'membro de conselho') || str_contains($norm, 'membro de diretoria')
            ) {
                if (
                    str_contains($norm, 'universidade') || str_contains($norm, 'faculdade') ||
                    str_contains($norm, 'instituto federal') || str_contains($norm, 'instituto de') ||
                    str_contains($norm, 'unesp') || str_contains($norm, 'usp')
                ) {
                    if (str_contains($norm, 'revista') || str_contains($norm, 'editora') || str_contains($norm, 'corpo editorial')) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
            return false;
        };

        $classifyInstitutionType = function(string $name, ?string $officialName, ?string $acronym): string {
            $check = mb_strtolower($name . ' ' . ($officialName ?? '') . ' ' . ($acronym ?? ''));
            if (
                str_contains($check, 'usp') || str_contains($check, 'unesp') || str_contains($check, 'unicamp') ||
                str_contains($check, 'estadual paulista') || str_contains($check, 'universidade de são paulo') ||
                str_contains($check, 'estadual de campinas')
            ) {
                return 'Estadual Paulista (USP/UNESP/UNICAMP)';
            }
            if (
                str_contains($check, 'federal') || str_contains($check, 'unifesp') || str_contains($check, 'ufrgs') ||
                str_contains($check, 'ufms') || str_contains($check, 'ufsc') || str_contains($check, 'uff') ||
                str_contains($check, 'unb') || str_contains($check, 'ufpr') || str_contains($check, 'ufpe') ||
                str_contains($check, 'ufal') || str_contains($check, 'ufpb') || str_contains($check, 'unilab') ||
                str_contains($check, 'ufabc') || str_contains($check, 'unirio') || str_contains($check, 'ufrj')
            ) {
                return 'Universidade Federal (IFES)';
            }
            if (
                str_contains($check, 'estadual') || str_contains($check, 'udesc') || str_contains($check, 'unioeste') ||
                str_contains($check, 'uem') || str_contains($check, 'uern') || str_contains($check, 'uel')
            ) {
                return 'Universidade Estadual (Outros Estados)';
            }
            if (
                str_contains($check, 'québec') || str_contains($check, 'illinois') || str_contains($check, 'oxford') ||
                str_contains($check, 'leiden') || str_contains($check, 'california') || str_contains($check, 'ibero-amerikanisches')
            ) {
                return 'Instituição Internacional / Exterior';
            }
            if (
                str_contains($check, 'ministério') || str_contains($check, 'inep') || str_contains($check, 'capes') ||
                str_contains($check, 'cnpq') || str_contains($check, 'fiocruz') || str_contains($check, 'ebserh') ||
                str_contains($check, 'secretaria') || str_contains($check, 'confederação')
            ) {
                return 'Órgão Público / Instituto de Pesquisa';
            }
            return 'Privada / Comunitária / Outras';
        };

        $records = [];
        $destMap = [];
        $categoryCounts = [];
        $deptCounts = [];
        $totalExits = count($researchers);
        $totalMapped = 0;
        $totalPublicHigherEd = 0;
        $totalOutStateOrIntl = 0;

        $layer1To2Links = [];
        $layer2To3Links = [];

        foreach ($researchers as $r) {
            $leaveYear = !empty($r['leave_year']) ? (int)$r['leave_year'] : 2020;
            $dept = $cleanDeptName($r['department'], $r['department_code']);

            $exps = $expsByResearcher[$r['id']] ?? [];

            $bestDest = null;
            $score = -1;

            foreach ($exps as $e) {
                $instName = trim($e['institution_name'] ?? '');
                $isUfscar = (
                    stripos($instName, 'ufscar') !== false ||
                    stripos($instName, 'são carlos') !== false ||
                    stripos($instName, 'sao carlos') !== false
                );
                if ($isUfscar) {
                    continue;
                }

                if ($isEditorialOrJournal($instName, $e['role_name'], $e['contract_type'])) {
                    continue;
                }

                $curScore = 0;
                if (!empty($e['is_current'])) {
                    $curScore += 50;
                }
                if (!empty($e['start_year']) && (int)$e['start_year'] >= $leaveYear) {
                    $curScore += 30;
                }
                if (!empty($e['end_year']) && (int)$e['end_year'] >= $leaveYear) {
                    $curScore += 20;
                }
                if (!empty($e['contract_type']) && (
                    stripos($e['contract_type'], 'servidor') !== false ||
                    stripos($e['contract_type'], 'clt') !== false ||
                    stripos($e['contract_type'], 'professor') !== false ||
                    stripos($e['contract_type'], 'docente') !== false
                )) {
                    $curScore += 15;
                }

                if ($curScore > $score && $curScore > 0) {
                    $score = $curScore;
                    $resInst = $this->institutionResolver->resolveInstitutionData($instName);
                    $resolvedInst = $resInst ? ($resInst['acronym'] ?: $resInst['officialName']) : $instName;
                    $instType = $classifyInstitutionType($resolvedInst, $resInst['officialName'] ?? null, $resInst['acronym'] ?? null);

                    $bestDest = [
                        'institution' => $resolvedInst,
                        'raw_institution' => $instName,
                        'institution_type' => $instType,
                        'role' => $e['role_name'],
                        'start' => $e['start_year'],
                        'end' => $e['end_year'],
                        'is_current' => !empty($e['is_current']),
                        'contract' => $e['contract_type'],
                    ];
                }
            }

            // Fallback via endereço profissional Lattes
            if (!$bestDest && !empty($r['work_agency'])) {
                $agency = trim($r['work_agency']);
                if (stripos($agency, 'ufscar') === false && stripos($agency, 'cech') === false) {
                    $resInst = $this->institutionResolver->resolveInstitutionData($agency);
                    $resolvedInst = $resInst ? ($resInst['acronym'] ?: $resInst['officialName']) : $agency;
                    $instType = $classifyInstitutionType($resolvedInst, $resInst['officialName'] ?? null, $resInst['acronym'] ?? null);
                    $bestDest = [
                        'institution' => $resolvedInst,
                        'raw_institution' => $agency,
                        'institution_type' => $instType,
                        'role' => 'Endereço Institucional Lattes',
                        'start' => null,
                        'end' => null,
                        'is_current' => true,
                        'contract' => null,
                    ];
                }
            }

            if ($bestDest) {
                $totalMapped++;
                $inst = $bestDest['institution'];
                $cat = $bestDest['institution_type'];

                if (
                    $cat === 'Estadual Paulista (USP/UNESP/UNICAMP)' ||
                    $cat === 'Universidade Federal (IFES)' ||
                    $cat === 'Universidade Estadual (Outros Estados)'
                ) {
                    $totalPublicHigherEd++;
                }
                if (
                    $cat === 'Universidade Estadual (Outros Estados)' ||
                    $cat === 'Instituição Internacional / Exterior' ||
                    in_array($inst, ['UFMS', 'UFRGS', 'UFSC', 'UFF', 'UnB', 'UFPR', 'UFPE', 'UFAL', 'UFPB', 'UNILAB', 'UEM', 'UDESC', 'UNIOESTE', 'UERN'], true)
                ) {
                    $totalOutStateOrIntl++;
                }

                if (!isset($destMap[$inst])) {
                    $destMap[$inst] = [
                        'name' => $inst,
                        'category' => $cat,
                        'count' => 0,
                        'percentage' => 0.0,
                        'researchers' => []
                    ];
                }
                $destMap[$inst]['count']++;
                $destMap[$inst]['researchers'][] = [
                    'name' => (string)$r['full_name'],
                    'dept' => $dept,
                    'leaveYear' => $r['leave_year'],
                    'role' => $bestDest['role'] ?: 'Docente/Pesquisador'
                ];

                $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
                $deptCounts[$dept] = ($deptCounts[$dept] ?? 0) + 1;

                // Sankey link: Dept -> Category
                $k1 = $dept . '|||' . $cat;
                if (!isset($layer1To2Links[$k1])) {
                    $layer1To2Links[$k1] = ['source' => $dept, 'target' => $cat, 'value' => 0, 'researchers' => []];
                }
                $layer1To2Links[$k1]['value']++;
                $layer1To2Links[$k1]['researchers'][] = (string)$r['full_name'];

                // Sankey link: Category -> Institution
                $k2 = $cat . '|||' . $inst;
                if (!isset($layer2To3Links[$k2])) {
                    $layer2To3Links[$k2] = ['source' => $cat, 'target' => $inst, 'value' => 0, 'researchers' => []];
                }
                $layer2To3Links[$k2]['value']++;
                $layer2To3Links[$k2]['researchers'][] = (string)$r['full_name'];
            }

            $records[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['full_name'],
                'slug' => (string)($r['slug'] ?: ($r['id_lattes'] ?? '')),
                'lattesId' => (string)($r['id_lattes'] ?? ''),
                'department' => $dept,
                'admissionYear' => $r['admission_year'],
                'leaveYear' => $r['leave_year'],
                'destinationInstitution' => $bestDest ? $bestDest['institution'] : 'Não mapeado / Aposentado',
                'rawInstitution' => $bestDest ? $bestDest['raw_institution'] : null,
                'institutionCategory' => $bestDest ? $bestDest['institution_type'] : 'Inativo / Sem Vínculo Posterior',
                'role' => $bestDest ? ($bestDest['role'] ?: 'Docente/Pesquisador') : 'Aposentado/Inativo',
                'period' => $bestDest ? (($bestDest['start'] ?: '?') . ' – ' . ($bestDest['end'] ?: ($bestDest['is_current'] ? 'Atual' : '?'))) : '-',
                'isCurrent' => $bestDest ? $bestDest['is_current'] : false,
                'contractType' => $bestDest ? $bestDest['contract'] : null,
            ];
        }

        // Ordenar e calcular percentuais do ranking
        uasort($destMap, fn($a, $b) => $b['count'] <=> $a['count']);
        foreach ($destMap as $k => $item) {
            $destMap[$k]['percentage'] = $totalMapped > 0 ? round(($item['count'] / $totalMapped) * 100, 1) : 0.0;
        }

        arsort($categoryCounts);
        uasort($deptCounts, fn($a, $b) => $b <=> $a);

        // Montar nós e links para Sankey D3.js
        $nodes = [];
        $nodeIndex = [];
        $idx = 0;

        $addNode = function(string $name, string $type) use (&$nodes, &$nodeIndex, &$idx) {
            if (!isset($nodeIndex[$name])) {
                $nodeIndex[$name] = $idx++;
                $nodes[] = [
                    'id' => $name,
                    'name' => $name,
                    'type' => $type
                ];
            }
            return $nodeIndex[$name];
        };

        foreach (array_keys($deptCounts) as $d) {
            $addNode($d, 'department');
        }
        foreach (array_keys($categoryCounts) as $c) {
            $addNode($c, 'category');
        }
        foreach (array_keys($destMap) as $i) {
            $addNode($i, 'institution');
        }

        $links = [];
        foreach ($layer1To2Links as $l) {
            $links[] = [
                'source' => $nodeIndex[$l['source']],
                'target' => $nodeIndex[$l['target']],
                'value' => $l['value'],
                'sourceName' => $l['source'],
                'targetName' => $l['target'],
                'researchers' => $l['researchers']
            ];
        }
        foreach ($layer2To3Links as $l) {
            $links[] = [
                'source' => $nodeIndex[$l['source']],
                'target' => $nodeIndex[$l['target']],
                'value' => $l['value'],
                'sourceName' => $l['source'],
                'targetName' => $l['target'],
                'researchers' => $l['researchers']
            ];
        }

        return [
            'summary' => [
                'totalExits' => $totalExits,
                'totalMapped' => $totalMapped,
                'mappedPercent' => round(($totalMapped / max(1, $totalExits)) * 100, 1),
                'totalPublicHigherEd' => $totalPublicHigherEd,
                'publicHigherEdPercent' => round(($totalPublicHigherEd / max(1, $totalMapped)) * 100, 1),
                'totalOutStateOrIntl' => $totalOutStateOrIntl,
                'outStateOrIntlPercent' => round(($totalOutStateOrIntl / max(1, $totalMapped)) * 100, 1),
            ],
            'destinationsRanking' => array_values($destMap),
            'categoriesDistribution' => $categoryCounts,
            'departmentsDistribution' => $deptCounts,
            'sankey' => [
                'nodes' => $nodes,
                'links' => $links
            ],
            'records' => $records
        ];
    }
}


