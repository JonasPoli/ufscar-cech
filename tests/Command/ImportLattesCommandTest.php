<?php

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportLattesCommandTest extends KernelTestCase
{
    private const ID_LATTES = '9999999999990001';

    private Connection $conn;
    private string $xml;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->xml = tempnam(sys_get_temp_dir(), 'lattes');
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/></CURRICULO-VITAE>',
            self::ID_LATTES
        ));
    }

    protected function tearDown(): void
    {
        $this->conn->delete('researchers', ['id_lattes' => self::ID_LATTES]);
        unlink($this->xml);
        parent::tearDown();
    }

    public function testDeptOptionSavesTheDepartmentThatTheXmlDoesNotCarry(): void
    {
        $tester = $this->runCommand(['--file' => $this->xml, '--dept' => 'TST|Departamento de Teste']);

        $tester->assertCommandIsSuccessful();
        $row = $this->row();
        $this->assertSame('TST', $row['department_code']);
        $this->assertSame('Departamento de Teste', $row['department']);
    }

    public function testImportingAgainWithoutDeptKeepsTheDepartment(): void
    {
        $this->runCommand(['--file' => $this->xml, '--dept' => 'TST|Departamento de Teste']);
        $this->runCommand(['--file' => $this->xml]);

        $this->assertSame('TST', $this->row()['department_code']);
    }

    public function testInvalidDeptFailsBeforeImportingAnything(): void
    {
        $tester = $this->runCommand(['--file' => $this->xml, '--dept' => 'sem barra']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertFalse($this->row(), 'Nenhum currículo é gravado quando a opção é inválida.');
    }

    public function testFileThatFailsMakesTheCommandFail(): void
    {
        file_put_contents($this->xml, '<?xml version="1.0"?><NAO-E-CURRICULO/>');

        $tester = $this->runCommand(['--file' => $this->xml]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Quem chama o comando precisa saber que algo falhou.');
    }

    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:import:lattes'));
        $tester->execute($input);

        return $tester;
    }

    private function row(): array|false
    {
        return $this->conn->fetchAssociative(
            'SELECT department_code, department FROM researchers WHERE id_lattes = ?',
            [self::ID_LATTES]
        );
    }
}
