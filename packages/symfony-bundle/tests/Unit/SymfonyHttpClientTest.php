<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Symfony\Bridge\SymfonyHttpClient;

final class SymfonyHttpClientTest extends TestCase
{
    #[Test]
    public function itAdaptsSymfonyResponsesToTheSdkHttpAbstraction(): void
    {
        $client = new SymfonyHttpClient(new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://platform.example/webhooks/orders', $url);
            self::assertContains('Signature: signed', $options['headers']);

            return new MockResponse('accepted', [
                'http_code' => 202,
                'response_headers' => ['X-Webhook-Id: demo-1'],
            ]);
        }));

        $response = $client->request('POST', 'https://platform.example/webhooks/orders', [
            'headers' => ['Signature' => 'signed'],
        ]);

        $content = '';
        foreach ($client->stream($response, 3.0) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isFirst()) {
                continue;
            }

            $content .= $chunk->getContent();
        }

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['demo-1'], $response->getHeaders(false)['x-webhook-id']);
        self::assertSame('accepted', $content);
    }
}
