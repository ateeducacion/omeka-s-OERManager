<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-067 container verification (ADR-0023 §1-2) with REAL NON-ADMINISTRATOR
 * users: throwaway `rea_proponente`, `rea_curador`, `site_editor` and `editor`
 * accounts log in over HTTP and exercise the real ACL, IsolatedSites'
 * isolation, the controllers and the workflow.
 *
 * DISPOSABLE DATA ONLY. It creates its own users, site permissions and REA
 * (copies of one complete REA's values, so the integrity gate can pass), and
 * deletes all of them at the end, in batch, even after a failure. It never
 * writes to the existing REA, users, curriculum or old graph: it only READS
 * one existing REA to copy its values. It sets `oermanager_rea_site_id` to the
 * REA site for the run and restores the previous value.
 *
 * Isolation stays ON for the throwaway users (limit_to_granted_sites = 1, the
 * production default). Searching the curriculum as a non-administrator
 * depends on TASK-069 (the curriculum belongs to no site): that check is
 * reported as PENDING, not as a failure.
 *
 * Usage (inside the container; the web server answers on localhost:8080):
 *   php /var/www/html/volume/modules/OERManager/test/container/rea-roles-check.php [rea_site_id=1] [other_site_id=2]
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\IntegrityChecker;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\ReaSite;
use OERManager\Service\Workflow\QueueCounter;

const BASE = 'http://localhost:8080';
const TAG = '[TASK-067 prueba]';

$reaSiteId = (int) ($argv[1] ?? 1);
$otherSiteId = (int) ($argv[2] ?? 2);

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$settings = $services->get('Omeka\Settings');
$auth = $services->get('Omeka\AuthenticationService');
$userSettings = $services->get('Omeka\Settings\User');

$checks = 0;
$failures = 0;
$pending = [];
function check(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $ok || '' === $detail ? '' : ' — ' . $detail);
}
function section(string $title): void
{
    echo "\n== $title ==\n";
}

/** Public REA count through the anonymous API (the 71 must stay 71). */
function publicReaCount(int $classId): int
{
    $headers = get_headers(BASE . '/api/items?resource_class_id=' . $classId . '&per_page=1', true);
    return (int) ($headers['Omeka-S-Total-Results'] ?? $headers['omeka-s-total-results'] ?? -1);
}

// ---------------------------------------------------------------- HTTP client
final class Browser
{
    private string $jar;

    public function __construct(public string $who)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 't067');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int, 1:string} status and body */
    public function request(string $method, string $path, array $data = [], bool $ajax = false, ?string $json = null): array
    {
        $ch = curl_init(BASE . $path);
        $headers = $ajax ? ['X-Requested-With: XMLHttpRequest'] : [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (null !== $json) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        } elseif ('GET' !== $method) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    }

    public function login(string $email, string $password): bool
    {
        [, $page] = $this->request('GET', '/login');
        preg_match('/name="loginform_csrf" value="([^"]+)"/', $page, $m);
        [$status] = $this->request('POST', '/login', [
            'loginform_csrf' => $m[1] ?? '', 'email' => $email, 'password' => $password, 'submit' => 'Log in',
        ]);
        [$adminStatus] = $this->request('GET', '/admin');
        return 302 === $status && 200 === $adminStatus;
    }

    public function status(string $path): int
    {
        return $this->request('GET', $path)[0];
    }

    /** JSON of an AJAX POST to an OERManager action. */
    public function post(string $action, array $data): array
    {
        [$status, $body] = $this->request('POST', '/admin/oer-manager/' . $action, $data, true);
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['http' => $status, 'raw' => substr(strip_tags($body), 0, 200)];
    }

    /** CSRF of the workflow forms, from the native item page or the panel. */
    public function csrf(string $path): string
    {
        [, $body] = $this->request('GET', $path);
        preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }
}

