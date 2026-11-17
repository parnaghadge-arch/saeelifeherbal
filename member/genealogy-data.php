<?php
/**
 * AJAX endpoint for the shared MLM genealogy tree widget (scoped to the logged in member).
 *
 * Modes:
 *   ?parent=<id>  one level of children (TeamService::treeChildren — raw values, the
 *                 widget fills DOM text nodes)
 *   ?detail=<id>  MLM details window payload (TeamService::mlmDetail — raw values)
 *   ?search=      legacy search dropdown (pre-escaped HTML strings, kept for the
 *                 search box on the genealogy page)
 */
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
        if (!in_array((int) $row['id'], $allowed, true)) {
            continue;
        }
        $members[] = [
            'id' => (int) $row['id'],
            'name' => e((string) $row['name']),
            'member_code' => e((string) $row['member_code']),
        ];
    }
    Response::success('ok', ['members' => $members]);
}

$detailId = Request::int('detail');
if ($detailId > 0) {
    if (!in_array($detailId, $allowed, true)) {
        Response::error('Not permitted.', [], 403);
    }
    Response::success('ok', ['detail' => $team->mlmDetail($detailId)]);
}

$parent = Request::int('parent', $currentMemberId);
if (!in_array($parent, $allowed, true)) {
    Response::error('Not permitted.', [], 403);
}
Response::success('ok', ['members' => $team->treeChildren($parent)]);
