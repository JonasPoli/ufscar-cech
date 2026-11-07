<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Novidades\NovidadesService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Lista os commits do git que ainda não estão no histórico de novidades (config/novidades.yaml), para quem mantém o
 * portal escrever a explicação em linguagem simples. Só lê: não altera o arquivo.
 *
 * Os commits que só registram outros no histórico (assunto começando por "docs(novidades)") ficam de fora.
 */
#[AsCommand(name: 'app:novidades:pendentes', description: 'Lista os commits do git que ainda não estão em config/novidades.yaml')]
final class NovidadesPendentesCommand extends Command
{
    public function __construct(
        private readonly NovidadesService $novidades,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $process = new Process(['git', 'log', '--date=short', '--pretty=format:%H|%h|%ad|%s'], $this->projectDir);
        $process->run();
        if (!$process->isSuccessful()) {
            $io->error('Não foi possível ler o histórico do git: ' . trim($process->getErrorOutput()));

            return Command::FAILURE;
        }

        $known = $this->novidades->commitHashes();
        $pending = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            [$full, $short, $date, $subject] = array_pad(explode('|', $line, 4), 4, '');
            if ($full === '' || str_starts_with($subject, 'docs(novidades)')) {
                continue;
            }
            $listed = false;
            foreach ($known as $hash) {
                if ($hash !== '' && str_starts_with($full, $hash)) {
                    $listed = true;
                    break;
                }
            }
            if (!$listed) {
                $pending[] = [$short, $date, $subject];
            }
        }

        if ($pending === []) {
            $io->success('Todos os commits estão no histórico de novidades.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d commit(s) ainda sem explicação em config/novidades.yaml:', count($pending)));
        $io->table(['Commit', 'Data', 'Mensagem'], $pending);

        return Command::SUCCESS;
    }
}
