<?php

namespace App;

use PDO;

/**
 * Campaign data access + pricing logic.
 * Keeps promotional rules out of the Router.
 */
class CampaignService
{
    private const TYPES = ['percent_off', 'fixed_off'];
    private const STATUSES = ['draft', 'active', 'paused', 'ended'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->db->prepare('SELECT * FROM campaigns WHERE status = ? ORDER BY id');
            $stmt->execute([$status]);
            return $stmt->fetchAll();
        }

        return $this->db->query('SELECT * FROM campaigns ORDER BY id')->fetchAll();
    }

    /** Active campaigns currently within their date window. */
    public function active(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM campaigns
             WHERE status = 'active'
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY id"
        );
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM campaigns WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByCoupon(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM campaigns WHERE coupon_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>|null, 1: string|null} [campaign, error]
     */
    public function create(array $data): array
    {
        $error = $this->validate($data, true);
        if ($error !== null) {
            return [null, $error];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO campaigns
                (name, description, type, value, coupon_code, starts_at, ends_at, status, usage_limit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['type'],
            (float) $data['value'],
            $data['coupon_code'] ?? null,
            $data['starts_at'] ?? null,
            $data['ends_at'] ?? null,
            $data['status'] ?? 'draft',
            isset($data['usage_limit']) ? (int) $data['usage_limit'] : null,
        ]);

        return [$this->find((int) $this->db->lastInsertId()), null];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>|null, 1: string|null} [campaign, error]
     */
    public function update(int $id, array $data): array
    {
        if ($this->find($id) === null) {
            return [null, 'not_found'];
        }

        $error = $this->validate($data, false);
        if ($error !== null) {
            return [null, $error];
        }

        $columns = ['name', 'description', 'type', 'value', 'coupon_code', 'starts_at', 'ends_at', 'status', 'usage_limit'];
        $fields = [];
        $values = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = ?";
                $values[] = $data[$col];
            }
        }

        if (empty($fields)) {
            return [null, 'no_fields'];
        }

        $values[] = $id;
        $this->db->prepare('UPDATE campaigns SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($values);

        return [$this->find($id), null];
    }

    /** @return string|null error code */
    public function delete(int $id): ?string
    {
        if ($this->find($id) === null) {
            return 'not_found';
        }

        try {
            $this->db->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'referenced';
            }
            throw $e;
        }

        return null;
    }

    /** Status active, inside window, and under usage limit. */
    public function isRunnable(array $campaign): bool
    {
        if (($campaign['status'] ?? '') !== 'active') {
            return false;
        }

        $now = time();
        if (!empty($campaign['starts_at']) && strtotime((string) $campaign['starts_at']) > $now) {
            return false;
        }
        if (!empty($campaign['ends_at']) && strtotime((string) $campaign['ends_at']) < $now) {
            return false;
        }

        if ($campaign['usage_limit'] !== null && (int) $campaign['times_used'] >= (int) $campaign['usage_limit']) {
            return false;
        }

        return true;
    }

    /** Discount for a subtotal; never exceeds the subtotal. */
    public function computeDiscount(float $subtotal, array $campaign): float
    {
        if ($campaign['type'] === 'percent_off') {
            $percent = min(100.0, max(0.0, (float) $campaign['value']));
            $discount = $subtotal * ($percent / 100);
        } else {
            $discount = (float) $campaign['value'];
        }

        $discount = min($discount, $subtotal);
        return round(max(0.0, $discount), 2);
    }

    /** Atomically bump usage counter after a campaign is applied. */
    public function incrementUsage(int $id): void
    {
        $this->db->prepare('UPDATE campaigns SET times_used = times_used + 1 WHERE id = ?')
            ->execute([$id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return string|null error message, or null if valid
     */
    private function validate(array $data, bool $isCreate): ?string
    {
        if ($isCreate && !isset($data['name'], $data['type'], $data['value'])) {
            return 'name, type and value are required';
        }

        if (array_key_exists('type', $data) && !in_array($data['type'], self::TYPES, true)) {
            return 'type must be percent_off or fixed_off';
        }

        if (array_key_exists('value', $data) && (float) $data['value'] <= 0) {
            return 'value must be greater than zero';
        }

        if (array_key_exists('status', $data) && !in_array($data['status'], self::STATUSES, true)) {
            return 'status must be one of: ' . implode(', ', self::STATUSES);
        }

        return null;
    }
}
