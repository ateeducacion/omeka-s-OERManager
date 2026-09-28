<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchRequest;
use OERManager\Service\Governance\GovernanceFields;
use PHPUnit\Framework\TestCase;

final class BatchRequestTest extends TestCase
{
    public function testTickedFieldsAreNormalisedAndModeDefaultsToFill(): void
    {
        $request = BatchRequest::fromPost([
            GovernanceFields::CREATOR => [' Ana ', '', 'Luis'],
            GovernanceFields::LICENCE => 'https://creativecommons.org/licenses/by/4.0/',
        ], null);

        $this->assertTrue($request->isValid());
        $this->assertSame(BatchRequest::MODE_FILL, $request->mode);
        $this->assertSame(['Ana', 'Luis'], $request->raw[GovernanceFields::CREATOR]);
        $this->assertSame(['https://creativecommons.org/licenses/by/4.0/'], $request->raw[GovernanceFields::LICENCE]);
    }

    public function testSourceAndUnknownTermsAreNeverBatched(): void
    {
        $request = BatchRequest::fromPost([
            GovernanceFields::SOURCE => 'https://example.org/rea/1',
            'dcterms:title' => 'x',
            GovernanceFields::PUBLISHER => 'ACME',
        ], 'replace');

        $this->assertSame([GovernanceFields::PUBLISHER], array_keys($request->raw));
        $this->assertSame(BatchRequest::MODE_REPLACE, $request->mode);
    }

    public function testATickedFieldWithoutAValueIsRequiredNotAClear(): void
    {
        $request = BatchRequest::fromPost([GovernanceFields::LICENCE => '  '], 'replace');

        $this->assertFalse($request->isValid());
        $this->assertSame('required', $request->errors[GovernanceFields::LICENCE]);
    }

    public function testNoFieldUnknownModeAndInvalidValuesAreErrors(): void
    {
        $this->assertSame('no-field', BatchRequest::fromPost([], 'fill')->errors['_']);
        $this->assertSame('no-field', BatchRequest::fromPost('not-an-array', 'fill')->errors['_']);
        $this->assertSame('mode', BatchRequest::fromPost([GovernanceFields::CREATOR => ['Ana']], 'append')->errors['_']);
        $this->assertSame(
            'not-http-uri',
            BatchRequest::fromPost([GovernanceFields::LICENCE => 'ccbysa'], 'fill')->errors[GovernanceFields::LICENCE]
        );
        $this->assertSame(
            'too-many',
            BatchRequest::fromPost([GovernanceFields::PUBLISHER => ['A', 'B']], 'fill')->errors[GovernanceFields::PUBLISHER]
        );
    }
}
