<?php

declare(strict_types=1);

namespace OERManager\Test\Permissions;

use OERManager\Controller\Admin\AiBatchController;
use OERManager\Controller\Admin\GovernanceBatchController;
use OERManager\Controller\Admin\IndexController;
use OERManager\Controller\Admin\StatsController;
use OERManager\Permissions\ReaAcl;
use Omeka\Permissions\Acl;
use PHPUnit\Framework\TestCase;

/**
 * TASK-067 (ADR-0023 §1): OERManager grants only to the two REA roles and
 * global_admin. This pins the whole role × privilege matrix on a functional
 * ACL fake; the container harness checks it again on Omeka's real ACL.
 */
final class ReaAclTest extends TestCase
{
    private const CONTROLLERS = [
        IndexController::class,
        StatsController::class,
        GovernanceBatchController::class,
        AiBatchController::class,
    ];

    /** Expected privileges per role and controller. Anything not listed is denied. */
    private const EXPECTED = [
        'rea_proponente' => [
            // TASK-035 §3: the panel from «Mis propuestas» and the re-cataloguer
            // (RecatalogRule narrows it to their own Borrador/Rechazado REA, and
            // the panel reads to their own REA: IndexControllerTest).
            // No master view, visibility, governance, AI, batches or statistics.
            IndexController::class => [
                'propose', 'my-proposals',
                'drawer-details', 'drawer-history', 'search-terms', 'curriculum-tree',
                'recatalog-preview', 'recatalog-apply', 'recatalog-last-event', 'recatalog-undo',
            ],
        ],
        'rea_curador' => [
            IndexController::class => [
                'index', 'search', 'search-terms', 'curriculum-tree', 'set-visibility',
                'recatalog-preview', 'recatalog-apply', 'governance-apply', 'recatalog-last-event',
                'drawer-details', 'drawer-history', 'recatalog-undo',
                'ai-propose', 'ai-propose-status', 'ai-propose-cancel', 'ai-evaluate', 'ai-proposal-discard',
                'propose', 'reject-proposal', 'publish-proposal', 'discard', 'unpublish', 'reopen',
            ],
            StatsController::class => ['index', 'export', 'layout'],
            GovernanceBatchController::class => ['form', 'preview', 'apply', 'status', 'cancel', 'recent', 'undo'],
            AiBatchController::class => ['form', 'preview', 'launch', 'status', 'cancel'],
        ],
    ];

    /** Privileges that are not controller actions. */
    private const PSEUDO_PRIVILEGES = [
        GovernanceBatchController::class => [GovernanceBatchController::PRIVILEGE_UNDO_ANY],
    ];

    private function aclWithIsolatedSites(): Acl
    {
        $acl = Acl::core();
        $acl->addRole('site_editor', 'editor');
        return $acl;
    }

    /** @return array<string, list<string>> controller => privileges (actions and pseudo-privileges) */
    private static function privileges(): array
    {
        $out = [];
        foreach (self::CONTROLLERS as $controller) {
            $out[$controller] = array_merge(self::actions($controller), self::PSEUDO_PRIVILEGES[$controller] ?? []);
        }
        return $out;
    }

    /** @return list<string> the dashed action names the router dispatches to */
    private static function actions(string $controller): array
    {
        $actions = [];
        foreach ((new \ReflectionClass($controller))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $controller || !str_ends_with($method->name, 'Action')) {
                continue;
            }
            $actions[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', substr($method->name, 0, -6)));
        }
        return $actions;
    }

    public function testTheWholeMatrixIsGrantedToTheReaRolesAndNobodyElse(): void
    {
        $acl = $this->aclWithIsolatedSites();
        ReaAcl::register($acl);
        $roles = ['researcher', 'author', 'reviewer', 'editor', 'site_admin', 'site_editor',
            'rea_proponente', 'rea_curador', 'global_admin'];
        foreach ($roles as $role) {
            foreach (self::privileges() as $controller => $privileges) {
                foreach ($privileges as $privilege) {
                    $expected = 'global_admin' === $role
                        || in_array($privilege, self::EXPECTED[$role][$controller] ?? [], true);
                    $this->assertSame(
                        $expected,
                        $acl->isAllowed($role, $controller, $privilege),
                        sprintf('%s on %s::%s', $role, $controller, $privilege)
                    );
                }
            }
        }
    }

    public function testEveryControllerActionIsDecidedAndEveryGrantIsAnAction(): void
    {
        foreach (self::privileges() as $controller => $privileges) {
            $granted = [];
            foreach (ReaAcl::GRANTS[$controller] ?? [] as $rolePrivileges) {
                $granted = array_merge($granted, $rolePrivileges);
            }
            $known = array_unique(array_merge($granted, ReaAcl::ADMIN_ONLY[$controller] ?? []));
            sort($known);
            $actual = $privileges;
            sort($actual);
            $this->assertSame($actual, $known, $controller . ': an action without a decision, or a grant without an action');
        }
        $this->assertSame([IndexController::class => ['config'],
            GovernanceBatchController::class => [GovernanceBatchController::PRIVILEGE_UNDO_ANY]], ReaAcl::ADMIN_ONLY);
    }

    public function testRolesInheritFromSiteEditorWithLabels(): void
    {
        $acl = $this->aclWithIsolatedSites();
        ReaAcl::register($acl);
        $this->assertTrue($acl->inheritsRole('rea_proponente', 'site_editor', true));
        $this->assertTrue($acl->inheritsRole('rea_curador', 'site_editor', true));
        $this->assertSame(['rea_proponente' => 'Proponente REA', 'rea_curador' => 'Curador REA'], $acl->getRoleLabels());
        // Registering twice (a second bootstrap) neither throws nor duplicates.
        ReaAcl::register($acl);
        $this->assertTrue($acl->hasRole('rea_curador'));
    }

    public function testWithoutIsolatedSitesTheRolesFallBackToTheLeastNativeRoleThatWorks(): void
    {
        $acl = Acl::core();
        ReaAcl::register($acl);
        $this->assertTrue($acl->inheritsRole('rea_proponente', 'author', true));
        $this->assertTrue($acl->inheritsRole('rea_curador', 'editor', true));
        $this->assertFalse($acl->isAllowed('editor', IndexController::class, 'index'));
        $this->assertTrue($acl->isAllowed('rea_curador', IndexController::class, 'index'));
        $this->assertFalse($acl->isAllowed('rea_proponente', IndexController::class, 'index'));
    }

    public function testUninstallTargetsAreTheParentRoles(): void
    {
        $this->assertSame(
            ['rea_proponente' => 'site_editor', 'rea_curador' => 'site_editor'],
            ReaAcl::uninstallTargets(true)
        );
        $this->assertSame(
            ['rea_proponente' => 'author', 'rea_curador' => 'editor'],
            ReaAcl::uninstallTargets(false)
        );
    }
}
