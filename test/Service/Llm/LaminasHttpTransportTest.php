<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use Laminas\Http\Client;
use Laminas\Http\Client\Adapter\Curl;
use Laminas\Http\Client\Adapter\Exception\RuntimeException;
use Laminas\Http\Response;
use OERManager\Service\Llm\LaminasHttpTransport;
use OERManager\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;

final class LaminasHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        Client::$nextException = null;
        Client::$nextResponse = (new Response())->setStatusCode(201)->setContent('{"ok":true}');
    }

    protected function tearDown(): void
    {
        Client::$nextException = null;
        Client::$nextResponse = null;
        Client::$last = null;
    }

    public function testRequestKeepsHeadersAndBodyAndDisallowsRedirects(): void
    {
        $result = (new LaminasHttpTransport(37))->send('post', 'https://llm.example/api', ['X-Test' => 'yes'], '{}');
        self::assertSame(201, $result->status());
        self::assertSame('{"ok":true}', $result->body());
        self::assertSame('https://llm.example/api', Client::$last->record['uri']);
        self::assertSame('POST', Client::$last->record['method']);
        self::assertSame(['X-Test' => 'yes'], Client::$last->record['headers']);
        self::assertSame('{}', Client::$last->record['body']);
        $options = Client::$last->record['options'];
        self::assertSame(37, $options['timeout']);
        self::assertSame(0, $options['maxredirects']);
        if (extension_loaded('curl')) {
            self::assertSame(Curl::class, $options['adapter']);
            self::assertSame(37, $options['curloptions'][CURLOPT_TIMEOUT]);
            self::assertSame(15, $options['curloptions'][CURLOPT_CONNECTTIMEOUT]);
            self::assertFalse($options['curloptions'][CURLOPT_FOLLOWLOCATION]);
        }
    }

    public function testEmptyBodyAndNonPostMethodArePreserved(): void
    {
        Client::$nextResponse->setStatusCode(503)->setContent('unavailable');
        $result = (new LaminasHttpTransport())->send('GET', 'https://llm.example/api', [], '');
        self::assertSame('GET', Client::$last->record['method']);
        self::assertArrayNotHasKey('body', Client::$last->record);
        self::assertSame(503, $result->status());
        self::assertSame('unavailable', $result->body());
    }

    public function testAdapterFailureBecomesDomainExceptionWithConfiguredTimeout(): void
    {
        Client::$nextException = new RuntimeException('Socket timed out');
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('37 s');
        (new LaminasHttpTransport(37))->send('POST', 'https://llm.example/api', [], '{}');
    }
}
