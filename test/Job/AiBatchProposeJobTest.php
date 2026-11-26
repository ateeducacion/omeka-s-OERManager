<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use Laminas\ServiceManager\ServiceLocatorInterface;
use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Ai\StoredProposals;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Test\Service\Ai\FakeClassifier;
use OERManager\Test\Service\Ai\FakeLlmClient;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Entity\Job;
use Omeka\Settings\Settings;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;

final class AiBatchProposeJobTest extends TestCase
{
    use RepresentationFactory;

    private string $directory = '';

    protected function tearDown(): void
    {
        // Only the run's own directory: an empty path would glob the filesystem root.
        if ('' === $this->directory || !is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * perform() wires the real services: the eligible REA are proposed with the
     * configured model and stored, the ACL decides each one, and the final
     * state lands in the ProposalStore under the Job id.
     *
     * @return array{0:?array, 1:list<array>, 2:list<string>}
     */
    private function perform(array $ids, bool $failLlm, bool $stop = false): array
    {
        $this->directory = sys_get_temp_dir() . '/oer-ai-batch-job-' . bin2hex(random_bytes(6));
        $api = $this->createMock(Manager::class);
        $api->method('read')->willReturnCallback(fn ($resource, $id) => $this->response($this->item((int) $id, 'REA')));
        $api->expects($this->never())->method('update');
        $source = $this->createMock(MediaSourceInterface::class);
        $source->method('filesFor')->willReturn([]);
        $source->method('imagesFor')->willReturn([]);
        $classifier = $failLlm
            ? new class implements \OERManager\Service\Ai\ClassifierInterface {
                public function classify(\OERManager\Service\Content\ItemContext $context): array
                {
                    throw new \OERManager\Service\Llm\LlmException('quota');
                }
            }
            : new FakeClassifier([]);
        $runner = new ProposeRunner($api, new AiCataloguer(
            new ContentExtractor(),
            new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), false),
            new ContextDistiller(new FakeLlmClient(array_fill(0, 10, 'Summary')), new PromptBuilder()),
            $classifier,
            new FakeClassifier([])
        ), $source);
        $stored = [];
        $proposals = $this->createMock(StoredProposals::class);
        $proposals->method('mayUpdate')->willReturnCallback(static fn (int $id) => 3 !== $id);
        $proposals->method('store')->willReturnCallback(
            static function (int $id, array $payload, string $model) use (&$stored): bool {
                $stored[] = [$id, $model];
                return true;
            }
        );
        $eligibility = $this->createMock(BatchProposeEligibility::class);
        $eligibility->method('stillEligible')->willReturnCallback(static fn (int $id) => 2 !== $id);
        $settings = new Settings();
        $settings->set(LlmSettings::MODEL, 'claude-test');
        $logger = new class {
            public array $errors = [];
            public function err($message)
            {
                $this->errors[] = $message;
            }
        };
        $store = new ProposalStore($this->directory);
        $services = $this->createMock(ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([
            [ProposeRunner::class, $runner],
            [StoredProposals::class, $proposals],
            [BatchProposeEligibility::class, $eligibility],
            ['Omeka\Acl', new \Omeka\Permissions\Acl()],
            ['Omeka\Logger', $logger],
            ['Omeka\Settings', $settings],
            [ProposalStore::class, $store],
        ]);
        $job = $this->getMockBuilder(AiBatchProposeJob::class)->disableOriginalConstructor()
            ->onlyMethods(['getArg', 'shouldStop', 'getServiceLocator'])->getMock();
        $job->method('getArg')->with('ids', [])->willReturn(array_map('strval', $ids));
        $job->method('shouldStop')->willReturn($stop);
        $job->method('getServiceLocator')->willReturn($services);
        $entity = $this->createMock(Job::class);
        $entity->method('getId')->willReturn(42);
        (new \ReflectionProperty($job, 'job'))->setValue($job, $entity);
        $job->perform();
        return [$store->read(42), $stored, $logger->errors];
    }

    public function testPerformProposesTheEligibleReaAndRecordsTheOutcome(): void
    {
        [$state, $stored, $errors] = $this->perform([1, 2, 3, 0, 4], false);
        $this->assertSame(['kind' => 'ai-batch', 'status' => 'completed', 'batch' => 'ai-batch-42'], array_slice($state, 0, 3, true));
        $this->assertSame([[1, 'claude-test'], [4, 'claude-test']], $stored, 'not 2 (left the queue) nor 3 (no ACL), and 0 is dropped');
        $this->assertSame(['done' => 4, 'total' => 4, 'proposed' => 2, 'skipped' => 2], array_slice($state['tallies'], 0, 4, true));
        $this->assertSame([], $errors);
    }

    public function testPerformRecordsAnAbortedBatchAsAnError(): void
    {
        [$state, $stored, $errors] = $this->perform(range(4, 12), true);
        $this->assertSame('error', $state['status']);
        $this->assertTrue($state['tallies']['aborted']);
        $this->assertSame([], $stored);
        $this->assertCount(AiBatchProposeJob::ABORT_AFTER, $errors);
    }

    public function testPerformRecordsAStoppedBatch(): void
    {
        [$state, $stored] = $this->perform([1, 4], false, true);
        $this->assertSame('stopped', $state['status']);
        $this->assertSame([], $stored);
    }

    private function progress(int $stopAt = PHP_INT_MAX): ProgressReporter
    {
        return new class ($stopAt) implements ProgressReporter {
            public array $reports = [];
            public function __construct(private int $stopAt)
            {
            }
            public function report(string $step, int $done, int $total): void
            {
                $this->reports[] = [$done, $total];
            }
            public function shouldStop(): bool
            {
                return end($this->reports)[0] >= $this->stopAt;
            }
        };
    }

    private function runBatch(array $ids, callable $propose, ?callable $eligible = null, ?callable $store = null, ?ProgressReporter $progress = null, array &$stored = [], array &$logged = []): array
    {
        return AiBatchProposeJob::run(
            $ids,
            $eligible ?? static fn (int $id): bool => true,
            $propose,
            $store ?? static function (int $id, array $payload) use (&$stored): bool {
                $stored[] = $id;
                return true;
            },
            $progress ?? $this->progress(),
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );
    }

    public function testEveryEligibleReaIsProposedAndStored(): void
    {
        $stored = [];
        $progress = $this->progress();
        $tallies = $this->runBatch([1, 2, 3], static fn (int $id): array => ['alignment' => []], static fn (int $id): bool => 2 !== $id, null, $progress, $stored);
        $this->assertSame(['done' => 3, 'total' => 3, 'proposed' => 2, 'skipped' => 1, 'failed' => [], 'stopped' => false, 'aborted' => false], $tallies);
        $this->assertSame([1, 3], $stored);
        $this->assertSame([0, 3], $progress->reports[0]);
        $this->assertSame([3, 3], end($progress->reports));
    }

    public function testAReaThatIsNoLongerEligibleIsSkippedWithoutCallingTheLlm(): void
    {
        $called = [];
        $this->runBatch([1, 2], static function (int $id) use (&$called): array {
            $called[] = $id;
            return [];
        }, static fn (int $id): bool => 1 === $id);
        $this->assertSame([1], $called);
    }

    public function testAFailureOnOneReaDoesNotStopTheBatch(): void
    {
        $logged = [];
        $stored = [];
        $tallies = $this->runBatch([1, 2, 3], static function (int $id): array {
            if (2 === $id) {
                throw new \RuntimeException('boom');
            }
            return [];
        }, null, null, null, $stored, $logged);
        $this->assertSame(2, $tallies['proposed']);
        $this->assertSame([['id' => 2, 'code' => 'unexpected']], $tallies['failed']);
        $this->assertSame(['OERManager ai batch item 2: boom'], $logged);
    }

    public function testAStoreThatWritesNothingIsAFailure(): void
    {
        $tallies = $this->runBatch([1], static fn (): array => [], null, static fn (): bool => false);
        $this->assertSame([['id' => 1, 'code' => 'not_stored']], $tallies['failed']);
    }

    public function testFiveLlmErrorsInARowAbort(): void
    {
        $calls = 0;
        $tallies = $this->runBatch(range(1, 8), static function () use (&$calls): array {
            $calls++;
            throw new LlmException('quota');
        });
        $this->assertTrue($tallies['aborted']);
        $this->assertSame(5, $calls);
        $this->assertSame(5, $tallies['done']);
        $this->assertSame('llm', $tallies['failed'][0]['code']);
    }

    public function testASuccessResetsTheStreak(): void
    {
        $tallies = $this->runBatch(range(1, 9), static function (int $id): array {
            if (5 !== $id) {
                throw new LlmException('slow');
            }
            return [];
        });
        $this->assertFalse($tallies['aborted']);
        $this->assertSame(9, $tallies['done']);
        $this->assertSame(1, $tallies['proposed']);
    }

    public function testStopIsHonouredBetweenReas(): void
    {
        $tallies = $this->runBatch([1, 2, 3, 4], static fn (): array => [], null, null, $this->progress(2));
        $this->assertTrue($tallies['stopped']);
        $this->assertSame(2, $tallies['done']);
    }
}