// ---------------------------------------------------------------- setup
section('Setup (disposable data)');
$admin = $em->getRepository(\Omeka\Entity\User::class)->findOneBy(['role' => 'global_admin', 'isActive' => true]);
$auth->getStorage()->write($admin);
$acl = $services->get('Omeka\Acl');
$lrClass = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent()[0];
$classId = (int) $lrClass->id();
$reaBefore = $api->search('items', ['resource_class_id' => $classId, 'limit' => 0])->getTotalResults();
$publicBefore = publicReaCount($classId);
printf("  REA in the catalogue: %d (public through the anonymous API: %d)\n", $reaBefore, $publicBefore);
$previousReaSite = $settings->get(ReaSite::SETTING);
$settings->set(ReaSite::SETTING, $reaSiteId);

$suffix = bin2hex(random_bytes(3));
$created = ['users' => [], 'items' => [], 'jobs' => [], 'keys' => []];
$browsers = [];
$roles = ['rea_proponente', 'rea_curador', 'site_editor', 'editor'];
$users = [];

try {
    $reaSite = $em->find(\Omeka\Entity\Site::class, $reaSiteId);
    foreach ($roles as $role) {
        $user = new \Omeka\Entity\User();
        $user->setEmail(sprintf('t067-%s-%s@example.invalid', str_replace('_', '-', $role), $suffix));
        $user->setName(sprintf('T067 %s %s', $role, $suffix));
        $user->setRole($role);
        $user->setIsActive(true);
        $password = bin2hex(random_bytes(12));
        $user->setPassword($password);
        $em->persist($user);
        $permission = new \Omeka\Entity\SitePermission();
        $permission->setSite($reaSite);
        $permission->setUser($user);
        $permission->setRole('editor');
        $em->persist($permission);
        $em->flush();
        $userSettings->setTargetId($user->getId());
        $userSettings->set('limit_to_granted_sites', 1);
        $users[$role] = ['entity' => $user, 'password' => $password, 'id' => (int) $user->getId()];
        $created['users'][] = (int) $user->getId();
    }
    check('throwaway users created with a permission on the REA site', 4 === count($created['users']));
    $adminId = (int) $admin->getId();
    /** Acts as a user (null = the administrator) with a fresh entity manager. */
    $as = static function (?string $role) use ($em, $auth, $users, $adminId): void {
        $em->clear();
        $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, null === $role ? $adminId : $users[$role]['id']));
    };
    /** Reads an item as the administrator, after the HTTP requests changed it. */
    $read = static function (int $id) use ($as, $api) {
        $as(null);
        return $api->read('items', $id)->getContent();
    };
    check('rea_proponente and rea_curador inherit from site_editor',
        $acl->inheritsRole('rea_proponente', 'site_editor', true) && $acl->inheritsRole('rea_curador', 'site_editor', true));
    check('the REA roles carry their labels in the user form',
        'Curador REA' === ($acl->getRoleLabels()['rea_curador'] ?? null)
        && 'Proponente REA' === ($acl->getRoleLabels()['rea_proponente'] ?? null));

    // A complete REA to copy (read only): integrity ok without the out-of-flow warning.
    $checker = $services->get(IntegrityChecker::class);
    $source = null;
    foreach ($api->search('items', ['resource_class_id' => $classId, 'site_id' => $reaSiteId, 'per_page' => 80])->getContent() as $candidate) {
        $codes = array_column($checker->check($candidate, true)->getIssues(), 'code');
        if ([] === array_diff($codes, ['published_out_of_flow'])) {
            $source = $candidate;
            break;
        }
    }
    check('a complete REA exists to copy (read only)', null !== $source);
    $copy = static function (string $title, bool $public, ?int $site, bool $values = true) use ($source, $classId, $users): array {
        $data = [
            'o:resource_class' => ['o:id' => $classId],
            'o:owner' => ['o:id' => $users['rea_proponente']['id']],
            'o:is_public' => $public,
            'o:site' => null === $site ? [] : [['o:id' => $site]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => TAG . ' ' . $title]],
        ];
        if ($source && $values) {
            if ($source->resourceTemplate()) {
                $data['o:resource_template'] = ['o:id' => $source->resourceTemplate()->id()];
            }
            foreach ($source->values() as $term => $info) {
                if ('dcterms:title' === $term || str_starts_with($term, 'curation:') || 'dcterms:provenance' === $term) {
                    continue;
                }
                foreach ($info['values'] as $value) {
                    $json = json_decode(json_encode($value), true);
                    $data[$term][] = array_intersect_key($json, array_flip(
                        ['type', 'property_id', '@value', '@id', 'o:label', 'value_resource_id', '@language', 'is_public']
                    ));
                }
            }
        }
        return $data;
    };
    $make = static function (array $data) use ($api, &$created): int {
        $id = (int) $api->create('items', $data)->getContent()->id();
        $created['items'][] = $id;
        return $id;
    };
    $titleProperty = $api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]->id();
    $fix = static function (array $data) use ($titleProperty): array {
        $data['dcterms:title'][0]['property_id'] = $titleProperty;
        return $data;
    };
    $r1 = $make($fix($copy('R1 flujo completo', false, $reaSiteId)));
    $r2 = $make($fix($copy('R2 descartar y reabrir', false, $reaSiteId, false)));
    $r3 = $make($fix($copy('R3 público fuera del flujo', true, $reaSiteId, false)));
    $r4 = $make($fix($copy('R4 sin sitio REA', false, null, false)));
    $other = $make($fix($copy('O en otro sitio', false, $otherSiteId, false)));
    check('throwaway REA created', 5 === count($created['items']), implode(',', $created['items']));
    printf("  R1=%d R2=%d R3=%d R4=%d O=%d\n", $r1, $r2, $r3, $r4, $other);

    // ------------------------------------------------------------ logins
    section('Logins (real non-administrator users over HTTP)');
    foreach ($roles as $role) {
        $browsers[$role] = new Browser($role);
        check("$role logs in", $browsers[$role]->login($users[$role]['entity']->getEmail(), $users[$role]['password']));
    }
    $p = $browsers['rea_proponente'];
    $c = $browsers['rea_curador'];
    // How this instance answers a denied admin page: the core's own denial of
    // the settings page to an editor is the baseline (GlobalLandingPage renders
    // denials as an HTTP 500 page in this container, not 403).
    $denied = $browsers['editor']->status('/admin/setting');
    printf("  core denial baseline (editor GET /admin/setting): HTTP %d\n", $denied);

    // ------------------------------------------------------------ ACL matrix over HTTP
    section('ACL over HTTP (allowed and denied)');
    $routes = [
        '/admin/oer-manager' => ['rea_curador'],
        '/admin/oer-manager/my-proposals' => ['rea_proponente'],
        '/admin/oer-manager/stats' => ['rea_curador'],
        '/admin/oer-manager/batch' => ['rea_curador'],
        '/admin/oer-manager/config' => [],
        '/admin/oer-manager/drawer-details?id=' . $r1 => ['rea_curador'],
    ];
    foreach ($routes as $path => $allowed) {
        foreach ($roles as $role) {
            $status = $browsers[$role]->status($path);
            $expected = in_array($role, $allowed, true) ? 200 : $denied;
            check(sprintf('%-15s GET %s → %d', $role, $path, $expected), $expected === $status, "got $status");
        }
    }
    // Navigation: OER Manager for the curator, Mis propuestas for the proposer.
    [, $curatorHome] = $c->request('GET', '/admin');
    [, $proposerHome] = $p->request('GET', '/admin');
    [, $editorHome] = $browsers['editor']->request('GET', '/admin');
    check('curator navigation shows «Cola (N)»', 1 === preg_match('/Cola \((\d+)\)/', $curatorHome, $navCount));
    check('proposer navigation shows «Mis propuestas» and no «OER Manager»',
        str_contains($proposerHome, 'Mis propuestas') && !str_contains($proposerHome, 'Cola ('));
    check('editor navigation shows neither', !str_contains($editorHome, 'Mis propuestas') && !str_contains($editorHome, 'Cola ('));

    // ------------------------------------------------------------ item page buttons
    section('Item page buttons follow each privilege');
    [, $pPage] = $p->request('GET', '/admin/item/' . $r1);
    [, $sePage] = $browsers['site_editor']->request('GET', '/admin/item/' . $r1);
    check('proposer sees «Proponer» on a draft', str_contains($pPage, 'Proponer para revisión'));
    check('proposer sees no curator button', !str_contains($pPage, 'Rechazar propuesta') && !str_contains($pPage, 'Descartar'));
    check('site_editor sees no workflow button', !str_contains($sePage, 'Proponer para revisión') && !str_contains($sePage, 'Descartar'));

    // ------------------------------------------------------------ the flow
    section('Flow: propose, reject, re-propose, confirm and publish, unpublish, discard, reopen');
    $pCsrf = $p->csrf('/admin/item/' . $r1);
    $cCsrf = $c->csrf('/admin/oer-manager/drawer-details?id=' . $r1);
    check('CSRF tokens read from the pages', '' !== $pCsrf && '' !== $cCsrf);
    $seCsrf = $pCsrf; // a token of another session must not work either
    $r = $browsers['site_editor']->post('propose', ['id' => $r1, 'csrf' => $seCsrf]);
    check('site_editor cannot propose (denied)', $denied === ($r['http'] ?? 0), json_encode($r));
    $r = $browsers['editor']->post('propose', ['id' => $r1, 'csrf' => $seCsrf]);
    check('editor cannot propose (denied)', $denied === ($r['http'] ?? 0), json_encode($r));
    $r = $p->post('propose', ['id' => $r1, 'csrf' => $pCsrf]);
    check('proposer proposes R1', true === ($r['updated'] ?? null) && 'Propuesto' === ($r['status'] ?? null), json_encode($r));
    $r = $p->post('reject-proposal', ['id' => $r1, 'csrf' => $pCsrf, 'reason' => 'x']);
    check('proposer cannot reject (denied)', $denied === ($r['http'] ?? 0), json_encode($r));
    $r = $p->post('discard', ['id' => $r1, 'csrf' => $pCsrf, 'reason' => 'x']);
    check('proposer cannot discard (denied)', $denied === ($r['http'] ?? 0), json_encode($r));
    $r = $c->post('reject-proposal', ['id' => $r1, 'csrf' => $cCsrf, 'reason' => '']);
    check('reject without a reason is refused', 'reason_required' === ($r['error'] ?? null), json_encode($r));
    $r = $c->post('reject-proposal', ['id' => $r1, 'csrf' => $cCsrf, 'reason' => 'Falta revisar la licencia ' . $suffix]);
    check('curator rejects R1 with a reason', 'Rechazado' === ($r['status'] ?? null), json_encode($r));
    [, $mine] = $p->request('GET', '/admin/oer-manager/my-proposals');
    check('«Mis propuestas» lists R1 with its reason',
        str_contains($mine, TAG . ' R1') && str_contains($mine, 'Falta revisar la licencia ' . $suffix));
    check('«Mis propuestas» lists only the proposer\'s REA', !str_contains($mine, (string) $source?->displayTitle()));
    $r = $p->post('propose', ['id' => $r1, 'csrf' => $pCsrf]);
    check('proposer proposes R1 again', 'Propuesto' === ($r['status'] ?? null), json_encode($r));
    $item = $read($r1);
    check('re-proposing cleared the old reason', null === $item->value('curation:note'));

    $pending[] = 'curriculum search as a non-administrator (search-terms/curriculum-tree): depends on TASK-069';
    [$searchStatus, $searchBody] = $c->request('GET', '/admin/oer-manager/search-terms?dimension=etapa&q=a');
    printf("  [PEND] curator search-terms answered %d with %d results (isolation on; TASK-069)\n",
        $searchStatus, count(json_decode($searchBody, true)['results'] ?? []));

    // Confirmar y publicar: a failing re-cataloguing publishes nothing.
    $r = $c->post('publish-proposal', ['id' => $r1, 'csrf' => $cCsrf, 'alignment' => ['lrmi:teaches' => [$r2]]]);
    $item = $read($r1);
    check('a failed re-cataloguing does not publish', false === ($r['updated'] ?? null) && !$item->isPublic()
        && 'Propuesto' === (string) $item->value('curation:status'), json_encode($r));
    $teaches = array_map(static fn ($v) => $v->valueResource()?->id(), $item->value('lrmi:teaches', ['all' => true]));
    $r = $c->post('publish-proposal', ['id' => $r1, 'csrf' => $cCsrf, 'alignment' => ['lrmi:teaches' => $teaches]]);
    $item = $read($r1);
    check('curator confirms and publishes R1', true === ($r['updated'] ?? null) && $item->isPublic()
        && 'Publicado' === (string) $item->value('curation:status'), json_encode($r));
    $statusValue = $item->value('curation:status');
    $annotation = $statusValue?->valueAnnotation();
    check('Publicado is annotated with who and when (ADR-0002)',
        $annotation && str_contains((string) $annotation->value('dcterms:contributor'), 'rea_curador')
        && 1 === preg_match('/\.\d{6}/', (string) $annotation->value('dcterms:modified')));
    check('the status value is private', $statusValue && !$statusValue->isPublic());

    // Anonymous public API: no status, no note, no curator name.
    $anon = (string) file_get_contents(BASE . '/api/items/' . $r1);
    check('anonymous API shows the published REA', str_contains($anon, TAG . ' R1'));
    check('anonymous API shows no curation:status, curation:note or contributor',
        !str_contains($anon, 'curation:status') && !str_contains($anon, 'curation:note')
        && !str_contains($anon, 'T067 rea_curador'));

    $r = $c->post('unpublish', ['id' => $r1, 'csrf' => $cCsrf, 'reason' => 'Licencia dudosa']);
    $item = $read($r1);
    check('curator unpublishes R1 (Propuesto, private, reason)', 'Propuesto' === (string) $item->value('curation:status')
        && !$item->isPublic() && 'Licencia dudosa' === (string) $item->value('curation:note'), json_encode($r));

    [, $before] = $c->request('GET', '/admin');
    preg_match('/Cola \((\d+)\)/', $before, $m1);
    $r = $c->post('discard', ['id' => $r2, 'csrf' => $cCsrf, 'reason' => 'Duplicado']);
    $item = $read($r2);
    check('curator discards R2 (Descartado, private)', 'Descartado' === (string) $item->value('curation:status')
        && !$item->isPublic(), json_encode($r));
    $r = $c->post('reopen', ['id' => $r2, 'csrf' => $cCsrf]);
    check('curator reopens R2 → Propuesto', 'Propuesto' === ($r['status'] ?? null), json_encode($r));
    [, $after] = $c->request('GET', '/admin');
    preg_match('/Cola \((\d+)\)/', $after, $m2);
    check('the counter counts the reopened REA (+1)', isset($m1[1], $m2[1]) && (int) $m2[1] === (int) $m1[1] + 1,
        ($m1[1] ?? '?') . ' → ' . ($m2[1] ?? '?'));
    $c->post('discard', ['id' => $r2, 'csrf' => $cCsrf, 'reason' => 'Duplicado']);
    [, $again] = $c->request('GET', '/admin');
    preg_match('/Cola \((\d+)\)/', $again, $m3);
    check('Descartado leaves the counter', isset($m3[1]) && (int) $m3[1] === (int) $m1[1], ($m3[1] ?? '?'));
    // The CLI has no route, so no isolation: restrict the same two COUNTs to
    // the curator's only site to compare with what isolation shows over HTTP.
    $as('rea_curador');
    $cliCount = $services->get(QueueCounter::class)->count();
    $statusRow = static fn (string $type, string $text): array => ['property' => 'curation:status', 'type' => $type,
        'text' => $text];
    $inSite = (int) $api->search('items', ['resource_class_id' => $classId, 'site_id' => $reaSiteId, 'limit' => 0,
        'property' => [$statusRow('eq', 'Propuesto')]])->getTotalResults()
        + (int) $api->search('items', ['resource_class_id' => $classId, 'site_id' => $reaSiteId, 'limit' => 0,
        'is_public' => true, 'property' => [$statusRow('neq', 'Propuesto'), $statusRow('neq', 'Publicado'),
        $statusRow('neq', 'Descartado')]])->getTotalResults();
    $as(null);
    printf("  counter: navigation %s; same COUNTs in CLI: whole catalogue %d, REA site only %d\n",
        $m3[1] ?? '?', $cliCount, $inSite);
    check('the navigation counter equals the queue in the curator\'s sites (isolation on)', (int) ($m3[1] ?? -1) === $inSite);

    // ------------------------------------------------------------ guard
    section('Hand-made status changes are rejected (security review)');
    // A disposable API key for the proposer (deleted with the user): the REST
    // API does not take the admin session for writes.
    $as('rea_proponente');
    $key = new \Omeka\Entity\ApiKey();
    $key->setId();
    $keyId = (string) $key->getId();
    $keyCredential = (string) $key->setCredential();
    $created['keys'][] = $keyId;
    $key->setLabel('TASK-067 throwaway');
    $key->setOwner($em->find(\Omeka\Entity\User::class, $users['rea_proponente']['id']));
    $em->persist($key);
    $em->flush();
    $q = '?key_identity=' . $keyId . '&key_credential=' . $keyCredential;
    [$apiStatus, $apiBody] = $p->request('GET', '/api/items/' . $r1 . $q);
    $json = json_decode($apiBody, true);
    check('proposer reads its own private status through the API (owner)', 'Propuesto' === ($json['curation:status'][0]['@value'] ?? null), "HTTP $apiStatus");
    $patchWith = static function (callable $edit) use ($p, $r1, $q, $apiBody): int {
        $json = json_decode($apiBody, true);
        $edit($json);
        return $p->request('PATCH', '/api/items/' . $r1 . $q, [], false, json_encode($json))[0];
    };
    $patch = $patchWith(static function (array &$j): void {
        $j['curation:status'][0]['@value'] = 'Publicado';
        $j['o:is_public'] = true;
    });
    $item = $read($r1);
    check('proposer: API PATCH writing «Publicado» is refused', 'Propuesto' === (string) $item->value('curation:status')
        && !$item->isPublic(), "HTTP $patch");
    $patch = $patchWith(static function (array &$j): void {
        $j['curation:status'][0]['is_public'] = true;
    });
    check('proposer: making the status public is refused', !$read($r1)->value('curation:status')->isPublic(), "HTTP $patch");
    $patch = $patchWith(static function (array &$j): void {
        $j['curation:status'][0]['@annotation']['dcterms:contributor'][0]['@value'] = 'Forjado';
    });
    $ann = $read($r1)->value('curation:status')->valueAnnotation();
    check('proposer: forging the contributor is refused',
        $ann && 'Forjado' !== (string) $ann->value('dcterms:contributor'), "HTTP $patch");
    $patch = $patchWith(static function (array &$j): void {
        $j['curation:status'][] = $j['curation:status'][0];
    });
    check('proposer: adding a second status is refused', 1 === count($read($r1)->value('curation:status', ['all' => true])), "HTTP $patch");
    $altProperty = $api->search('properties', ['term' => 'dcterms:alternative'])->getContent()[0]->id();
    $patch = $patchWith(static function (array &$j) use ($altProperty, $suffix): void {
        $j['dcterms:alternative'] = [['type' => 'literal', 'property_id' => $altProperty, '@value' => 'alt ' . $suffix]];
    });
    $item = $read($r1);
    check('proposer: resubmitting the status unchanged passes (native form case)', 200 === $patch
        && 'alt ' . $suffix === (string) $item->value('dcterms:alternative'), "HTTP $patch");
    $statusValue = $item->value('curation:status');
    $statusPropertyId = $statusValue->property()->id();
    $as('rea_proponente');
    try {
        $api->batchUpdate('items', [$r1], ['curation:status' => [['type' => 'literal',
            'property_id' => $statusPropertyId, '@value' => 'Publicado']]]);
        $batchRefused = false;
    } catch (\Omeka\Api\Exception\ValidationException $e) {
        $batchRefused = true;
    }
    check('proposer: batch update writing a status is refused', $batchRefused
        && 'Propuesto' === (string) $read($r1)->value('curation:status'));

    // ------------------------------------------------------------ integrity warnings
    section('Integrity warnings');
    $as('rea_curador');
    $checker = new IntegrityChecker(
        $services->get('OERManager\Service\Governance\VocabEntries\Licence'),
        $services->get(\OERManager\Service\Curriculum\CurriculumTerms::class),
        new ReaSite($api, $settings)
    );
    $codes = static function (int $id) use (&$checker, $api): array {
        return array_column($checker->check($api->read('items', $id)->getContent(), false)->getIssues(), 'code');
    };
    check('R3 (public, no status) is «Publicado fuera del flujo»', in_array('published_out_of_flow', $codes($r3), true));
    check('R4 (no site) is «REA sin sitio REA»', in_array('missing_rea_site', $codes($r4), true));
    check('R1 (in the REA site, private) has neither', [] === array_intersect(['published_out_of_flow', 'missing_rea_site'], $codes($r1)));
    $settings->set(ReaSite::SETTING, null);
    $checker = new IntegrityChecker(
        $services->get('OERManager\Service\Governance\VocabEntries\Licence'),
        null,
        new ReaSite($api, $settings)
    );
    check('without a REA site the rule is not evaluated', !in_array('missing_rea_site', $codes($r4), true));
    $settings->set(ReaSite::SETTING, $reaSiteId);
    $as(null);
    [, $drawer] = $c->request('GET', '/admin/oer-manager/drawer-details?id=' . $r3);
    check('the panel shows «Publicado fuera del flujo» to the curator', str_contains($drawer, 'Publicado fuera del flujo'));

    // ------------------------------------------------------------ isolation and jobs
    section('Isolation and Jobs');
    [, $browse] = $c->request('GET', '/admin/oer-manager?title=' . urlencode(TAG));
    check('isolation: the curator does not see O (other site) in the master view', !str_contains($browse, TAG . ' O en otro sitio'));
    check('isolation: the curator does not see R4 (no site)', !str_contains($browse, TAG . ' R4'));
    check('the curator sees R1 in the master view', str_contains($browse, TAG . ' R1'));
    // A Job by the curator, in this process (no route, so no isolation, as in a real Job): the native ACL decides each item.
    $as('rea_curador');
    $job = $services->get('Omeka\Job\Dispatcher')->dispatch(
        \OERManager\Job\GovernanceBatchJob::class,
        ['ids' => [$other, $r1], 'raw' => ['dcterms:rightsHolder' => ['T067 ' . $suffix]], 'mode' => 'fill',
            'contributor' => 'T067'],
        new \Omeka\Job\DispatchStrategy\Synchronous($services)
    );
    $created['jobs'][] = (int) $job->getId();
    $as(null);
    $state = $services->get(\OERManager\Service\Ai\ProposalStore::class)->read((int) $job->getId());
    $denied = array_column($state['tallies']['failed'] ?? $state['failed'] ?? [], 'code', 'id');
    $rights = static fn (int $id): string => (string) $read($id)->value('dcterms:rightsHolder');
    check('Job by rea_curador: the item outside its sites is denied by the native ACL',
        'denied' === ($denied[$other] ?? null) && '' === $rights($other), json_encode($state));
    check('Job by rea_curador: the item in the REA site is written', 'T067 ' . $suffix === $rights($r1), json_encode($state));
    // A real background Job (PhpCli) launched over HTTP by the curator: the REA roles exist in the Job process.
    [, $batchForm] = $c->request('GET', '/admin/oer-manager/batch');
    preg_match('/data-csrf="([^"]+)"/', $batchForm, $bm);
    $batchCsrf = $bm[1] ?? '';
    $preview = $c->request('POST', '/admin/oer-manager/batch/preview', ['ids' => [$r2], 'mode' => 'fill',
        'governance' => ['dcterms:rightsHolder' => 'T067 bg ' . $suffix], 'csrf' => $batchCsrf], true);
    $token = json_decode($preview[1], true)['token'] ?? '';
    $applied = json_decode($c->request('POST', '/admin/oer-manager/batch/apply', ['token' => $token,
        'csrf' => $batchCsrf], true)[1], true);
    $jobId = (int) ($applied['jobId'] ?? 0);
    if ($jobId) {
        $created['jobs'][] = $jobId;
    }
    $done = false;
    for ($i = 0; $i < 30 && $jobId && !$done; $i++) {
        sleep(1);
        $em->clear();
        $jobEntity = $em->find(\Omeka\Entity\Job::class, $jobId);
        $done = $jobEntity && in_array($jobEntity->getStatus(), ['completed', 'error', 'stopped'], true);
    }
    $as(null);
    check('a background Job launched by rea_curador completes and writes (roles exist in the Job process)',
        $done && 'T067 bg ' . $suffix === $rights($r2), 'job ' . $jobId . ' ' . substr($preview[1], 0, 120));
} catch (\Throwable $e) {
    check('harness ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    // ------------------------------------------------------------ cleanup
    section('Cleanup');
    $em->clear();
    $admin = $em->find(\Omeka\Entity\User::class, $admin->getId());
    $auth->getStorage()->write($admin);
    $settings->set(ReaSite::SETTING, $previousReaSite);
    if ($created['items']) {
        $api->batchDelete('items', $created['items']);
    }
    foreach ($created['jobs'] as $jobId) {
        if ($job = $em->find(\Omeka\Entity\Job::class, $jobId)) {
            $em->remove($job);
        }
    }
    foreach ($created['keys'] as $keyId) {
        if ($key = $em->find(\Omeka\Entity\ApiKey::class, $keyId)) {
            $em->remove($key);
        }
    }
    foreach ($created['users'] as $userId) {
        if ($user = $em->find(\Omeka\Entity\User::class, $userId)) {
            $em->remove($user);
        }
    }
    $em->flush();
    $leftItems = (int) $services->get('Omeka\Connection')
        ->fetchOne('SELECT COUNT(*) FROM value WHERE value LIKE ?', [TAG . '%']);
    $leftUsers = (int) $services->get('Omeka\Connection')
        ->fetchOne("SELECT COUNT(*) FROM user WHERE email LIKE 't067-%@example.invalid'");
    $leftPermissions = (int) $services->get('Omeka\Connection')
        ->fetchOne('SELECT COUNT(*) FROM site_permission WHERE user_id NOT IN (SELECT id FROM user)');
    check('no test item left', 0 === $leftItems);
    $leftKeys = (int) $services->get('Omeka\Connection')->fetchOne(
        'SELECT COUNT(*) FROM api_key WHERE id IN (?)',
        [implode(',', $created['keys']) ?: '-']
    );
    check('no test user, permission or API key left', 0 === $leftUsers && 0 === $leftPermissions && 0 === $leftKeys);
    check('REA site setting restored', $previousReaSite === $settings->get(ReaSite::SETTING));
    $reaAfter = $api->search('items', ['resource_class_id' => $classId, 'limit' => 0])->getTotalResults();
    $publicAfter = publicReaCount($classId);
    check(sprintf('REA unchanged: %d → %d (public API %d → %d)', $reaBefore, $reaAfter, $publicBefore, $publicAfter),
        $reaBefore === $reaAfter && $publicBefore === $publicAfter);
}

foreach ($pending as $note) {
    echo "  PENDING: $note\n";
}
printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
