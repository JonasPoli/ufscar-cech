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

    public function testOrigemDesconhecidaNaEscritaNaoRecebeAllowOrigin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/photo/save', [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $client->getResponse()->headers->get('Vary'));
    }

    public function testPreflightDeOrigemDesconhecidaNaoRecebeAllowOrigin(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/photo/save', [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertEquals(204, $client->getResponse()->getStatusCode());
        $this->assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testRequisicaoSemOriginNaoLevaCabecalhoCors(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/photo/save', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testApiPublicaDeLeituraContinuaAbertaComAsterisco(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/catalogo', [], [], ['HTTP_ORIGIN' => 'https://qualquer.example']);

        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
