<?php
/**
 * Member portal bootstrap + authentication guard.
 * Every member page starts by requiring this file.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\Url;

MemberAuth::requireLogin();
$member = MemberAuth::user();
$currentMemberId = (int) $member['id'];

/** Convenience: current wallet account for the logged in member. */
$walletAccount = (new \Saee\Lib\WalletService(db()))->account($currentMemberId);
$payoutService = new \Saee\Lib\PayoutService(db());
