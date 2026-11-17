<?php

namespace Omeka\Permissions;

/**
 * Host contract of Omeka's ACL (a Laminas ACL). Small but functional: roles
 * with parents, allow/deny rules per role, resource and privilege, and a
 * depth-first lookup through the parents like Laminas, so the REA matrix
 * (TASK-067) can be pinned on the host. The real behaviour is checked again
 * in the container (test/container/rea-roles-check.php).
 */
class Acl
{
    public const ROLE_GLOBAL_ADMIN = 'global_admin';
    public const ROLE_SITE_ADMIN = 'site_admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_AUTHOR = 'author';
    public const ROLE_RESEARCHER = 'researcher';

    /** @var array<string, list<string>> role => parents */
    private array $roles = [];

    /** @var list<array{0:string, 1:list<string>|null, 2:list<string>|null, 3:list<string>|null}> */
    private array $rules = [];

    /** @var array<string, string> */
    private array $roleLabels = [];

    /** Role of the current identity for userIsAllowed(); null = no identity, allowed (legacy stub). */
    public ?string $identityRole = null;

    /**
     * The core roles (flat, no parents) and the two allow-all rules of Omeka's
     * AclFactory: `global_admin` and `site_admin` («Supervisor», allowed
     * everything except module/setting/user administration).
     */
    public static function core(): self
    {
        $acl = new self();
        foreach (['researcher', 'author', 'reviewer', 'editor', 'site_admin', 'global_admin'] as $role) {
            $acl->addRole($role);
        }
        $acl->allow('global_admin');
        $acl->allow('site_admin');
        return $acl;
    }

    public function addRole($role, $parents = null)
    {
        if (isset($this->roles[$role])) {
            throw new \InvalidArgumentException("Role '$role' already exists");
        }
        $parents = null === $parents ? [] : (array) $parents;
        foreach ($parents as $parent) {
            $this->assertRole($parent);
        }
        $this->roles[$role] = $parents;
        return $this;
    }

    public function hasRole($role)
    {
        return isset($this->roles[$role]);
    }

    public function inheritsRole($role, $inherit, $onlyParents = false)
    {
        $this->assertRole($role);
        foreach ($this->roles[$role] as $parent) {
            if ($parent === $inherit || (!$onlyParents && $this->inheritsRole($parent, $inherit))) {
                return true;
            }
        }
        return false;
    }

    public function addRoleLabel($roleId, $roleLabel)
    {
        $this->roleLabels[$roleId] = $roleLabel;
    }

    public function getRoleLabels($excludeAdminRoles = false)
    {
        return $this->roleLabels;
    }

    public function allow($roles = null, $resources = null, $privileges = null, $assert = null)
    {
        return $this->rule('allow', $roles, $resources, $privileges);
    }

    public function deny($roles = null, $resources = null, $privileges = null, $assert = null)
    {
        return $this->rule('deny', $roles, $resources, $privileges);
    }

    public function isAllowed($role = null, $resource = null, $privilege = null)
    {
        $this->assertRole($role);
        return 'allow' === $this->lookup($role, $resource, $privilege);
    }

    public function userIsAllowed($resource = null, $privilege = null)
    {
        return null === $this->identityRole ? true : $this->isAllowed($this->identityRole, $resource, $privilege);
    }

    private function rule(string $type, $roles, $resources, $privileges)
    {
        $roles = null === $roles ? null : (array) $roles;
        foreach ($roles ?? [] as $role) {
            $this->assertRole($role);
        }
        $this->rules[] = [
            $type,
            $roles,
            null === $resources ? null : (array) $resources,
            null === $privileges ? null : (array) $privileges,
        ];
        return $this;
    }

    private function lookup(string $role, $resource, $privilege): ?string
    {
        $found = null;
        foreach ($this->rules as [$type, $roles, $resources, $privileges]) {
            if (
                (null === $roles || in_array($role, $roles, true))
                && (null === $resources || in_array($resource, $resources, true))
                && (null === $privileges || in_array($privilege, $privileges, true))
            ) {
                $found = $type;
            }
        }
        if (null !== $found) {
            return $found;
        }
        foreach ($this->roles[$role] as $parent) {
            $result = $this->lookup($parent, $resource, $privilege);
            if (null !== $result) {
                return $result;
            }
        }
        return null;
    }

    private function assertRole($role): void
    {
        if (!is_string($role) || !isset($this->roles[$role])) {
            throw new \InvalidArgumentException(sprintf("Role '%s' not found", (string) $role));
        }
    }
}
