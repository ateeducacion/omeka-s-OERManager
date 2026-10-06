<?php

declare(strict_types=1);

namespace OERManager\Service\Content;

/**
 * Alineación curricular que declara un paquete eXeLearning en su iDevice
 * `lomloe` (TASK-057, RF-020): etapa, cursos, materias y los códigos de saberes
 * y criterios que eligieron sus autores. Solo saberes y criterios (decisión del
 * propietario, 2026-10-06): las competencias específicas no cuentan.
 *
 * El paquete es dato no confiable: se lee en memoria, con tope de tamaño, y el
 * XML se parsea sin red ni sustitución de entidades; un DOCTYPE que declara
 * entidades se rechaza. Las exportaciones escriben `<ode>` con o sin el
 * namespace ODE, así que se busca por nombre local.
 */
final class ElpxDeclaredAlignment
{
    private const DEFAULT_MAX_XML_BYTES = 10485760; // 10 MB, como max_entry_bytes del extractor
    private const MAX_JSON_DEPTH = 64;

    public function __construct(private int $maxXmlBytes = self::DEFAULT_MAX_XML_BYTES)
    {
    }

    /**
     * @return array{
     *     stage: string,
     *     courses: list<string>,
     *     subjects: list<array{course: string, name: string}>,
     *     knowledge: list<string>,
     *     criteria: list<string>
     * }|null null si el paquete no declara alineación o no se puede leer
     */
    public function fromFile(string $path): ?array
    {
        if (!class_exists(\ZipArchive::class) || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return null;
        }
        try {
            $stat = $zip->statName('content.xml');
            if (false === $stat || (int) $stat['size'] > $this->maxXmlBytes) {
                return null;
            }
            $xml = $zip->getFromIndex((int) $stat['index'], $this->maxXmlBytes);
        } finally {
            $zip->close();
        }

        return is_string($xml) ? $this->fromContentXml($xml) : null;
    }

    /** @return array<string,mixed>|null mismo contrato que fromFile() */
    public function fromContentXml(string $xml): ?array
    {
        if ('' === trim($xml) || strlen($xml) > $this->maxXmlBytes || 1 === preg_match('/<!ENTITY/i', $xml)) {
            return null;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }

        $xp = new \DOMXPath($doc);
        $query = '//*[local-name()="odeComponent"][*[local-name()="odeIdeviceTypeName"]="lomloe"]'
            . '/*[local-name()="jsonProperties"]';
        foreach ($xp->query($query) ?: [] as $node) {
            $declared = $this->fromJson(trim((string) $node->textContent));
            if (null !== $declared) {
                return $declared;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function fromJson(string $json): ?array
    {
        try {
            $data = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $courses = [];
        $subjects = [];
        $knowledge = [];
        $criteria = [];
        foreach ((array) ($data['lomloeSelections'] ?? []) as $selection) {
            if (!is_array($selection)) {
                continue;
            }
            $course = $this->text($selection['nivel'] ?? '');
            $subject = $this->text($selection['denominacion'] ?? '');
            $type = $this->text($selection['type'] ?? '');
            $code = match ($type) {
                'saber' => $this->text($selection['nombre'] ?? ''),
                'criterio' => $this->text($selection['codigoCriterio'] ?? ''),
                default => '',
            };
            if ('' === $code) {
                continue; // competencias u otros tipos: fuera (decisión 2026-10-06)
            }
            if ('saber' === $type) {
                $knowledge[$code] = true;
            } else {
                $criteria[$code] = true;
            }
            if ('' !== $course) {
                $courses[$course] = true;
                if ('' !== $subject) {
                    $subjects[$course . "\u{1F}" . $subject] = ['course' => $course, 'name' => $subject];
                }
            }
        }

        $selectedCourse = $this->text($data['lomloeSelectedNivel'] ?? '');
        if (!$courses && '' !== $selectedCourse) {
            $courses[$selectedCourse] = true;
        }
        if (!$courses && !$knowledge && !$criteria) {
            return null;
        }

        return [
            'stage' => $this->text($data['lomloeSelectedEtapa'] ?? ''),
            'courses' => array_keys($courses),
            'subjects' => array_values($subjects),
            'knowledge' => array_keys($knowledge),
            'criteria' => array_keys($criteria),
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }
}
