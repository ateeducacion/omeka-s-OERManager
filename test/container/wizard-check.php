<?php
// CLI only: these harnesses bootstrap Omeka and this one writes the catalogue,
// so it must never run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-077: the wizard to add a REA, end to end over HTTP, as REAL
 * NON-ADMINISTRATOR users. Throwaway `rea_proponente` (two) and `rea_curador`
 * accounts log in and use the wizard's endpoints: the upload stored by token
 * and ingested at `save` (the plan's spike), several media in order, a link
 * as a media (a web page refused by Omeka and named), a draft with only a title, the
 * template set only once the REA meets it, resuming, someone else's REA
 * refused, an expired upload, the minimum rule to propose and the duplicate
 * warning filtered by the API's ACL.
 *
 * WRITES the catalogue: run on a development or disposable catalogue only.
 * Everything it creates (users, items, the template it may create, uploads)
 * is deleted at the end in one batch, even after a failure, and the settings
 * it touches are restored.
 *
 * Without a configured REA template it creates a disposable one for the run.
 * The anchoring and «Guardar y proponer» need a curriculum the users can read
 * (ADR-0022, TASK-069): without one, those checks are reported as PENDING.
 *
 * Usage (inside the container; the web server answers on localhost:8080):
 *   php /var/www/html/volume/modules/OERManager/test/container/wizard-check.php --write
 *
 * Exit 1 if a check fails, 2 on wrong usage.
 */

use OERManager\Service\GovernanceSettings;
use OERManager\Service\Workflow\WorkflowStatus;

if (!in_array('--write', array_slice($argv, 1), true)) {
    fwrite(STDERR, "usage: wizard-check.php --write  (writes the catalogue; development or disposable only)\n");
    exit(2);
}

chdir('/var/www/html');
require 'bootstrap.php';

const BASE = 'http://localhost:8080';

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$settings = $services->get('Omeka\Settings');
$auth = $services->get('Omeka\AuthenticationService');

$failures = 0;
$pending = [];
$check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . ($ok || '' === $detail ? '' : ': ' . $detail) . "\n";
    $failures += $ok ? 0 : 1;
};
$section = static fn (string $title) => print("\n== $title\n");

final class WizardBrowser
{
    private string $jar;
    public string $csrf = '';

    public function __construct()
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 't077');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int,1:string} */
    public function request(string $method, string $path, $data = null): array
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
        ]);
        if (null !== $data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) && !self::hasFile($data) ? http_build_query($data) : $data);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    }

    private static function hasFile(array $data): bool
    {
        foreach ($data as $value) {
            if ($value instanceof \CURLFile) {
                return true;
            }
        }
        return false;
    }

    public function login(string $email, string $password): bool
    {
        [, $page] = $this->request('GET', '/login');
        preg_match('/name="loginform_csrf" value="([^"]+)"/', $page, $m);
        [$status] = $this->request('POST', '/login', [
            'loginform_csrf' => $m[1] ?? '', 'email' => $email, 'password' => $password, 'submit' => 'Log in',
        ]);
        return 302 === $status;
    }

    /** The wizard page's configuration (and its CSRF), or null with the page's status. */
    public function page(string $query = ''): array
    {
        [$status, $body] = $this->request('GET', '/admin/oer-manager/wizard' . $query);
        $config = null;
        if (preg_match('#<script type="application/json" id="oer-wizard-config">(.*?)</script>#s', $body, $m)) {
            $config = json_decode($m[1], true);
            $this->csrf = (string) ($config['csrf'] ?? '');
        }
        return [$status, $config, $body];
    }

    public function json(string $method, string $action, $data = null): array
    {
        if (is_array($data)) {
            $data['csrf'] = $this->csrf;
        }
        [$status, $body] = $this->request($method, '/admin/oer-manager/wizard/' . $action, $data);
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['http' => $status, 'raw' => substr(strip_tags($body), 0, 200)];
    }

    public function save(array $state): array
    {
        return $this->json('POST', 'save', ['state' => json_encode($state)]);
    }
}

