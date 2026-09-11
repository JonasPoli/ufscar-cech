<?php

namespace App\Tests\Controller\api;

use App\Service\Security\SyncTokenProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Garante que os endpoints públicos de escrita usados pelo bookmarklet só aceitam
 * requisições que apresentem o token de sincronização da instalação.
 */
class SyncTokenApiTest extends WebTestCase
{
    public function testImportHtmlIsRejectedWithoutToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/curriculum/import-html',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['idLattes' => '1234567890123456', 'html' => '<html></html>'])
        );

        $this->assertResponseStatusCodeSame(401);
        $this->assertStringContainsString('Token', (string)$client->getResponse()->getContent());
    }

    public function testSavePhotoIsRejectedWithInvalidToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/photo/save',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CECH_SYNC_TOKEN' => 'token-invalido'],
            json_encode(['idLattes' => '1234567890123456', 'photoData' => 'AAAA'])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testImportHtmlWithValidTokenReachesTheController(): void
    {
        $client = static::createClient();
        $token = static::getContainer()->get(SyncTokenProvider::class)->getToken();

        // Sem HTML no corpo: passa pela autorização e para na validação de parâmetros (400)
        $client->request(
            'POST',
            '/api/curriculum/import-html',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CECH_SYNC_TOKEN' => $token],
            json_encode(['idLattes' => '', 'html' => ''])
        );

        $this->assertResponseStatusCodeSame(400);
    }
}
