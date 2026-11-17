<?php
/**
 * AJAX endpoint for the admin MLM genealogy tree widget.
 *
 * Modes (same contract as member/genealogy-data.php):
 *   ?parent=<id>  one level of children (TeamService::treeChildren — raw values, the
 *                 widget fills DOM text nodes)
 *   ?detail=<id>  MLM details window payload (TeamService::mlmDetail — raw values)
 */
declare(strict_types=1);

require __DIR__ . '/includes/_guard.php';
admin_require('mlm.tree');

use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\TeamService;

$team = new TeamService(db());

$detailId = Request::int('detail');
if ($detailId > 0) {
    $detail = $team->mlmDetail($detailId);
    if (!$detail) {
        Response::error('Member not found.', [], 404);
    }
    Response::success('ok', ['detail' => $detail]);
}

$parent = Request::int('parent');
if ($parent <= 0) {
    Response::error('Missing parent member.', [], 422);
}
Response::success('ok', ['members' => $team->treeChildren($parent)]);
