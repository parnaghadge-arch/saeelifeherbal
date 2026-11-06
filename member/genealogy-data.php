<?php
/** AJAX endpoint: genealogy children + member search (scoped to the logged in member). */
declare(strict_types=1);

require __DIR__ . '/_guard.php';

use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\TeamService;

$team = new TeamService(db());
$downline = array_map('intval', $team->downlineIds($currentMemberId)['all'] ?? []);
$allowed = array_merge([$currentMemberId], $downline);

$search = trim((string) Request::query('search', ''));
if ($search !== '') {
    $members = [];
    foreach ($team->searchMembers($search, 20) as $row) {
        if (!in_array((int) $row['id'], $allowed, true)) { continue; }
        $members[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'member_code' => (string) $row['member_code'],
        ];
    }
    Response::success('ok', ['members' => $members]);
}

$parent = Request::int('parent', $currentMemberId);
if (!in_array($parent, $allowed, true)) {
    Response::error('Not permitted.', [], 403);
}
$rows = $team->children($parent, '', 100, 0);
$members = [];
foreach ($rows as $row) {
    $hasChildren = (int) $row['direct_referral_count'] > 0;
    $members[] = [
        'id' => (int) $row['id'],
        'name' => e((string) $row['name']),
        'member_code' => e((string) $row['member_code']),
        'initials' => e(initials((string) $row['name'])),
        'directs' => (int) $row['direct_referral_count'],
        'status_label' => e(ucfirst((string) $row['status'])),
        'has_children' => $hasChildren,
    ];
}
Response::success('ok', ['members' => $members]);
