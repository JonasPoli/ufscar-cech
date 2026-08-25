<?php

namespace App\Tests\Controller\api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CorsApiTest extends WebTestCase
{
    public function testPreflightOptionsReturnsCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request(
            'OPTIONS',
            '/api/curriculum/import-html',
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://buscatextual.cnpq.br',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ]
        );

        $response = $client->getResponse();
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('https://buscatextual.cnpq.br', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', (string)$response->headers->get('Access-Control-Allow-Methods'));
    }

    public function testPostApiReturnsCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/curriculum/import-html',
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://buscatextual.cnpq.br',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['html' => '', 'idLattes' => ''])
        );

        $response = $client->getResponse();
        $this->assertEquals('https://buscatextual.cnpq.br', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
