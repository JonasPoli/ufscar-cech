<?php

namespace App\Tests\Support;

use App\Support\PageCacheFastPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class PageCacheFastPathTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fast_path_test_' . uniqid('', true);
        (new Filesystem())->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    public function testBotFamily(): void
    {
        $this->assertSame('perplexitybot', PageCacheFastPath::botFamily('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)'));
        $this->assertSame('amazonbot', PageCacheFastPath::botFamily('Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)'));
        $this->assertSame('bingbot', PageCacheFastPath::botFamily('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'));
        $this->assertNull(PageCacheFastPath::botFamily('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'));
        $this->assertNull(PageCacheFastPath::botFamily(''));
    }

    public function testBotIsThrottledAboveTheLimitPerMinute(): void
    {
        $server = ['REQUEST_URI' => '/explorar/assunto/educacao', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; PerplexityBot/1.0)'];
        $now = 1_800_000_000;

        for ($i = 1; $i <= PageCacheFastPath::BOT_REQUESTS_PER_MINUTE; $i++) {
            $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $server, $now), "pedido {$i} dentro do limite");
        }
        $this->assertTrue(PageCacheFastPath::throttleBot($this->tempDir, $server, $now));

        // Outro robô tem a sua própria cota; no minuto seguinte a cota recomeça
        $other = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Amazonbot/0.1)'] + $server;
        $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $other, $now));
        $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $server, $now + 60));
        $this->assertCount(1, glob($this->tempDir . '/' . PageCacheFastPath::THROTTLE_SUBDIR . '/perplexitybot-*'));
    }

    public function testVisitorsRobotsAndSitemapAreNeverThrottled(): void
    {
        $browser = ['REQUEST_URI' => '/busca?q=x', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0'];
        $bot = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'];

        for ($i = 0; $i < PageCacheFastPath::BOT_REQUESTS_PER_MINUTE + 5; $i++) {
            $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $browser, 1_800_000_000));
            $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $bot + ['REQUEST_URI' => '/robots.txt'], 1_800_000_000));
            $this->assertFalse(PageCacheFastPath::throttleBot($this->tempDir, $bot + ['REQUEST_URI' => '/sitemap.xml'], 1_800_000_000));
        }
    }

    public function testCanonicalUri(): void
    {
        $this->assertSame('/professor/fulano', PageCacheFastPath::canonicalUri('/professor/fulano', ['tema' => 'ensino']));
        $this->assertSame('/professor/fulano?aba=busca&tema=ensino', PageCacheFastPath::canonicalUri('/professor/fulano', ['tema' => 'ensino', 'aba' => 'busca']));
        $this->assertSame('/professor/fulano/fragment/analytics?x=1', PageCacheFastPath::canonicalUri('/professor/fulano/fragment/analytics', ['x' => '1']));
        $this->assertSame('/projetos?pagina=2&situacao=CONCLUIDO', PageCacheFastPath::canonicalUri('/projetos', ['situacao' => 'CONCLUIDO', 'pagina' => '2']));
        $this->assertSame('/', PageCacheFastPath::canonicalUri('/', []));
    }

    public function testSessionCookie(): void
    {
        $this->assertTrue(PageCacheFastPath::hasSessionCookie(['PHPSESSID' => 'abc']));
        $this->assertFalse(PageCacheFastPath::hasSessionCookie(['_ga' => 'GA1.2']));
    }
}
