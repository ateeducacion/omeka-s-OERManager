<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\LinkMedia;
use PHPUnit\Framework\TestCase;

/**
 * Owner's review of PR #100: a link is a media of the REA, ingested by the
 * Omeka ingester that fits it.
 */
class LinkMediaTest extends TestCase
{
    /** Omeka's own `oembed.whitelist` entries (application/config/module.config.php). */
    private const OEMBED = [
        ['#^https?://((m|www)\.)?youtube\.com/(watch|playlist|shorts/)#i', 'https://www.youtube.com/oembed'],
        ['#^https?://youtu\.be/#i', 'https://www.youtube.com/oembed'],
        ['#^https?://(.+\.)?vimeo\.com/#i', 'https://vimeo.com/api/oembed.json'],
    ];

    public function testAYoutubeVideoUsesTheYoutubeIngester(): void
    {
        foreach (['https://www.youtube.com/watch?v=abc123', 'https://youtu.be/abc123', 'http://m.youtube.com/watch?v=x&t=3'] as $url) {
            $this->assertSame(['o:ingester' => 'youtube', 'o:source' => $url], LinkMedia::spec($url, self::OEMBED), $url);
        }
    }

    public function testAnotherOembedProviderUsesTheOembedIngester(): void
    {
        foreach (['https://vimeo.com/76979871', 'https://www.youtube.com/playlist?list=PL1', 'https://www.youtube.com/shorts/abc'] as $url) {
            $this->assertSame(['o:ingester' => 'oembed', 'o:source' => $url], LinkMedia::spec($url, self::OEMBED), $url);
        }
    }

    public function testAnyOtherLinkIsDownloadedByTheUrlIngester(): void
    {
        $url = 'https://ejemplo.es/recursos/guía.pdf';
        $this->assertSame(['o:ingester' => 'url', 'ingest_url' => $url], LinkMedia::spec($url, self::OEMBED));
        // Without Omeka's list, only YouTube is told apart.
        $this->assertSame(['o:ingester' => 'url', 'ingest_url' => 'https://vimeo.com/1'], LinkMedia::spec('https://vimeo.com/1', []));
        // A malformed pattern never breaks the save.
        $this->assertSame('url', LinkMedia::spec('https://a.es/x', [['not a regex', 'x'], 'junk'])['o:ingester']);
    }

    /** Security review: the `url` ingester makes the server download the link (SSRF). */
    public function testALinkToAnInternalAddressIsNotDownloaded(): void
    {
        $dns = static fn (string $host): array => [
            'ejemplo.es' => ['93.184.216.34'],
            'intranet.local' => ['10.0.0.8'],
            'mixto.es' => ['93.184.216.34', '192.168.1.4'],
            'v6.es' => ['2001:db8::1'],
            'publico6.es' => ['2a00:1450:4003:80f::200e'],
        ][$host] ?? [];
        foreach ([
            'https://ejemplo.es/guía.pdf' => true,
            'https://EJEMPLO.es./a.pdf' => true,
            'https://publico6.es/a.pdf' => true,
            'http://localhost/x' => false,
            'http://127.0.0.1:8080/x' => false,
            'http://169.254.169.254/latest/meta-data/' => false,
            'http://[::1]/x' => false,
            'http://intranet.local/x' => false,
            'http://mixto.es/x' => false,
            'http://v6.es/x' => false,
            'http://no-resuelve.es/x' => false,
            'http://0x7f000001/x' => false,
        ] as $url => $public) {
            $this->assertSame($public, LinkMedia::isPublicHost($url, $dns), $url);
        }
    }

    /** Security review (parser differential): only an unambiguous authority is a web link. */
    public function testALinkWhoseHostCouldBeReadTwoWaysIsNotAWebLink(): void
    {
        foreach ([
            'http://ejemplo.es@127.0.0.1/', 'http://user:pw@ejemplo.es/', 'http://ejemplo.es\\@127.0.0.1/',
            'http://127.0.0.1\\.ejemplo.es/', 'http://%31%32%37.0.0.1/', 'https://ejemplo.es:8443\\x',
        ] as $bad) {
            $this->assertFalse(\OERManager\Service\Wizard\ReaWizardSave::isWebLink($bad), $bad);
        }
        $this->assertTrue(\OERManager\Service\Wizard\ReaWizardSave::isWebLink('https://ejemplo.es/perfil/@ana?mail=a@b.es'));
        $this->assertTrue(\OERManager\Service\Wizard\ReaWizardSave::isWebLink('https://[2001:4860::8888]/x'));
    }
}
