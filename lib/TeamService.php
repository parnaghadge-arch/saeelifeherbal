<?php
/**
 * Team / downline statistics for the unilevel tree.
 *
 * The tree is stored as an adjacency list (members.sponsor_id). For fast reads the
 * member row caches direct_referral_count and downline_count, which are recalculated
 * by recalculate() after registrations or sponsor corrections.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class TeamService
{
    public function __construct(private Database $db)
    {
    }

    /** Direct referrals count / active count. */
    public function directStats(int $memberId): array
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive
             FROM members WHERE sponsor_id = :s",
            ['s' => $memberId]
        ) ?? [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
        ];
    }

    /** Entire downline (all levels) via a single quoted IN query on collected ids. */
    public function downlineIds(int $memberId, ?int $maxLevels = null): array
    {
        $levels = [];
        $frontier = [$memberId];
        $seen = [$memberId => true];
        $level = 0;
        while ($frontier !== [] && ($maxLevels === null || $level < $maxLevels)) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $rows = $this->db->select(
                "SELECT id FROM members WHERE sponsor_id IN ($placeholders)",
                $frontier
            );
            $next = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $next[] = $id;
            }
            if ($next === []) {
                break;
            }
            $levels[$level + 1] = $next;
            $frontier = $next;
            $level++;
        }
        return ['levels' => $levels, 'all' => array_keys($seen)];
    }

    public function downlineCount(int $memberId): array
    {
        $data = $this->downlineIds($memberId);
        $ids = array_values(array_filter($data['all'], static fn ($id) => (int) $id !== $memberId));
        if ($ids === []) {
            return ['total' => 0, 'active' => 0, 'by_level' => []];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $active = (int) $this->db->value(
            "SELECT COUNT(*) FROM members WHERE status = 'active' AND id IN ($placeholders)",
            $ids
        );
        $byLevel = [];
        foreach ($data['levels'] as $level => $levelIds) {
            $byLevel[$level] = count($levelIds);
        }
        return ['total' => count($ids), 'active' => $active, 'by_level' => $byLevel];
    }

    /** Recalculate cached counters for a member (and optionally the whole upline). */
    public function recalculate(int $memberId, bool $cascadeUpline = true): void
    {
        $direct = $this->directStats($memberId);
        $downline = $this->downlineCount($memberId);
        $this->db->update('members', [
            'direct_referral_count' => $direct['total'],
            'downline_count' => $downline['total'],
            'active_downline_count' => $downline['active'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $memberId]);

        if ($cascadeUpline) {
            $sponsorId = $this->db->value('SELECT sponsor_id FROM members WHERE id = :id', ['id' => $memberId]);
            $guard = 0;
            while ($sponsorId && $guard++ < 25) {
                $row = $this->db->first('SELECT id, sponsor_id, is_company FROM members WHERE id = :id', ['id' => $sponsorId]);
                if (!$row || (int) $row['is_company'] === 1) {
                    break;
                }
                $d = $this->directStats((int) $row['id']);
                $dl = $this->downlineCount((int) $row['id']);
                $this->db->update('members', [
                    'direct_referral_count' => $d['total'],
                    'downline_count' => $dl['total'],
                    'active_downline_count' => $dl['active'],
                ], 'id = :id', ['id' => $row['id']]);
                $sponsorId = $row['sponsor_id'];
            }
        }
    }

    /** Recalculate every member (maintenance task). */
    public function recalculateAll(): int
    {
        $members = $this->db->column('SELECT id FROM members ORDER BY id');
        foreach ($members as $id) {
            $this->recalculate((int) $id, false);
        }
        return count($members);
    }

    /** Team sales (paid orders placed by anyone in the downline). */
    public function teamSales(int $memberId, ?string $from = null, ?string $to = null): array
    {
        $ids = array_values(array_filter($this->downlineIds($memberId)['all'], static fn ($id) => (int) $id !== $memberId));
        if ($ids === []) {
            return ['amount' => 0.0, 'orders' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT COALESCE(SUM(grand_total),0) AS amount, COUNT(*) AS orders
                FROM orders WHERE payment_status = 'paid' AND status IN ('paid','processing','shipped','delivered')
                AND member_id IN ($placeholders)";
        $params = $ids;
        if ($from) {
            $sql .= ' AND DATE(created_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND DATE(created_at) <= ?';
            $params[] = $to;
        }
        $row = $this->db->first($sql, $params) ?? [];
        return ['amount' => (float) ($row['amount'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];
    }

    public function personalSales(int $memberId): array
    {
        $row = $this->db->first(
            "SELECT COALESCE(SUM(grand_total),0) AS amount, COUNT(*) AS orders FROM orders
             WHERE member_id = :m AND payment_status = 'paid' AND status IN ('paid','processing','shipped','delivered')",
            ['m' => $memberId]
        ) ?? [];
        return ['amount' => (float) ($row['amount'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];
    }

    /**
     * Paginated tree nodes for the genealogy viewer (one level at a time, keeps memory flat
     * for very large networks).
     */
    public function children(int $parentId, string $search = '', int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT m.id, m.member_code, m.name, m.status, m.role, m.joined_at,
                       m.direct_referral_count, m.downline_count, m.total_earned
                FROM members m WHERE m.sponsor_id = :p";
        $params = ['p' => $parentId];
        if ($search !== '') {
            $sql .= ' AND (m.name LIKE :q OR m.member_code LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY m.id ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return $this->db->select($sql, $params);
    }

    public function searchMembers(string $term, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT id, member_code, name, status, sponsor_id FROM members
             WHERE name LIKE :q OR member_code LIKE :q OR mobile LIKE :q
             ORDER BY id DESC LIMIT " . (int) $limit,
            ['q' => '%' . $term . '%']
        );
    }

    /** Path from root company member down to a given member (breadcrumb for the tree). */
    public function ancestry(int $memberId): array
    {
        $path = [];
        $current = (int) $memberId;
        $guard = 0;
        while ($current && $guard++ < 50) {
            $row = $this->db->first('SELECT id, member_code, name, sponsor_id FROM members WHERE id = :id', ['id' => $current]);
            if (!$row) {
                break;
            }
            array_unshift($path, $row);
            $current = $row['sponsor_id'] !== null ? (int) $row['sponsor_id'] : 0;
        }
        return $path;
    }
}
