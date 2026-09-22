<?php

namespace OERManager\Test\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\ColumnType\AlignmentStatus;
use OERManager\ColumnType\Curricular;
use OERManager\ColumnType\GovernanceValue;
use OERManager\ColumnType\Integrity;
use OERManager\ColumnType\IsPublic;
use OERManager\ColumnType\Licence;
use OERManager\ColumnType\ResourceType;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    private function item(array $values = [], string $title = 'Course', int $id = 1): ItemRepresentation
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('id')->willReturn($id);
        $item->method('displayTitle')->willReturn($title);
        $item->method('value')->willReturnCallback(static function ($term, $options = []) use ($values) {
            return isset($options['all']) ? ($values[$term] ?? []) : ($values[$term][0] ?? null);
        });
        return $item;
    }

    private function value(?ItemRepresentation $resource = null, string $text = ''): ValueRepresentation
    {
        $value = $this->createMock(ValueRepresentation::class);
        $value->method('type')->willReturn($resource ? 'resource:item' : 'literal');
        $value->method('valueResource')->willReturn($resource);
        $value->method('value')->willReturn($text);
        $value->method('__toString')->willReturn($text);
        return $value;
    }

    public function testColumnsExposeTheirNativeContracts(): void
    {
        $view = new PhpRenderer();
        $checker = $this->createMock(IntegrityChecker::class);
        foreach (
            [new AlignmentStatus(), new Curricular(), new GovernanceValue(), new Licence(),
            new ResourceType(), new Integrity($checker)] as $column
        ) {
            $this->assertNotSame('', $column->getLabel());
            $this->assertIsArray($column->getResourceTypes());
            $this->assertContains($column->getMaxColumns(), [null, 1]);
            $this->assertSame('', $column->renderDataForm($view, []));
            $this->assertContains($column->getSortBy([]), [null, 'dcterms:license', 'lrmi:learningResourceType']);
            $this->assertSame($column->getLabel(), $column->renderHeader($view, []));
            $other = $this->createMock(\Omeka\Api\Representation\AbstractEntityRepresentation::class);
            $this->assertNull($column->renderContent($view, $other, []));
        }
        foreach (['Id', 'Modified', 'ResourceTemplate', 'Value', 'IsPublic'] as $name) {
            $class = 'OERManager\\ColumnType\\' . $name;
            $this->assertSame(['oer_items'], (new $class())->getResourceTypes());
        }
    }

    public function testGovernanceAndVisibilityCellsEscapeContent(): void
    {
        $view = new PhpRenderer();
        $item = $this->item(['dcterms:license' => [$this->value(null, '<License>')]]);
        $this->assertStringContainsString('&lt;License&gt;', (new Licence())->renderContent($view, $item, []));
        $this->assertStringContainsString('Sin licencia', (new Licence())->renderContent($view, $this->item(), []));
        $this->assertNull((new GovernanceValue())->renderContent($view, $item, []));
        $this->assertSame('Visibilidad', (new IsPublic())->renderHeader($view, []));
        foreach ([true, false] as $public) {
            $item = $this->item();
            $item->method('isPublic')->willReturn($public);
            $this->assertStringContainsString(
                $public ? 'oer-visibility-public' : 'oer-visibility-private',
                (new IsPublic())->renderContent($view, $item, [])
            );
        }
    }

    public function testIntegrityCellsReportCompleteAndWarningCounts(): void
    {
        $view = new PhpRenderer();
        foreach (
            [[], [['severity' => 'warning', 'message' => 'Missing']],
            [['severity' => 'warning'], ['severity' => 'warning']]] as $issues
        ) {
            $checker = $this->createMock(IntegrityChecker::class);
            $checker->expects($this->once())->method('check')
                ->with($this->isInstanceOf(ItemRepresentation::class), false)
                ->willReturn(new IntegrityResult($issues));
            $html = (new Integrity($checker))->renderContent($view, $this->item(), []);
            $this->assertStringContainsString($issues ? 'oer-integrity-warning' : 'oer-integrity-ok', $html);
        }
    }

    public function testCurricularCellsDistinguishMissingLiteralOrphanAndCompleteGraphs(): void
    {
        $view = new PhpRenderer();
        $column = new Curricular();
        $this->assertStringContainsString('Sin anclar', $column->renderContent($view, $this->item(), []));
        $course = $this->item([], '1 ESO', 2);
        $subject = $this->item(['lrmi:educationalLevel' => [$this->value($course)]], '<Maths>', 3);
        $values = [
            'schema:about' => [$this->value($subject)],
            'lrmi:educationalLevel' => [$this->value($course)],
            'lrmi:teaches' => [$this->value($course)],
            'lrmi:assesses' => [$this->value($course)],
        ];
        $item = $this->item($values);
        $this->assertSame(AlignmentStatus::COMPLETE, AlignmentStatus::statusFor($item));
        $this->assertStringContainsString('&lt;Maths&gt;', $column->renderContent($view, $item, []));
        $this->assertStringContainsString('Completo', (new AlignmentStatus())->renderContent($view, $item, []));
        $values['schema:about'] = [$this->value(null, '<Literal>')];
        $html = $column->renderContent($view, $this->item($values), []);
        $this->assertStringContainsString('Anclaje incompleto', $html);
        $this->assertStringContainsString('valor literal', $html);
        $this->assertStringContainsString('Sin materia', $html);
    }
}
