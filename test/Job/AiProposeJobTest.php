<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use Laminas\ServiceManager\ServiceLocatorInterface;
use OERManager\Job\AiProposeJob;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\JobProgressReporter;
use OERManager\Service\Ai\JobStoppedException;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\Llm\LlmException;
use OERManager\Test\Service\Ai\FakeClassifier;
use OERManager\Test\Service\Ai\FakeLlmClient;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Entity\Job;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiProposeJobTest extends TestCase
{
    use RepresentationFactory;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/oer-job-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public static function outcomes(): array
    {
        return [[null, 'completed', null], ['stop', 'stopped', null], ['llm', 'error', 'llm'], ['unexpected',
            'error', 'unexpected']];
    }

    #[DataProvider('outcomes')]
    public function testJobPersistsTerminalStateAndOnlyLogsErrors(?string $failure, string $status, ?string $code): void
    {
        $api = $this->createMock(Manager::class);
        if ($failure !== null) {
            $exception = match ($failure) {
                'stop' => new JobStoppedException(),
                'llm' => new LlmException('Provider failed'),
                default => new \RuntimeException('Failed'),
            };
            $api->method('read')->willThrowException($exception);
        } else {
            $api->method('read')->willReturn($this->response($this->item(1, 'Title')));
        }
        $api->expects($this->never())->method('update');
        $source = $this->createMock(MediaSourceInterface::class);
        $source->method('filesFor')->willReturn([]);
        $source->method('imagesFor')->willReturn([]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), false),
            new ContextDistiller(new FakeLlmClient(['Summary']), new PromptBuilder()),
            new FakeClassifier([]),
            new FakeClassifier([])
        );
        $runner = new ProposeRunner($api, $cataloguer, $source);
        $store = new ProposalStore($this->directory);
        $logger = new class {
            public array $errors = [];
            public function err($message)
            {
                $this->errors[] = $message;
            }
        };
        $services = $this->createMock(ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([[ProposeRunner::class, $runner], [ProposalStore::class, $store],
            ['Omeka\Logger', $logger]]);
        $job = $this->getMockBuilder(AiProposeJob::class)->disableOriginalConstructor()->onlyMethods(['getArg',
            'shouldStop', 'getServiceLocator'])->getMock();
        $job->method('getArg')->with('item', 0)->willReturn(1);
        $job->method('shouldStop')->willReturn(false);
        $entity = $this->createMock(Job::class);
        $entity->method('getId')->willReturn(42);
        (new \ReflectionProperty($job, 'job'))->setValue($job, $entity);
        $job->method('getServiceLocator')->willReturn($services);
        $job->perform();
        $result = $store->read(42);
        $this->assertSame($status, $result['status']);
        $this->assertSame($code, $result['code'] ?? null);
        $this->assertCount($code === null ? 0 : 1, $logger->errors);
        if ($status === 'completed') {
            $this->assertSame([], $result['payload']['alignment']);
        }
    }

    public function testProgressReporterUsesNativeCancellationAndPersistsProgress(): void
    {
        $store = new ProposalStore($this->directory);
        $job = $this->getMockBuilder(AiProposeJob::class)->disableOriginalConstructor()
            ->onlyMethods(['shouldStop'])->getMock();
        $job->method('shouldStop')->willReturn(true);
        $progress = new JobProgressReporter($store, $job, 42);
        $progress->report('Extract', 2, 5);
        $this->assertSame(
            ['status' => 'in_progress', 'step' => 'Extract', 'done' => 2, 'total' => 5],
            $store->read(42)
        );
        $this->assertTrue($progress->shouldStop());
    }
}
