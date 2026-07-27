<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\MediaSourceInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Ensambla el payload de propuesta para el navegador a partir de un itemId: lee el
 * item, construye el texto de metadatos, obtiene medios, llama al propose y
 * enriquece el alineamiento a chips {id,title}. Centraliza lo que antes estaba en
 * IndexController para que el AiProposeJob (2º plano) lo reutilice (TASK-020). No
 * escribe nada en el catálogo.
 */
final class ProposeRunner
{
    public function __construct(
        private ApiManager $api,
        private AiCataloguer $cataloguer,
        private MediaSourceInterface $mediaSource
    ) {
    }

    /** @return array<string,mixed> */
    public function run(int $itemId, ?ProgressReporter $progress = null): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $proposal = $this->cataloguer->propose(
            $this->itemMetadataText($item),
            $this->mediaSource->filesFor($itemId),
            $this->mediaSource->imagesFor($itemId),
            $progress
        );

        return [
            'alignment' => $this->enrichLabels($proposal['alignment']),
            'justifications' => $proposal['justifications'] ?? [],
            'content' => $proposal['content'],
            'debug' => $proposal['debug'],
        ];
    }

    private function itemMetadataText(ItemRepresentation $item): string
    {
        $parts = [];
        $title = trim((string) $item->displayTitle(''));
        if ('' !== $title) {
            $parts[] = $title;
        }
        foreach ($item->values() as $info) {
            foreach ($info['values'] as $value) {
                if ('literal' !== $value->type()) {
                    continue;
                }
                $text = trim((string) $value->value());
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }
        return implode("\n", array_values(array_unique($parts)));
    }

    /**
     * @param array<string,int[]> $alignment
     * @return array<string,array<int,array{id:int,title:string}>>
     */
    private function enrichLabels(array $alignment): array
    {
        $out = [];
        foreach ($alignment as $term => $ids) {
            $list = [];
            foreach ($ids as $id) {
                try {
                    $title = (string) $this->api->read('items', (int) $id)->getContent()->displayTitle();
                } catch (\Exception $e) {
                    continue;
                }
                $list[] = ['id' => (int) $id, 'title' => $title];
            }
            if ($list) {
                $out[$term] = $list;
            }
        }
        return $out;
    }
}
