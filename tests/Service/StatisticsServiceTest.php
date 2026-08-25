<?php

namespace App\Tests\Service;

use App\Service\StatisticsService;
use PHPUnit\Framework\TestCase;

class StatisticsServiceTest extends TestCase
{
    public function testNormalizeCourseName(): void
    {
        $this->assertSame('Pedagogia', StatisticsService::normalizeCourseName('Licenciatura Plena em Pedagogia'));
        $this->assertSame('Ciências Sociais', StatisticsService::normalizeCourseName('Bacharelado em Ciências Sociais'));
        $this->assertSame('Psicologia', StatisticsService::normalizeCourseName('Formação de Psicólogo'));
        $this->assertSame('Filosofia', StatisticsService::normalizeCourseName('Bacharel em Filosofia'));
        $this->assertSame('Letras', StatisticsService::normalizeCourseName('Licenciatura em Letras Português/Inglês'));
        $this->assertSame('Linguística', StatisticsService::normalizeCourseName('Linguística'));
        $this->assertSame('História', StatisticsService::normalizeCourseName('Licenciatura em História'));
        $this->assertSame('Direito', StatisticsService::normalizeCourseName('Bacharelado em Direito'));
        $this->assertSame('Biblioteconomia', StatisticsService::normalizeCourseName('Biblioteconomia e Documentação'));
        $this->assertSame('Engenharia de Materiais', StatisticsService::normalizeCourseName('Engenharia de Materiais'));
        $this->assertSame('Engenharia Mecânica', StatisticsService::normalizeCourseName('Engenharia Mecânica'));
        $this->assertSame('Física', StatisticsService::normalizeCourseName('Licenciatura em Física'));
        $this->assertSame('Química', StatisticsService::normalizeCourseName('Licenciatura em Química'));
        $this->assertSame('Matemática', StatisticsService::normalizeCourseName('Licenciatura Plena em Matemática'));
    }

    public function testGetMajorKnowledgeAreaForCourse(): void
    {
        $this->assertSame('Ciências Humanas', StatisticsService::getMajorKnowledgeAreaForCourse('Pedagogia'));
        $this->assertSame('Ciências Humanas', StatisticsService::getMajorKnowledgeAreaForCourse('Ciências Sociais'));
        $this->assertSame('Ciências Humanas', StatisticsService::getMajorKnowledgeAreaForCourse('Filosofia'));
        $this->assertSame('Ciências Humanas', StatisticsService::getMajorKnowledgeAreaForCourse('Psicologia'));
        $this->assertSame('Ciências Humanas', StatisticsService::getMajorKnowledgeAreaForCourse('História'));

        $this->assertSame('Ciências Sociais Aplicadas', StatisticsService::getMajorKnowledgeAreaForCourse('Biblioteconomia'));
        $this->assertSame('Ciências Sociais Aplicadas', StatisticsService::getMajorKnowledgeAreaForCourse('Direito'));
        $this->assertSame('Ciências Sociais Aplicadas', StatisticsService::getMajorKnowledgeAreaForCourse('Comunicação Social / Jornalismo'));
        $this->assertSame('Ciências Sociais Aplicadas', StatisticsService::getMajorKnowledgeAreaForCourse('Administração'));
        $this->assertSame('Ciências Sociais Aplicadas', StatisticsService::getMajorKnowledgeAreaForCourse('Ciências Econômicas'));

        $this->assertSame('Engenharias', StatisticsService::getMajorKnowledgeAreaForCourse('Engenharia de Materiais'));
        $this->assertSame('Engenharias', StatisticsService::getMajorKnowledgeAreaForCourse('Engenharia Mecânica'));
        $this->assertSame('Engenharias', StatisticsService::getMajorKnowledgeAreaForCourse('Engenharia Elétrica'));
        $this->assertSame('Engenharias', StatisticsService::getMajorKnowledgeAreaForCourse('Engenharia Metalúrgica'));

        $this->assertSame('Linguística, Letras e Artes', StatisticsService::getMajorKnowledgeAreaForCourse('Letras'));
        $this->assertSame('Linguística, Letras e Artes', StatisticsService::getMajorKnowledgeAreaForCourse('Linguística'));
        $this->assertSame('Linguística, Letras e Artes', StatisticsService::getMajorKnowledgeAreaForCourse('Música'));
        $this->assertSame('Linguística, Letras e Artes', StatisticsService::getMajorKnowledgeAreaForCourse('Artes Visuais & Cênicas'));

        $this->assertSame('Ciências Exatas e da Terra', StatisticsService::getMajorKnowledgeAreaForCourse('Física'));
        $this->assertSame('Ciências Exatas e da Terra', StatisticsService::getMajorKnowledgeAreaForCourse('Química'));
        $this->assertSame('Ciências Exatas e da Terra', StatisticsService::getMajorKnowledgeAreaForCourse('Matemática'));
        $this->assertSame('Ciências Exatas e da Terra', StatisticsService::getMajorKnowledgeAreaForCourse('Ciência da Computação'));

        $this->assertSame('Ciências da Saúde', StatisticsService::getMajorKnowledgeAreaForCourse('Educação Física'));
        $this->assertSame('Ciências da Saúde', StatisticsService::getMajorKnowledgeAreaForCourse('Fonoaudiologia'));
        $this->assertSame('Ciências da Saúde', StatisticsService::getMajorKnowledgeAreaForCourse('Enfermagem'));
    }
}