$asAdmin = static function () use ($em, $auth): void {
    $em->clear();
    $admin = $em->getRepository(\Omeka\Entity\User::class)->findOneBy(['role' => 'global_admin', 'isActive' => true]);
    $auth->getStorage()->write($admin);
};
$asAdmin();

$suffix = bin2hex(random_bytes(3));
$marker = '[TASK-077 prueba ' . $suffix . ']';
$created = ['users' => [], 'template' => null];
$previous = [GovernanceSettings::REA_TEMPLATE_ID => $settings->get(GovernanceSettings::REA_TEMPLATE_ID)];
$tmpFiles = [];

$property = static function (string $term) use ($api): ?int {
    $found = $api->search('properties', ['term' => $term])->getContent();
    return $found ? (int) $found[0]->id() : null;
};
$markerItems = static function () use ($api, $marker, $asAdmin): array {
    $asAdmin();
    return array_map('intval', array_keys((array) $api->search('items', [
        'property' => [['property' => 'dcterms:title', 'type' => 'in', 'text' => $marker]],
    ], ['returnScalar' => 'id'])->getContent()));
};

try {
    $section('Setup (disposable data)');
    $templateId = GovernanceSettings::parseId($previous[GovernanceSettings::REA_TEMPLATE_ID]);
    $template = null;
    if (null !== $templateId) {
        try {
            $template = $api->read('resource_templates', $templateId)->getContent();
        } catch (\Exception $e) {
            $template = null;
        }
    }
    $templateRequired = [];
    if (null === $template) {
        $class = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent()[0];
        $rows = [];
        foreach (['dcterms:title' => true, 'dcterms:description' => false, 'dcterms:creator' => true,
            'dcterms:license' => false] as $term => $required) {
            $rows[] = ['o:property' => ['o:id' => $property($term)], 'o:is_required' => $required, 'o:data_type' => []];
        }
        $template = $api->create('resource_templates', [
            'o:label' => 'TASK-077 wizard-check ' . $suffix . ' (disposable)',
            'o:resource_class' => ['o:id' => (int) $class->id()],
            'o:resource_template_property' => $rows,
        ])->getContent();
        $created['template'] = (int) $template->id();
        $settings->set(GovernanceSettings::REA_TEMPLATE_ID, (int) $template->id());
        echo "  disposable REA template #{$template->id()} (title and authorship required)\n";
    } else {
        echo "  REA template #{$template->id()} of the catalogue\n";
    }
    foreach ($template->resourceTemplateProperties() as $row) {
        if ($row->isRequired()) {
            $templateRequired[] = (string) $row->property()->term();
        }
    }
    $elpxAllowed = $settings->get('disable_file_validation')
        || in_array('elpx', (array) $settings->get('extension_whitelist'), true);
    echo '  Omeka allows the .elpx extension: ' . ($elpxAllowed ? 'yes' : 'NO (the deployment must allow it)') . "\n";

    $users = [];
    foreach (['a' => 'rea_proponente', 'b' => 'rea_proponente', 'c' => 'rea_curador'] as $key => $role) {
        $user = new \Omeka\Entity\User();
        $user->setEmail(sprintf('t077-%s-%s@example.invalid', $key, $suffix));
        $user->setName(sprintf('T077 %s %s', $key, $suffix));
        $user->setRole($role);
        $user->setIsActive(true);
        $password = bin2hex(random_bytes(12));
        $user->setPassword($password);
        $em->persist($user);
        $em->flush();
        $created['users'][] = (int) $user->getId();
        $browser = new WizardBrowser();
        $check($browser->login($user->getEmail(), $password), "$role ($key) logs in");
        $users[$key] = ['browser' => $browser, 'id' => (int) $user->getId()];
    }
    [$a, $b, $c] = [$users['a']['browser'], $users['b']['browser'], $users['c']['browser']];

    $section('Access');
    [$status, $config] = $a->page();
    $check(200 === $status && is_array($config), 'the proposer opens the wizard', "HTTP $status");
    [$status, $cconfig] = $c->page();
    $check(200 === $status && is_array($cconfig), 'the curator opens the wizard', "HTTP $status");
    [$status] = $c->request('GET', '/admin/oer-manager/my-proposals');
    $check(200 === $status, 'the curator opens «Mis propuestas»', "HTTP $status");
    $terms = array_column($config['schema']['data'] ?? [], 'term');
    $check(in_array('dcterms:title', $terms, true) && !in_array('curation:status', $terms, true),
        'the schema shows the template data and hides the machine terms', implode(',', $terms));

    $section('Step ①: upload (stored by token, metadata read)');
    $zip = (string) tempnam(sys_get_temp_dir(), 't077zip');
    $tmpFiles[] = $zip;
    $za = new \ZipArchive();
    $za->open($zip, \ZipArchive::OVERWRITE);
    $za->addFromString('content.xml', '<?xml version="1.0"?><ode xmlns="http://www.intef.es/xsd/ode"><odeProperties>'
        . '<odeProperty><key>pp_title</key><value>' . htmlspecialchars($marker) . ' Fracciones</value></odeProperty>'
        . '<odeProperty><key>pp_author</key><value>Departamento de Matemáticas</value></odeProperty>'
        . '</odeProperties><odeNavStructures/></ode>');
    $za->addFromString('index.html', '<html><body>Fracciones</body></html>');
    $za->close();
    $upload = $a->json('POST', 'upload', ['file' => new \CURLFile($zip, 'application/zip', 'fracciones.zip')]);
    $check(!empty($upload['ok']) && 1 === preg_match('/^[0-9a-f]{32}$/', (string) ($upload['token'] ?? '')),
        'the package is stored under a token', json_encode($upload));
    $check(($upload['metadata']['title'] ?? '') === $marker . ' Fracciones'
        && ($upload['metadata']['author'] ?? '') === 'Departamento de Matemáticas', 'its title and authorship are suggested');
    $html = (string) tempnam(sys_get_temp_dir(), 't077html');
    $tmpFiles[] = $html;
    file_put_contents($html, '<html><body><script>alert(1)</script></body></html>');
    $refused = $a->json('POST', 'upload', ['file' => new \CURLFile($html, 'application/pdf', 'trampa.pdf')]);
    $check('type' === ($refused['error'] ?? ''), 'an HTML file renamed .pdf is refused by its content', json_encode($refused));
    $noCsrf = (new WizardBrowser())->json('POST', 'upload', ['file' => new \CURLFile($zip, 'application/zip', 'x.zip')]);
    $check(!($noCsrf['ok'] ?? false), 'an upload without a session and CSRF is refused');

    $section('Save a draft with only a title');
    $title = $marker . ' Fracciones';
    $draft = $a->save([
        'values' => ['dcterms:title' => [$title]],
        'resources' => [['key' => 'r1', 'kind' => 'upload', 'token' => $upload['token'] ?? '']],
        'alignment' => [],
        'propose' => false,
    ]);
    $check(!empty($draft['ok']) && ($draft['id'] ?? 0) > 0, 'the draft is created', json_encode($draft));
    $id = (int) ($draft['id'] ?? 0);
    $asAdmin();
    $item = $id ? $api->read('items', $id)->getContent() : null;
    $check(null !== $item && !$item->isPublic(), 'it is private');
    $check(null !== $item && 'lrmi:LearningResource' === $item->resourceClass()?->term(), 'it is a lrmi:LearningResource');
    $check(null !== $item && (int) $item->owner()?->id() === $users['a']['id'], 'the proposer owns it');
    $media = $item ? $item->media() : [];
    $check(1 === count($media) && $media[0]->hasOriginal() && 'fracciones.zip' === $media[0]->source(),
        'its media was ingested from the stored upload, original kept');
    $check(null !== $item && null === $item->value(WorkflowStatus::STATUS_TERM), 'no workflow status is written (Borrador)');
    $meets = !array_diff($templateRequired, ['dcterms:title']);
    $check(null !== $item && ($meets ? null !== $item->resourceTemplate() : null === $item->resourceTemplate()),
        $meets ? 'the template is set: the draft meets it' : 'no template yet: a required field is still empty');
    $reused = $a->save(['values' => ['dcterms:title' => [$title . ' bis']],
        'resources' => [['key' => 'r1', 'kind' => 'upload', 'token' => $upload['token'] ?? '']]]);
    $check('upload_expired' === ($reused['error'] ?? '') && 'r1' === ($reused['key'] ?? ''),
        'an ingested upload cannot be used twice, and the answer names it', json_encode($reused));

    $section('Resume');
    [$status, $resumed] = $a->page('?id=' . $id);
    $check(200 === $status && ($resumed['item']['id'] ?? 0) === $id, 'the proposer resumes their draft');
    $check(($resumed['item']['media'][0]['name'] ?? '') === 'fracciones.zip', 'with its media');
    $keep = [['key' => 'm', 'kind' => 'media', 'id' => (int) ($resumed['item']['media'][0]['id'] ?? 0)]];
    $values = ['dcterms:title' => [$title]];
    foreach ($templateRequired as $term) {
        $values[$term] ??= ['Departamento de Matemáticas'];
    }
    $updated = $a->save(['id' => $id, 'values' => $values + ['dcterms:creator' => ['Departamento de Matemáticas']],
        'resources' => $keep, 'alignment' => [], 'propose' => false]);
    $check(!empty($updated['ok']) && $id === ($updated['id'] ?? 0), 'the resumed draft is updated, not duplicated', json_encode($updated));
    $asAdmin();
    $item = $api->read('items', $id)->getContent();
    $check(null !== $item->resourceTemplate(), 'once it meets the template, the template is set');
    $check(1 === count($item->media()), 'keeping the media touches no media');
    $check((string) $item->value('dcterms:creator') === 'Departamento de Matemáticas', 'the authorship went through governance');
    $check(1 <= count($item->value('dcterms:provenance', ['all' => true, 'default' => []])), 'a curation event was recorded');

    $section('Who may resume');
    [, $other] = $b->page('?id=' . $id);
    $check(null === $other, 'another proposer is answered as for a missing REA');
    $b->page(); // a valid session and CSRF, so the refusal below is the access rule's
    $check('not_found' === ($b->save(['id' => $id, 'values' => ['dcterms:title' => ['x']]])['error'] ?? ''),
        'and cannot save over it');
    [, $byCurator] = $c->page('?id=' . $id);
    $check(($byCurator['item']['id'] ?? 0) === $id, 'a curator resumes the proposer’s draft');

    $section('Propose (minimum rule)');
    $proposal = $a->save(['id' => $id, 'values' => $values, 'resources' => $keep, 'alignment' => [], 'propose' => true]);
    $check('incomplete' === ($proposal['error'] ?? '') && in_array('course', $proposal['missing'] ?? [], true)
        && in_array('licence', $proposal['missing'] ?? [], true), 'without licence, course and subject it is not proposed',
        json_encode($proposal));
    $asAdmin();
    $check(null === $api->read('items', $id)->getContent()->value(WorkflowStatus::STATUS_TERM), 'and stays a Borrador');
    $pending[] = 'Anchoring and «Guardar y proponer» end to end: need a curriculum readable by non-administrators'
        . ' (development catalogue with EducationalCurriculum; TASK-069 for isolated users).';

    $section('Several files, in order (owner\'s review of PR #100)');
    $uno = $a->json('POST', 'upload', ['file' => new \CURLFile($zip, 'application/zip', 'uno.zip')]);
    $dos = $a->json('POST', 'upload', ['file' => new \CURLFile($zip, 'application/zip', 'dos.zip')]);
    $many = $a->save(['values' => ['dcterms:title' => [$marker . ' Varios']], 'alignment' => [], 'resources' => [
        ['key' => 'd', 'kind' => 'upload', 'token' => $dos['token'] ?? ''],
        ['key' => 'u', 'kind' => 'upload', 'token' => $uno['token'] ?? ''],
    ]]);
    $check(!empty($many['ok']), 'a draft with two files is saved', json_encode($many));
    $asAdmin();
    $manyItem = !empty($many['id']) ? $api->read('items', (int) $many['id'])->getContent() : null;
    $sources = $manyItem ? array_map(static fn ($m) => (string) $m->source(), $manyItem->media()) : [];
    $check(['dos.zip', 'uno.zip'] === $sources, 'its media keep the order of step ①: the first is the main one', json_encode($sources));
    [, $manyPage] = $a->page('?id=' . (int) ($many['id'] ?? 0));
    $ids = array_column($manyPage['item']['media'] ?? [], 'id');
    $reordered = $a->save(['id' => (int) ($many['id'] ?? 0), 'values' => ['dcterms:title' => [$marker . ' Varios']],
        'alignment' => [], 'resources' => [['key' => 'b', 'kind' => 'media', 'id' => (int) ($ids[1] ?? 0)]]]);
    $check(!empty($reordered['ok']), 'resuming, one is kept and the other removed', json_encode($reordered));
    $asAdmin();
    $after = $manyItem ? $api->read('items', (int) $many['id'])->getContent()->media() : [];
    $check(1 === count($after) && 'uno.zip' === $after[0]->source(), 'only the kept media remains');

    $section('A link is a media; a web page Omeka refuses is named, and the draft stays');
    $linked = $a->save(['values' => ['dcterms:title' => [$marker . ' Enlace']], 'alignment' => [], 'resources' => [
        ['key' => 'l1', 'kind' => 'url', 'url' => 'https://www3.gobiernodecanarias.org/medusa/ecoescuela/recursosdigitales/'],
    ]]);
    $check('media' === ($linked['error'] ?? '') && 'l1' === ($linked['failed'][0]['key'] ?? '') && ($linked['id'] ?? 0) > 0,
        'Omeka\'s url ingester refuses a web page (text/html not allowed): the answer names the link', json_encode($linked));
    $asAdmin();
    $linkItem = !empty($linked['id']) ? $api->read('items', (int) $linked['id'])->getContent() : null;
    $check(null !== $linkItem && [] === $linkItem->media() && null === $linkItem->value('schema:url'),
        'the draft is kept without that media, and nothing is written to schema:url');

    $section('Duplicates (filtered by the API ACL)');
    $other = $c->save(['values' => ['dcterms:title' => [$title]], 'resources' => [], 'alignment' => []]);
    $check(!empty($other['ok']), 'the curator saves another draft with the same title');
    $seenByCurator = array_column($c->json('GET', 'duplicates?' . http_build_query(['title' => $title, 'id' => $other['id'] ?? 0]))['duplicates'] ?? [], 'id');
    $check(in_array($id, $seenByCurator, true), 'the curator is warned about the proposer’s REA');
    $seenByB = array_column($b->json('GET', 'duplicates?' . http_build_query(['title' => $title]))['duplicates'] ?? [], 'id');
    $check([] === $seenByB, 'another proposer is not shown REA they cannot read');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e), $e->getMessage());
} finally {
    $section('Cleanup');
    $asAdmin();
    $ids = $markerItems();
    if ($ids) {
        // One batch: with the Access module two deletes in a request fail (TASK-064 lesson).
        $api->batchDelete('items', $ids);
    }
    if ($created['template']) {
        $api->delete('resource_templates', $created['template']);
    }
    foreach ($previous as $key => $value) {
        $settings->set($key, $value);
    }
    foreach ($created['users'] as $userId) {
        $user = $em->find(\Omeka\Entity\User::class, $userId);
        if ($user) {
            $em->remove($user);
        }
    }
    $em->flush();
    array_map('unlink', array_filter($tmpFiles, 'is_file'));
    $left = $markerItems();
    $check([] === $left, 'every disposable item is deleted', implode(',', $left));
    echo '  deleted ' . count($ids) . ' items, ' . count($created['users']) . ' users'
        . ($created['template'] ? ', 1 template' : '') . "; settings restored\n";
}

foreach ($pending as $line) {
    echo "  PENDING $line\n";
}
echo "\n" . (0 === $failures ? 'ALL OK' : "$failures FAILED") . "\n";
exit(0 === $failures ? 0 : 1);
