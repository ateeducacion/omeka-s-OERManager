<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\CurriculumTermResolver;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\CurriculumSearch;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use PHPUnit\Framework\TestCase;

final class ProposeRunnerTest extends TestCase
{
    use RepresentationFactory;

    public function testProposalUsesMetadataAndExistingTargetLabelsWithoutCatalogWrites(): void
    {
        $api = $this->createMock(Manager::class);
        $item = $this->item(1, 'Title', ['dcterms:description' => [$this->value('Description'), $this->value('Title'),
            $this->value(' '), $this->value('', null, 'resource:item')]]);
        $api->method('read')->willReturnCallback(function ($resource, $id) use ($item) {
            if ($id === 99) {
                throw new \RuntimeException('Deleted target');
            }
            return $this->response($id === 1 ? $item : $this->item($id, 'Term'));
        });
        $api->expects($this->never())->method('update');
        $media = $this->createMock(MediaSourceInterface::class);
        $media->expects($this->once())->method('filesFor')->with(1)->willReturn([]);
        $media->expects($this->once())->method('imagesFor')->with(1)->willReturn([]);
        $classifier = new FakeClassifier(['lrmi:teaches' => [2, 99], 'lrmi:assesses' => [99]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), false),
            new ContextDistiller(new FakeLlmClient(['Summary']), new PromptBuilder()),
            $classifier,
            new FakeClassifier([])
        );
        $result = (new ProposeRunner($api, $cataloguer, $media))->run(1);
        $this->assertSame(['lrmi:teaches' => [['id' => 2, 'title' => 'Term']]], $result['alignment']);
        $this->assertStringContainsString("Title\nDescription", $classifier->received[0]);
        $this->assertSame([], $result['justifications']);
    }

    public function testResolverUsesBoundedSearchForEveryDimension(): void
    {
        $search = $this->createMock(CurriculumSearch::class);
        $search->expects($this->once())->method('searchEtapas')->with('', 300)->willReturn([['id' => 1]]);
        $search->expects($this->once())->method('searchAxes')->with('', 300)->willReturn([['id' => 2]]);
        $search->expects($this->once())->method('searchDimension')->with(
            'schema:about',
            '',
            ['level' => 3],
            300
        )->willReturn([['id' => 4]]);
        $search->expects($this->once())->method('searchSubjectFamilies')->with(
            1,
            300
        )->willReturn([['name' => 'Math']]);
        $search->expects($this->once())->method('searchLeaves')->with(
            'lrmi:teaches',
            1,
            'Math',
            300
        )->willReturn([['id' => 5]]);
        $resolver = new CurriculumTermResolver($search);
        $this->assertSame([['id' => 1]], $resolver->listCandidates('etapa'));
        $this->assertSame([['id' => 2]], $resolver->listCandidates('dcterms:relation'));
        $this->assertSame([['id' => 4]], $resolver->listCandidates('schema:about', ['level' => 3]));
        $this->assertSame([['name' => 'Math']], $resolver->listSubjectFamilies(1));
        $this->assertSame([['id' => 5]], $resolver->listLeaves('lrmi:teaches', 1, 'Math'));
    }
}
