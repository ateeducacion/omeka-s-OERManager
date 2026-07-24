<?php

namespace OERManager\Job;

use OERManager\Service\Ai\JobProgressReporter;
use OERManager\Service\Ai\JobStoppedException;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Llm\LlmException;
use Omeka\Job\AbstractJob;

/**
 * Ejecuta el «Proponer con IA» de un item en 2º plano (TASK-020): corre el
 * ProposeRunner reportando fase a fase y deja el resultado en el ProposalStore para
 * que el polling lo recoja. NUNCA escribe en el catálogo (ADR-0007): solo propone.
 * Impersona a su owner (identidad nativa del Job) → la ACL del propose se preserva.
 */
class AiProposeJob extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var ProposeRunner $runner */
        $runner = $services->get(ProposeRunner::class);
        /** @var ProposalStore $store */
        $store = $services->get(ProposalStore::class);

        $jobId = (int) $this->job->getId();
        $itemId = (int) $this->getArg('item', 0);
        $largePdf = (string) $this->getArg('large_pdf', 'ask');

        $progress = new JobProgressReporter($store, $this, $jobId);

        try {
            $payload = $runner->run($itemId, $largePdf, $progress);
            $store->write($jobId, ['status' => 'completed', 'payload' => $payload]);
        } catch (JobStoppedException $e) {
            $store->write($jobId, ['status' => 'stopped']);
        } catch (LlmException $e) {
            $services->get('Omeka\Logger')->err('OERManager ai propose job item ' . $itemId . ': ' . $e->getMessage());
            $store->write($jobId, ['status' => 'error', 'code' => 'llm']);
        } catch (\Throwable $e) {
            $services->get('Omeka\Logger')->err('OERManager ai propose job item ' . $itemId . ': ' . $e->getMessage());
            $store->write($jobId, ['status' => 'error', 'code' => 'unexpected']);
        }
    }
}
