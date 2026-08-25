<?php

namespace App\Controller\admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('/', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->userRepo->findAll();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();

        if ($request->isMethod('POST')) {
            $username = trim((string)$request->request->get('username'));
            $password = (string)$request->request->get('password');
            $role = (string)$request->request->get('role', 'ROLE_ADMIN');

            if ($username !== '' && $password !== '') {
                $user->setUsername($username);
                $user->setRoles([$role]);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));

                $this->em->persist($user);
                $this->em->flush();

                $this->addFlash('success', "Usuário \"{$username}\" criado com sucesso.");
                return $this->redirectToRoute('app_admin_user_index');
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'isNew' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        if ($request->isMethod('POST')) {
            $username = trim((string)$request->request->get('username'));
            $password = (string)$request->request->get('password');
            $role = (string)$request->request->get('role', 'ROLE_ADMIN');

            if ($username !== '') {
                $user->setUsername($username);
                $user->setRoles([$role]);

                if ($password !== '') {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                }

                $this->em->flush();

                $this->addFlash('success', "Usuário \"{$username}\" atualizado com sucesso.");
                return $this->redirectToRoute('app_admin_user_index');
            }
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'isNew' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        if ($this->getUser() === $user) {
            $this->addFlash('error', 'Você não pode excluir o usuário com o qual está autenticado.');
            return $this->redirectToRoute('app_admin_user_index');
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), (string)$request->request->get('_token'))) {
            $username = $user->getUsername();
            $this->em->remove($user);
            $this->em->flush();
            $this->addFlash('success', "Usuário \"{$username}\" removido com sucesso.");
        }

        return $this->redirectToRoute('app_admin_user_index');
    }
}
