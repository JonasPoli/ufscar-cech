<?php

namespace App\Service;

use App\Service\Thesaurus\CountryResolverService;
use App\Service\Thesaurus\InstitutionResolverService;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class StatisticsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstitutionResolverService $institutionResolver,
        private readonly CountryResolverService $countryResolver,
        private readonly JournalResolverService $journalResolver
    ) {}

    /**
     * Normaliza e consolida variações de cursos e áreas (Tesauro de Formação Acadêmica).
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
     * Resumo quantitativo global do centro.
     */
    public function getGlobalSummary(): array
    {
        $conn = $this->em->getConnection();

        $researchersCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM researchers WHERE status = 1");
        $productionsCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items");
        $articlesQualisCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO' AND qualis IS NOT NULL AND qualis != ''");
        $orientationsCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM orientations WHERE nature = 'CONCLUIDA'");
        $booksCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type IN ('LIVRO', 'CAPITULO')");

        return [
            'totalResearchers' => $researchersCount,
            'totalProductions' => $productionsCount,
            'totalArticlesQualis' => $articlesQualisCount,
            'totalOrientations' => $orientationsCount,
            'totalBooksAndChapters' => $booksCount,
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
            LIMIT 15
        ";

        return $conn->fetchAllAssociative($sql);
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
     * Fig. 13: Rede de Coautoria e Colaboração Docente.
     */
    public function getFig13CoauthorshipNetwork(int $limit = 10): array
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT 
                r1.full_name as author1,
                r1.slug as slug1,
                r2.full_name as author2,
                r2.slug as slug2,
                COUNT(DISTINCT pa1.production_item_id) as collaborations
            FROM production_authors pa1
            JOIN production_authors pa2 
                ON pa1.production_item_id = pa2.production_item_id 
               AND pa1.id_lattes < pa2.id_lattes
            JOIN researchers r1 ON r1.id_lattes = pa1.id_lattes
            JOIN researchers r2 ON r2.id_lattes = pa2.id_lattes
            GROUP BY r1.full_name, r1.slug, r2.full_name, r2.slug
            ORDER BY collaborations DESC
            LIMIT :lim
        ";

        return $conn->fetchAllAssociative($sql, ['lim' => $limit], ['lim' => \PDO::PARAM_INT]);
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

        $allExps = $conn->fetchAllAssociative("
            SELECT pe.researcher_id, pe.institution_name, pe.role_name, pe.contract_type, pe.start_year, pe.end_year, pe.is_current
            FROM professional_experiences pe
            WHERE pe.institution_name IS NOT NULL AND TRIM(pe.institution_name) != ''
            ORDER BY pe.is_current DESC, pe.end_year DESC, pe.start_year DESC
        ");

        $expsByResearcher = [];
        foreach ($allExps as $exp) {
            $expsByResearcher[$exp['researcher_id']][] = $exp;
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


