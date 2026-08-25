<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin-user',
    description: 'Adicionar um usuário administrador',
)]
class AdminUserCommand extends Command
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'UserName')
            ->addArgument('arg2', InputArgument::OPTIONAL, 'Senha')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);


        // Listar usuários existentes
        $io->title('Usuários existentes');
        $users = $this->userRepository->findAll();

        if (empty($users)) {
            $io->warning('Nenhum usuário encontrado no sistema.');
        } else {
            $tableRows = [];
            foreach ($users as $user) {
                $tableRows[] = [$user->getUsername(), implode(', ', $user->getRoles())];
            }

            $io->table(['Nome de Usuário', 'Roles'], $tableRows);
        }

        // Obter argumentos
        $userName = $input->getArgument('arg1');
        $pass = $input->getArgument('arg2');

        // Solicitar valores caso não sejam informados
        if (!$userName) {
            $userName = $io->ask('Por favor, informe o nome de usuário (username)', 'admin');
        }

        if (!$pass) {
            $pass = $io->ask('Por favor, informe a senha (password)');
        }

        // Criar ou atualizar o usuário
        $user = $this->userRepository->findOneBy(['username' => $userName]);
        if (!$user) {
            $user = new User();
            $user->setUsername($userName);
            $this->em->persist($user);
        }
        $user->setRoles(['ROLE_ADMIN']);
        $passHash = $this->userPasswordHasher->hashPassword($user, $pass);
        $user->setPassword($passHash);

        $this->em->flush();

        // Mensagens de confirmação
        $io->writeln('Usuário criado com sucesso');
        $io->writeln('UserName: ' . $userName);
        $io->writeln('Senha: ' . $pass);
        $io->writeln('Senha Hash: ' . $passHash);
        $io->writeln('Role: [\'ROLE_ADMIN\']');

        $io->success('Seu Admin foi criado com sucesso');

        return Command::SUCCESS;
    }
}
