<?php

namespace App\Controller\admin;

use App\Entity\TestDatabase;
use App\Form\TestDatabaseType;
use App\Repository\TestDatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/test/database')]
final class TestDatabaseController extends AbstractController
{
    #[Route(name: 'app_admin_test_database_index', methods: ['GET'])]
    public function index(TestDatabaseRepository $testDatabaseRepository): Response
    {
        return $this->render('admin/test_database/index.html.twig', [
            'test_databases' => $testDatabaseRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_test_database_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $testDatabase = new TestDatabase();
        $form = $this->createForm(TestDatabaseType::class, $testDatabase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($testDatabase);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_test_database_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/test_database/new.html.twig', [
            'test_database' => $testDatabase,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_test_database_show', methods: ['GET'])]
    public function show(#[MapEntity] TestDatabase $testDatabase): Response
    {
        return $this->render('admin/test_database/show.html.twig', [
            'test_database' => $testDatabase,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_test_database_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[MapEntity] TestDatabase $testDatabase, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TestDatabaseType::class, $testDatabase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_test_database_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/test_database/edit.html.twig', [
            'test_database' => $testDatabase,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_test_database_delete', methods: ['POST'])]
    public function delete(Request $request, #[MapEntity] TestDatabase $testDatabase, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$testDatabase->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($testDatabase);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_test_database_index', [], Response::HTTP_SEE_OTHER);
    }
}
