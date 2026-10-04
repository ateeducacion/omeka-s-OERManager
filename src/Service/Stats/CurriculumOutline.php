<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Settings\Settings;

/**
 * Builds the CurriculumMap of the statistics (TASK-047, spec §5.1). Core
 * adapter: touches representations, verified in the container
 * (`test/container/stats-check.php`).
 *
 * Fixed number of searches, whatever the REA count: the linked terms by id
 * (the batch TASK-006 already did), every course and every subject by their
 * configured `dcterms:type`. Stages are read through the courses'
 * `schema:inDefinedTermSet`. Only the two upper levels of the curriculum
 * (≈ 250 items) are read, never saberes or criterios (NFR-004 keeps the
 * re-catalogador selector from loading the tree; this is a once-per-request
 * read of its top). Read-only: curriculum items are inputs.
 */
final class CurriculumOutline
{
    public const TYPE_TERM = 'dcterms:type';
    public const STAGE_EDGE = 'schema:inDefinedTermSet';
    public const POSITION_TERM = 'schema:position';

    /** Settings with the `dcterms:type` literal of courses and subjects (CurriculumSearch::TYPE_SETTINGS). */
    public const COURSE_TYPE_SETTING = 'oermanager_type_educationallevel';
    public const SUBJECT_TYPE_SETTING = 'oermanager_type_about';

    /** Same edges, same preference order, as CurricularPairs::COURSE_TERMS. */
    private const COURSE_EDGES = [
        'lrmi:educationalLevel',
        'lrmi:educationalAlignment',
        'schema:inDefinedTermSet',
    ];

    /** Upper bound of each curriculum-level search (230 subjects measured). */
    public const LEVEL_CAP = 5000;

    private ApiManager $api;
    private Settings $settings;

    /** @var array<int, array{label:string, position:?int}> */
    private array $stages = [];
    /** @var array<int, array{label:string, stage:?int, position:?int}> */
    private array $courses = [];
    /** @var array<int, array{label:string, course:?int}> */
    private array $subjects = [];

    public function __construct(ApiManager $api, Settings $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
    }

    /**
     * @param int[] $courseIds Ids the REA use as course (`lrmi:educationalLevel`).
     * @param int[] $subjectIds Ids the REA use as subject (`schema:about`).
     * @param int[] $otherIds Other linked ids (axes, projects) that only need a label.
     */
    public function load(array $courseIds, array $subjectIds, array $otherIds): CurriculumMap
    {
        $this->stages = [];
        $this->courses = [];
        $this->subjects = [];

        foreach ($this->searchByType(self::COURSE_TYPE_SETTING) as $course) {
            $this->addCourse($course);
        }
        $universe = $this->searchByType(self::SUBJECT_TYPE_SETTING);
        foreach ($universe as $subject) {
            $this->addSubject($subject);
        }

        // Linked terms outside the configured types (or every term, when the
        // settings are empty): one batch, as in TASK-006.
        $labels = [];
        $wanted = array_unique(array_map('intval', [...$courseIds, ...$subjectIds, ...$otherIds]));
        $missing = array_values(array_filter(
            $wanted,
            fn (int $id): bool => !isset($this->courses[$id]) && !isset($this->subjects[$id])
        ));
        if ([] !== $missing) {
            $courseSet = array_flip(array_map('intval', $courseIds));
            $subjectSet = array_flip(array_map('intval', $subjectIds));
            foreach ((array) $this->api->search('items', ['id' => $missing])->getContent() as $term) {
                $id = (int) $term->id();
                if (isset($subjectSet[$id])) {
                    $this->addSubject($term);
                } elseif (isset($courseSet[$id])) {
                    $this->addCourse($term);
                } else {
                    $labels[$id] = (string) $term->displayTitle();
                }
            }
        }

        return new CurriculumMap($this->stages, $this->courses, $this->subjects, $labels, [] !== $universe);
    }

    /** @return AbstractEntityRepresentation[] */
    private function searchByType(string $setting): array
    {
        $type = trim((string) $this->settings->get($setting));
        if ('' === $type) {
            return [];
        }
        return (array) $this->api->search('items', [
            'property' => [['property' => self::TYPE_TERM, 'type' => 'eq', 'text' => $type]],
            // `per_page` solo lo aplica el core si viene con `page`.
            'page' => 1,
            'per_page' => self::LEVEL_CAP,
        ])->getContent();
    }

    private function addCourse(AbstractEntityRepresentation $course): int
    {
        $id = (int) $course->id();
        if (!isset($this->courses[$id])) {
            $stage = $this->linked($course, [self::STAGE_EDGE]);
            if (null !== $stage && !isset($this->stages[(int) $stage->id()])) {
                $this->stages[(int) $stage->id()] = [
                    'label' => (string) $stage->displayTitle(),
                    'position' => $this->position($stage),
                ];
            }
            $this->courses[$id] = [
                'label' => (string) $course->displayTitle(),
                'stage' => null !== $stage ? (int) $stage->id() : null,
                'position' => $this->position($course),
            ];
        }
        return $id;
    }

    private function addSubject(AbstractEntityRepresentation $subject): void
    {
        $course = $this->linked($subject, self::COURSE_EDGES);
        $this->subjects[(int) $subject->id()] = [
            'label' => (string) $subject->displayTitle(),
            'course' => null !== $course ? $this->addCourse($course) : null,
        ];
    }

    /** @param list<string> $terms */
    private function linked(AbstractEntityRepresentation $node, array $terms): ?AbstractEntityRepresentation
    {
        if (!method_exists($node, 'value')) {
            return null;
        }
        foreach ($terms as $term) {
            $value = $node->value($term);
            $resource = $value ? $value->valueResource() : null;
            if (null !== $resource) {
                return $resource;
            }
        }
        return null;
    }

    private function position(AbstractEntityRepresentation $node): ?int
    {
        if (!method_exists($node, 'value')) {
            return null;
        }
        $value = $node->value(self::POSITION_TERM);
        $raw = $value ? trim((string) $value->value()) : '';
        return preg_match('/^-?\d+$/', $raw) ? (int) $raw : null;
    }
}
