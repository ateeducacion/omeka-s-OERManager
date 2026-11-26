<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\UploadPolicy;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    private const OPEN = ['disable_file_validation' => true];

    public function testTheWizardFamiliesAreAccepted(): void
    {
        foreach ([
            ['application/zip', 'clase.elpx'], ['application/zip', 'web.zip'], ['application/pdf', 'f.pdf'],
            ['image/png', 'a.png'], ['audio/mpeg', 'a.mp3'], ['video/mp4', 'a.mp4'],
            ['application/vnd.oasis.opendocument.text', 'a.odt'],
            ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'a.pptx'],
            ['application/msword', 'a.doc'],
            ['text/rtf', 'apuntes.rtf'], ['application/vnd.ms-office', 'viejo.xls'], ['application/CDFV2', 'viejo.doc'],
        ] as [$type, $name]) {
            $this->assertNull(UploadPolicy::refusal($type, $name, self::OPEN), $name);
        }
    }

    public function testOtherContentIsRefusedWhateverTheExtension(): void
    {
        $this->assertSame('type', UploadPolicy::refusal('application/x-dosexec', 'clase.pdf', self::OPEN));
        $this->assertSame('type', UploadPolicy::refusal('text/html', 'a.html', self::OPEN));
        $this->assertSame('type', UploadPolicy::refusal('', 'a.pdf', self::OPEN));
    }

    public function testOmekasAllowListsApplyWhenValidationIsOn(): void
    {
        $settings = [
            'disable_file_validation' => false,
            'media_type_whitelist' => ['application/zip', 'application/pdf'],
            'extension_whitelist' => ['zip', 'pdf'],
        ];
        $this->assertNull(UploadPolicy::refusal('application/pdf', 'a.PDF', $settings));
        $this->assertSame('extension', UploadPolicy::refusal('application/zip', 'clase.elpx', $settings));
        $this->assertSame('extension', UploadPolicy::refusal('image/png', 'a.png', $settings));
        $this->assertSame('extension', UploadPolicy::refusal('application/pdf', 'sin-extension', $settings));
    }

    public function testTheSmallerPhpLimitIsTheUploadLimit(): void
    {
        $this->assertSame(8 * 1024 * 1024, UploadPolicy::limitBytes('64M', '8M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, UploadPolicy::limitBytes('2G', '0'));
        $this->assertSame(512 * 1024, UploadPolicy::limitBytes('512K', '1M'));
        $this->assertSame(1000, UploadPolicy::limitBytes('1000', '1000'));
    }
}
