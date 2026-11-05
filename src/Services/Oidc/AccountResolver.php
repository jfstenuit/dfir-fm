<?php

// src/Services/Oidc/AccountResolver.php
namespace Services\Oidc;

use Backends\LogEngineInterface;
use PDO;

/**
 * The DFIR FM account of a validated OIDC identity (D33, D34), without
 * HTTP: validated claims in, the account out, or an OidcRefusal with its
 * log cause.
 *
 * - The identity is (iss, sub). Unknown, it is linked to the account of
 *   its address (D33), or a new account is made: in the admin group with
 *   the admin claim, without any group or right otherwise (D34).
 * - Admin rights follow the IdP at every login (D12): added with the
 *   claim, removed without it, the login going on with the account's
 *   other rights. The last active admin isn't protected here.
 * - A locked or deleted account stays refused: a local lock overrides
 *   the IdP.
 *
 * Addresses are compared case-insensitively. Changes are made in one
 * transaction, and logged once it is committed.
 */
final class AccountResolver
{
    public function __construct(
        private readonly PDO $db,
        private readonly OidcConfig $config,
        private readonly LogEngineInterface $logger,
    ) {
    }

    /**
     * @return array the users row, after the changes
     * @throws OidcRefusal
     */
    public function resolve(Claims $claims): array
    {
        $email = $claims->email($this->config->emailClaim());
        if ($email === null) {
            throw new OidcRefusal('claims', "No address in the {$this->config->emailClaim()} claim");
        }
        if ($this->config->requireEmailVerified() && !$claims->emailVerified()) {
            throw new OidcRefusal('unverified_email', "The address $email isn't verified (email_verified)");
        }
        if (!$this->config->inDomain($email)) {
            throw new OidcRefusal('foreign_domain', "The address $email isn't in {$this->config->emailDomain()}");
        }
        $adminClaim = $this->config->adminClaim();
        if ($claims->claim($adminClaim) === null && $claims->hasOverage($adminClaim)) {
            throw new OidcRefusal('claim_overage', "The token names a source for $adminClaim (_claim_names) instead of listing it: use app roles");
        }
        $isAdmin = $claims->contains($adminClaim, $this->config->adminValue());

        $identity = ['iss' => $claims->issuer(), 'sub' => $claims->subject()];
        $events = [];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            [$user, $isNew] = $this->account($identity, $email, $events);
            $this->syncAdmin($user, $isAdmin, $isNew, $events);
            $this->db->prepare("UPDATE users SET display_name = :name, last_login_at = :now WHERE id = :id")
                ->execute([':name' => self::displayName($claims), ':now' => gmdate('Y-m-d H:i:s'), ':id' => $user['id']]);
            $user = $this->user('id = :id', [':id' => $user['id']]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        foreach ($events as [$action, $context]) {
            $this->logger->log($action, $context + $identity + ['username' => $user['username']]);
        }
        return $user;
    }

    /** @return array{0:array,1:bool} the account, and whether it was just created */
    private function account(array $identity, string $email, array &$events): array
    {
        $user = $this->user('oidc_issuer = :iss AND oidc_subject = :sub', [':iss' => $identity['iss'], ':sub' => $identity['sub']]);
        if ($user !== null) {
            self::refuseLocked($user);
            // A change of case alone isn't one: the username stays as it was
            if (strtolower($user['username']) !== strtolower($email)) {
                if ($this->others($email, (int)$user['id']) !== []) {
                    throw new OidcRefusal('username_conflict', "The address changed at the IdP to $email, which another account holds");
                }
                $this->db->prepare("UPDATE users SET username = :email, email = :email WHERE id = :id")
                    ->execute([':email' => $email, ':id' => $user['id']]);
                $events[] = ['oidc_address_changed', ['from' => $user['username'], 'to' => $email]];
                $user['username'] = $email;
            }
            return [$user, false];
        }

        $matches = $this->others($email);
        if (count($matches) > 1) {
            throw new OidcRefusal('username_conflict', "Several accounts have the address $email (in another case)");
        }
        if ($matches !== []) {
            $user = $matches[0];
            self::refuseLocked($user);
            if ($user['oidc_subject'] !== null) {
                throw new OidcRefusal('subject_conflict', "The account $email is linked to another IdP identity");
            }
            // D33: the IdP is authoritative for the domain. The password and
            // a pending invitation go, the rights stay; open sessions end.
            $this->db->prepare("UPDATE users SET oidc_issuer = :iss, oidc_subject = :sub, auth_source = 'oidc', password = NULL,
                                       invitation_token = NULL, token_expiry = NULL, session_version = session_version + 1
                                WHERE id = :id")
                ->execute([':iss' => $identity['iss'], ':sub' => $identity['sub'], ':id' => $user['id']]);
            $events[] = ['oidc_account_linked', ['previous_auth_source' => $user['auth_source'], 'had_password' => self::hadPassword($user)]];
            return [$this->user('id = :id', [':id' => $user['id']]), false];
        }

        $this->db->prepare("INSERT INTO users (username, email, password, auth_source, oidc_issuer, oidc_subject)
                            VALUES (:email, :email, NULL, 'oidc', :iss, :sub)")
            ->execute([':email' => $email, ':iss' => $identity['iss'], ':sub' => $identity['sub']]);
        $user = $this->user('id = :id', [':id' => (int)$this->db->lastInsertId()]);
        return [$user, true];
    }

    private function syncAdmin(array $user, bool $isAdmin, bool $isNew, array &$events): void
    {
        $memberOf = $this->db->prepare("SELECT COUNT(*) FROM user_group ug JOIN groups g ON g.id = ug.group_id
                                        WHERE ug.user_id = :id AND g.is_admin = 1");
        $memberOf->execute([':id' => $user['id']]);
        $wasAdmin = (int)$memberOf->fetchColumn() > 0;

        if ($isAdmin && !$wasAdmin) {
            $group = $this->db->query("SELECT id FROM groups WHERE is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
            if ($group === false) {
                throw new \RuntimeException('No administrator group'); // Database::initialize() creates one
            }
            $this->db->prepare("INSERT INTO user_group (user_id, group_id) VALUES (:user, :group)")
                ->execute([':user' => $user['id'], ':group' => $group]);
            if (!$isNew) {
                $events[] = ['oidc_admin_restored', []];
            }
        } elseif (!$isAdmin && $wasAdmin) {
            // Every admin group: the IdP decides, whichever one it was
            $this->db->prepare("DELETE FROM user_group WHERE user_id = :id AND group_id IN (SELECT id FROM groups WHERE is_admin = 1)")
                ->execute([':id' => $user['id']]);
            $events[] = ['oidc_admin_revoked', []];
        }
        if ($isNew) {
            $events[] = ['oidc_user_provisioned', ['admin' => $isAdmin]];
        }
    }

    /** Accounts other than $exceptId holding this address (username or email), deleted ones aside */
    private function others(string $email, int $exceptId = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE deleted_at IS NULL AND id <> :id
                                    AND (LOWER(username) = LOWER(:email) OR LOWER(email) = LOWER(:email)) ORDER BY id");
        $stmt->execute([':email' => $email, ':id' => $exceptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function user(string $where, array $params): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE $where");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function refuseLocked(array $user): void
    {
        if ($user['deleted_at'] !== null || $user['locked_at'] !== null) {
            throw new OidcRefusal('locked', sprintf('The account #%d is %s', $user['id'], $user['deleted_at'] !== null ? 'deleted' : 'locked'));
        }
    }

    private static function hadPassword(array $user): bool
    {
        return is_string($user['password']) && $user['password'] !== '' && $user['password'] !== '!';
    }

    /** The IdP's name for the account: printable, at most 200 characters, or null */
    private static function displayName(Claims $claims): ?string
    {
        $name = $claims->get('name');
        if (!is_string($name)) {
            return null;
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        return $name === '' ? null : mb_substr($name, 0, 200);
    }
}
