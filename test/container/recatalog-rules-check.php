<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-035 container verification (spec 2026-11-18): the panel re-catalogues a
 * PRIVATE REA with the session (no `/api`), and the rule by state and role is
 * applied on the server, with REAL throwaway users logged in over HTTP:
 * `rea_proponente`, `rea_curador`, a second `rea_proponente` (not the owner)
 * and a throwaway `global_admin`.
 *
 * DISPOSABLE DATA ONLY. It creates its own users (emails `t035-…@example.invalid`),
 * site permissions and private REA (copies of one complete REA's values), and
 * deletes all of them at the end, in batch, even after a failure. It only READS
 * one existing REA to copy its values; it never writes to the existing REA,
 * users or the curriculum. Every re-cataloguing it applies is undone at once.
 *
 * Isolation: the throwaway users get `limit_to_granted_sites = 0` ON PURPOSE,
 * so the curriculum (in no site until TASK-069) can be searched by a
 * non-administrator. The curriculum search with isolation on is reported as
 * PENDING (TASK-069), not as a failure.
 *
 * Usage (inside the container; the web server answers on localhost:8080):
 *   php /var/www/html/volume/modules/OERManager/test/container/recatalog-rules-check.php [rea_site_id=1]
 */

chdir('/var/www/html');
require 'bootstrap.php';

use OERManager\Service\IntegrityChecker;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;
use OERManager\Service\Workflow\RecatalogRule;

const BASE = 'http://localhost:8080';
const TAG = '[TASK-035 prueba]';

$reaSiteId = (int) ($argv[1] ?? 1);

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$auth = $services->get('Omeka\AuthenticationService');
$userSettings = $services->get('Omeka\Settings\User');
$connection = $services->get('Omeka\Connection');

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
        $this->jar = tempnam(sys_get_temp_dir(), 't035');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int, 1:string} status and body */
    public function request(string $method, string $path, array $data = [], bool $ajax = false): array
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $ajax ? ['X-Requested-With: XMLHttpRequest'] : [],
        ]);
        if ('GET' !== $method) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
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

    /** JSON of an AJAX POST to an OERManager action (or the HTTP status when not JSON). */
    public function post(string $action, array $data): array
    {
        [$status, $body] = $this->request('POST', '/admin/oer-manager/' . $action, $data, true);
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['http' => $status, 'raw' => substr(strip_tags($body), 0, 160)];
    }

    /** The panel's `data-recatalog` for an item (TASK-035), decoded, or null. */
    public function panel(int $id): ?array
    {
        [, $body] = $this->request('GET', '/admin/oer-manager/drawer-details?id=' . $id);
        if (!preg_match('/class="oer-anchor-slot" data-recatalog="([^"]*)"/', $body, $m)) {
            return null;
        }
        $data = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Re-cataloguing CSRF, from the table configuration of «Mis propuestas»
     * (a proposer) or of the master view (a curator, who may own no REA).
     */
    public function recatalogCsrf(string $page = 'my-proposals'): string
    {
        [, $body] = $this->request('GET', '/admin/oer-manager' . ('' === $page ? '' : '/' . $page));
        preg_match('/data-recatalog-csrf="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    /** Workflow CSRF from the native item page (proposer) or the panel (curator). */
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
$adminId = (int) $admin->getId();
$lrClass = $api->search('resource_classes', ['term' => MasterViewQuery::LEARNING_RESOURCE_CLASS_TERM])->getContent()[0];
$classId = (int) $lrClass->id();
$reaBefore = $api->search('items', ['resource_class_id' => $classId, 'limit' => 0])->getTotalResults();
$publicBefore = publicReaCount($classId);
$usersBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM user');
printf("  REA in the catalogue: %d (public through the anonymous API: %d); users: %d\n",
    $reaBefore, $publicBefore, $usersBefore);

$suffix = bin2hex(random_bytes(3));
$created = ['users' => [], 'items' => []];
$accounts = [
    'proposer' => 'rea_proponente',
    'other' => 'rea_proponente',
    'curator' => 'rea_curador',
    'admin' => 'global_admin',
];
$users = [];
$browsers = [];

try {
    $reaSite = $em->find(\Omeka\Entity\Site::class, $reaSiteId);
    foreach ($accounts as $key => $role) {
        $user = new \Omeka\Entity\User();
        $user->setEmail(sprintf('t035-%s-%s@example.invalid', $key, $suffix));
        $user->setName(sprintf('T035 %s %s', $key, $suffix));
        $user->setRole($role);
        $user->setIsActive(true);
        $password = bin2hex(random_bytes(12));
        $user->setPassword($password);
        $em->persist($user);
        if ('global_admin' !== $role) {
            $permission = new \Omeka\Entity\SitePermission();
            $permission->setSite($reaSite);
            $permission->setUser($user);
            $permission->setRole('editor');
            $em->persist($permission);
        }
        $em->flush();
        // Explicit: off so the curriculum (in no site, TASK-069) can be searched.
        $userSettings->setTargetId($user->getId());
        $userSettings->set('limit_to_granted_sites', 0);
        $users[$key] = ['email' => $user->getEmail(), 'password' => $password, 'id' => (int) $user->getId()];
        $created['users'][] = (int) $user->getId();
    }
    check('throwaway users created (2 proposers, 1 curator, 1 global_admin)', 4 === count($created['users']));
    echo "  NOTE: limit_to_granted_sites = 0 for these users (curriculum search before TASK-069)\n";

    // A complete REA to copy (read only), with at least one alignment value.
    $checker = $services->get(IntegrityChecker::class);
    $source = null;
    $term = null;
    foreach ($api->search('items', ['resource_class_id' => $classId, 'site_id' => $reaSiteId, 'per_page' => 80])->getContent() as $candidate) {
        $codes = array_column($checker->check($candidate, true)->getIssues(), 'code');
        if ([] !== array_diff($codes, ['published_out_of_flow'])) {
            continue;
        }
        foreach (RecatalogService::ALIGNMENT_TERMS as $alignmentTerm) {
            if ($candidate->value($alignmentTerm)?->valueResource()) {
                $source = $candidate;
                $term = $alignmentTerm;
                break 2;
            }
        }
    }
    check('a complete, aligned REA exists to copy (read only)', null !== $source, (string) $term);
    $titleProperty = $api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]->id();
    $make = static function (string $title, int $ownerId) use ($api, $source, $classId, $reaSiteId, $titleProperty, &$created): int {
        $data = [
            'o:resource_class' => ['o:id' => $classId],
            'o:owner' => ['o:id' => $ownerId],
            'o:is_public' => false,
            'o:site' => [['o:id' => $reaSiteId]],
            'dcterms:title' => [['type' => 'literal', 'property_id' => $titleProperty, '@value' => TAG . ' ' . $title]],
        ];
        if ($source->resourceTemplate()) {
            $data['o:resource_template'] = ['o:id' => $source->resourceTemplate()->id()];
        }
        foreach ($source->values() as $sourceTerm => $info) {
            if ('dcterms:title' === $sourceTerm || str_starts_with($sourceTerm, 'curation:') || 'dcterms:provenance' === $sourceTerm) {
                continue;
            }
            foreach ($info['values'] as $value) {
                $json = json_decode(json_encode($value), true);
                $data[$sourceTerm][] = array_intersect_key($json, array_flip(
                    ['type', 'property_id', '@value', '@id', 'o:label', 'value_resource_id', '@language', 'is_public']
                ));
            }
        }
        $id = (int) $api->create('items', $data)->getContent()->id();
        $created['items'][] = $id;
        return $id;
    };
    $proposerId = $users['proposer']['id'];
    $draft = $make('A borrador', $proposerId);
    $rejected = $make('B rechazado', $proposerId);
    $proposed = $make('C propuesto', $proposerId);
    $published = $make('D publicado', $proposerId);
    $discarded = $make('E descartado', $proposerId);
    $foreign = $make('F ajeno', $users['other']['id']);
    $foreignPublic = $make('G ajeno publico', $users['other']['id']);
    $api->update('items', $foreignPublic, ['o:is_public' => true], [], ['isPartial' => true]);
    check('throwaway REA created (G public, the rest private)', 7 === count($created['items']), implode(',', $created['items']));
    printf("  A=%d B=%d C=%d D=%d E=%d F=%d G=%d (term %s)\n",
        $draft, $rejected, $proposed, $published, $discarded, $foreign, $foreignPublic, $term);

    /** Ids linked under $term, read as the administrator. */
    $linked = static function (int $id) use ($em, $auth, $adminId, $api, &$term): array {
        $em->clear();
        $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, $adminId));
        $item = $api->read('items', $id)->getContent();
        return array_values(array_filter(array_map(
            static fn ($v) => $v->valueResource()?->id(),
            $item->value($term, ['all' => true, 'default' => []])
        )));
    };
    $statusOf = static function (int $id) use ($em, $auth, $adminId, $api): ?string {
        $em->clear();
        $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, $adminId));
        $value = $api->read('items', $id)->getContent()->value('curation:status');
        return null === $value ? null : (string) $value->value();
    };

    // ------------------------------------------------------------ logins
    section('Logins over HTTP');
    foreach (array_keys($accounts) as $key) {
        $browsers[$key] = new Browser($key);
        check("$key ({$accounts[$key]}) logs in", $browsers[$key]->login($users[$key]['email'], $users[$key]['password']));
    }
    $p = $browsers['proposer'];
    $c = $browsers['curator'];
    $a = $browsers['admin'];
    $o = $browsers['other'];
    $csrf = [];
    foreach ($browsers as $key => $browser) {
        $csrf[$key] = $browser->recatalogCsrf(in_array($key, ['curator', 'admin'], true) ? '' : 'my-proposals');
    }
    check('re-cataloguing CSRF read from «Mis propuestas» (proposers) and the master view (curators)',
        4 === count(array_filter($csrf)));

    // ------------------------------------------------------------ states through the real flow
    section('States through the real workflow (HTTP)');
    $pFlow = $p->csrf('/admin/item/' . $draft);
    $cFlow = $c->csrf('/admin/oer-manager/drawer-details?id=' . $draft);
    foreach ([$rejected, $proposed, $published, $discarded] as $id) {
        $p->post('propose', ['id' => $id, 'csrf' => $pFlow]);
    }
    $c->post('reject-proposal', ['id' => $rejected, 'csrf' => $cFlow, 'reason' => 'T035']);
    $r = $c->post('publish-proposal', ['id' => $published, 'csrf' => $cFlow]);
    $c->post('discard', ['id' => $discarded, 'csrf' => $cFlow, 'reason' => 'T035']);
    check('states: A Borrador, B Rechazado, C Propuesto, D Publicado, E Descartado, F Borrador',
        null === $statusOf($draft) && 'Rechazado' === $statusOf($rejected) && 'Propuesto' === $statusOf($proposed)
        && 'Publicado' === $statusOf($published) && 'Descartado' === $statusOf($discarded) && null === $statusOf($foreign),
        json_encode($r));
    $em->clear();
    $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, $adminId));
    check('A, B, C, E and F stay private; D is public',
        !$api->read('items', $draft)->getContent()->isPublic() && !$api->read('items', $proposed)->getContent()->isPublic()
        && $api->read('items', $published)->getContent()->isPublic());

    // ------------------------------------------------------------ TASK-035: the panel without /api
    section('TASK-035: the panel pre-fills a private REA with the session');
    [$anonStatus] = (new Browser('anonymous'))->request('GET', '/api/items/' . $proposed);
    check('the old path fails: anonymous /api on a private REA → ' . $anonStatus, in_array($anonStatus, [403, 404], true));
    $panel = $c->panel($proposed);
    $expected = $linked($proposed);
    $prefill = array_column($panel['current'][$term] ?? [], 'id');
    check('curator panel on a private Propuesto REA offers the editor', true === ($panel['allowed'] ?? null), json_encode($panel));
    check('its pre-fill is the item\'s values (' . $term . ')', $expected === $prefill && [] !== $prefill,
        json_encode([$expected, $prefill]));
    [, $master] = $c->request('GET', '/admin/oer-manager?title=' . urlencode(TAG));
    check('the master view rows no longer carry data-api-url', str_contains($master, TAG) && !str_contains($master, 'data-api-url'));

    // ------------------------------------------------------------ apply and undo by state and role
    section('Re-catalogue (preview, apply, undo) by state and role');
    /**
     * Previews, applies (drops the last linked value of $term) and undoes as
     * $who on $id; asserts the write and the restoration.
     */
    $roundTrip = static function (string $label, Browser $who, string $token, int $id) use ($linked, &$term): void {
        $before = $linked($id);
        $after = array_slice($before, 0, -1);
        $alignment = [$term => $after ?: ['']];
        $preview = $who->post('recatalog-preview', ['id' => $id, 'alignment' => $alignment]);
        check("$label: preview", is_array($preview['diff'] ?? null) && !isset($preview['error']), json_encode($preview));
        $apply = $who->post('recatalog-apply', ['id' => $id, 'alignment' => $alignment, 'csrf' => $token]);
        check("$label: apply writes", true === ($apply['updated'] ?? null) && $after === $linked($id), json_encode($apply));
        $event = $who->post('recatalog-last-event', ['id' => $id]);
        check("$label: last event offered for undo", is_array($event['event'] ?? null), json_encode($event));
        $undo = $who->post('recatalog-undo', ['id' => $id, 'csrf' => $token]);
        check("$label: undo restores", true === ($undo['updated'] ?? null) && $before === $linked($id), json_encode($undo));
    };
    $refused = static function (string $label, Browser $who, string $token, int $id, string $code) use ($linked, &$term): void {
        $before = $linked($id);
        $alignment = [$term => array_slice($before, 0, -1) ?: ['']];
        foreach (['recatalog-preview', 'recatalog-apply', 'recatalog-undo'] as $action) {
            $r = $who->post($action, ['id' => $id, 'alignment' => $alignment, 'csrf' => $token]);
            check("$label: $action refused ($code)", $code === ($r['error'] ?? null)
                && RecatalogRule::message($code) === ($r['message'] ?? null), json_encode($r));
        }
        $event = $who->post('recatalog-last-event', ['id' => $id]);
        check("$label: no undo offered", array_key_exists('event', $event) && null === $event['event'], json_encode($event));
        check("$label: nothing written", $before === $linked($id));
        $panel = $who->panel($id);
        check("$label: the panel does not offer the editor and says why",
            false === ($panel['allowed'] ?? null) && '' !== ($panel['reason'] ?? ''), json_encode($panel));
    };

    /**
     * Security review: someone else's REA is answered to a proposer like a
     * missing one — no panel, no history, no write, existence not revealed.
     */
    $hidden = static function (string $label, Browser $who, string $token, int $id) use ($linked, &$term): void {
        $before = $linked($id);
        $alignment = [$term => array_slice($before, 0, -1) ?: ['']];
        foreach (['recatalog-preview', 'recatalog-apply', 'recatalog-undo'] as $action) {
            $r = $who->post($action, ['id' => $id, 'alignment' => $alignment, 'csrf' => $token]);
            check("$label: $action answers not_found", 'not_found' === ($r['error'] ?? null), json_encode($r));
        }
        $event = $who->post('recatalog-last-event', ['id' => $id]);
        check("$label: no event", array_key_exists('event', $event) && null === $event['event'], json_encode($event));
        [$status, $body] = $who->request('GET', '/admin/oer-manager/drawer-details?id=' . $id);
        check("$label: the panel answers as for a missing REA (no title, no data)",
            200 === $status && !str_contains($body, TAG) && !str_contains($body, 'data-recatalog=')
            && str_contains($body, 'oer-drawer-empty'), substr(strip_tags($body), 0, 120));
        [, $history] = $who->request('GET', '/admin/oer-manager/drawer-history?id=' . $id);
        check("$label: the history is null", ['history' => null] === json_decode($history, true), substr($history, 0, 120));
        [, $missing] = $who->request('GET', '/admin/oer-manager/drawer-details?id=999999999');
        check("$label: same panel as a missing id",
            preg_replace('/\s+/', '', strip_tags($missing)) === preg_replace('/\s+/', '', strip_tags($body)));
        check("$label: nothing written", $before === $linked($id));
    };

    $roundTrip('proposer, own private Borrador', $p, $csrf['proposer'], $draft);
    $roundTrip('proposer, own private Rechazado', $p, $csrf['proposer'], $rejected);
    $roundTrip('curator, private Borrador', $c, $csrf['curator'], $draft);
    $roundTrip('curator, private Propuesto', $c, $csrf['curator'], $proposed);
    $roundTrip('curator, Publicado (directly)', $c, $csrf['curator'], $published);
    $roundTrip('global_admin, private Propuesto', $a, $csrf['admin'], $proposed);
    $refused('proposer, own Propuesto', $p, $csrf['proposer'], $proposed, RecatalogRule::STATUS);
    $refused('proposer, own Publicado', $p, $csrf['proposer'], $published, RecatalogRule::STATUS);
    $hidden('proposer, someone else\'s private REA', $p, $csrf['proposer'], $foreign);
    $hidden('proposer, someone else\'s public REA', $p, $csrf['proposer'], $foreignPublic);
    $panel = $c->panel($foreignPublic);
    check('the curator still reads someone else\'s REA', is_array($panel) && true === ($panel['allowed'] ?? null), json_encode($panel));
    $refused('proposer, own Descartado', $p, $csrf['proposer'], $discarded, RecatalogRule::DISCARDED);
    $refused('curator, Descartado', $c, $csrf['curator'], $discarded, RecatalogRule::DISCARDED);
    $refused('global_admin, Descartado', $a, $csrf['admin'], $discarded, RecatalogRule::DISCARDED);
    $roundTrip('the other proposer, their own Borrador', $o, $csrf['other'], $foreign);
    check('the workflow states are unchanged by re-cataloguing',
        null === $statusOf($draft) && 'Rechazado' === $statusOf($rejected) && 'Propuesto' === $statusOf($proposed)
        && 'Publicado' === $statusOf($published) && 'Descartado' === $statusOf($discarded));

    // A governance event is the curator's to undo, never the proposer's.
    $governance = $c->post('governance-apply', ['id' => $draft, 'csrf' => $csrf['curator'],
        'governance' => ['dcterms:rightsHolder' => 'T035 ' . $suffix]]);
    $r = $p->post('recatalog-undo', ['id' => $draft, 'csrf' => $csrf['proposer']]);
    check('proposer cannot undo the curator\'s licence/authorship change',
        true === ($governance['updated'] ?? null) && 'governance_undo' === ($r['error'] ?? null), json_encode([$governance, $r]));
    $r = $c->post('recatalog-undo', ['id' => $draft, 'csrf' => $csrf['curator']]);
    check('the curator can', true === ($r['updated'] ?? null), json_encode($r));

    // ------------------------------------------------------------ proposer access
    section('Proposer access: «Mis propuestas» and nothing else');
    [, $mine] = $p->request('GET', '/admin/oer-manager/my-proposals');
    // Omeka escapes script URLs as HTML entities: compare decoded.
    $mine = html_entity_decode($mine, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $missing = array_filter(
        ['oer-open-drawer', 'Catalogar', 'OERManager/asset/js/main.js', 'id="oer-detail-sidebar"', 'data-ai-enabled="0"', 'data-recatalog-csrf'],
        static fn (string $needle): bool => !str_contains($mine, $needle)
    );
    check('«Mis propuestas» opens the panel («Catalogar» + main.js + sidebar)', [] === $missing, implode(', ', $missing));
    $denied = $p->request('GET', '/admin/oer-manager')[0];
    check('proposer is denied the master view (HTTP ' . $denied . ')', 200 !== $denied);
    foreach (['ai-propose', 'governance-apply', 'set-visibility', 'publish-proposal', 'reject-proposal'] as $action) {
        $r = $p->post($action, ['id' => $draft, 'csrf' => $csrf['proposer']]);
        check("proposer is denied $action", isset($r['http']) && 200 !== $r['http'], json_encode($r));
    }
    foreach (['/admin/oer-manager/stats', '/admin/oer-manager/batch', '/admin/oer-manager/search'] as $path) {
        $status = $p->request('GET', $path)[0];
        check("proposer is denied GET $path (HTTP $status)", 200 !== $status);
    }
    [$termsStatus, $termsBody] = $p->request('GET', '/admin/oer-manager/search-terms?dimension=etapa&q=a');
    $etapas = json_decode($termsBody, true)['results'] ?? null;
    check('proposer searches the curriculum (limit_to_granted_sites = 0)', 200 === $termsStatus && is_array($etapas) && [] !== $etapas,
        $termsStatus . ' ' . substr($termsBody, 0, 120));
    $about = $linked($draft);
    $subjects = [];
    $em->clear();
    $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, $adminId));
    foreach ($api->read('items', $draft)->getContent()->value('schema:about', ['all' => true, 'default' => []]) as $v) {
        if ($v->valueResource()) {
            $subjects[] = $v->valueResource()->id();
        }
    }
    [$treeStatus, $treeBody] = $p->request('GET', '/admin/oer-manager/curriculum-tree?' . http_build_query(['about' => $subjects]));
    check('proposer loads the knowledge/criteria tree', 200 === $treeStatus && is_array(json_decode($treeBody, true)['trees'] ?? null),
        $treeStatus . ' ' . substr($treeBody, 0, 120));
    $pending[] = 'curriculum search by a non-administrator WITH isolation on (limit_to_granted_sites = 1): TASK-069';
} catch (\Throwable $e) {
    check('harness ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    // ------------------------------------------------------------ cleanup
    section('Cleanup');
    $em->clear();
    $auth->getStorage()->write($em->find(\Omeka\Entity\User::class, $adminId));
    if ($created['items']) {
        $api->batchDelete('items', $created['items']);
    }
    foreach ($created['users'] as $userId) {
        if ($user = $em->find(\Omeka\Entity\User::class, $userId)) {
            $em->remove($user);
        }
    }
    $em->flush();
    $leftItems = (int) $connection->fetchOne('SELECT COUNT(*) FROM value WHERE value LIKE ?', [TAG . '%']);
    $leftUsers = (int) $connection->fetchOne("SELECT COUNT(*) FROM user WHERE email LIKE 't0%@example.invalid'");
    $leftPermissions = (int) $connection->fetchOne('SELECT COUNT(*) FROM site_permission WHERE user_id NOT IN (SELECT id FROM user)');
    $leftSettings = (int) $connection->fetchOne('SELECT COUNT(*) FROM user_setting WHERE user_id NOT IN (SELECT id FROM user)');
    check('no test item left', 0 === $leftItems);
    check('no test user (t0xx), permission or user setting left', 0 === $leftUsers && 0 === $leftPermissions && 0 === $leftSettings,
        "$leftUsers/$leftPermissions/$leftSettings");
    $usersAfter = (int) $connection->fetchOne('SELECT COUNT(*) FROM user');
    $reaAfter = $api->search('items', ['resource_class_id' => $classId, 'limit' => 0])->getTotalResults();
    $publicAfter = publicReaCount($classId);
    check(sprintf('REA unchanged: %d → %d (public API %d → %d); users %d → %d',
        $reaBefore, $reaAfter, $publicBefore, $publicAfter, $usersBefore, $usersAfter),
        $reaBefore === $reaAfter && $publicBefore === $publicAfter && $usersBefore === $usersAfter);
}

foreach ($pending as $note) {
    echo "  PENDING: $note\n";
}
printf("\n%d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
