<?php
/**
 * B2B lead pipeline (lightweight CRM).
 *
 * Every submission from /b2b lands here. A lead moves through the stages below; each
 * change is journalled in `b2b_lead_activities` so the full conversation history of a
 * prospect is visible in one place.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('b2b.view');

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;

$stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost', 'spam'];
$businessTypes = [
    'retailer' => 'Retail shop', 'distributor' => 'Distributor', 'dealer' => 'Dealer',
    'fpo' => 'FPO', 'cooperative' => 'Co-operative', 'exporter' => 'Exporter', 'other' => 'Other',
];

$db = db();
$canManage = AdminAuth::can('b2b.manage');

if (is_post() && $canManage) {
    Security::verifyCsrf();
    $id = Request::int('id');
    $lead = $db->first('SELECT * FROM b2b_leads WHERE id = :id', ['id' => $id]);
    if ($lead) {
        $action = (string) Request::input('action', 'note');
        $now = date('Y-m-d H:i:s');

        if ($action === 'delete') {
            $db->delete('b2b_leads', 'id = :id', ['id' => $id]);
            Audit::log('delete', 'b2b', 'Deleted B2B lead ' . ($lead['lead_no'] ?: '#' . $id), 'b2b_leads', $id);
            Session::success('Lead ' . ($lead['lead_no'] ?: '#' . $id) . ' deleted.');
            Request::redirect('b2b-leads.php');
        }

        if ($action === 'convert') {
            $db->update('b2b_leads', ['status' => 'won', 'updated_at' => $now], 'id = :id', ['id' => $id]);
            $db->insert('b2b_lead_activities', [
                'lead_id' => $id, 'admin_id' => AdminAuth::id(), 'type' => 'status',
                'body' => 'Marked as converted (won).', 'old_status' => (string) $lead['status'], 'new_status' => 'won',
                'created_at' => $now,
            ]);
            Audit::log('convert', 'b2b', 'Converted B2B lead ' . ($lead['lead_no'] ?: '#' . $id), 'b2b_leads', $id);
            Session::success('Lead ' . ($lead['lead_no'] ?: '#' . $id) . ' marked as won.');
            Request::redirect('b2b-leads.php?id=' . $id);
        }

        // Partial update: only the fields the submitted form actually carries are touched, so
        // logging an activity never wipes the owner or the follow-up date.
        $changes = ['updated_at' => $now];
        $status = (string) $lead['status'];

        if (array_key_exists('status', $_POST)) {
            $status = (string) Request::input('status', (string) $lead['status']);
            if (!in_array($status, $stages, true)) {
                $status = (string) $lead['status'];
            }
            $changes['status'] = $status;
        }
        if (array_key_exists('priority', $_POST)) {
            $priority = (string) Request::input('priority', (string) $lead['priority']);
            $changes['priority'] = in_array($priority, ['low', 'medium', 'high'], true) ? $priority : (string) $lead['priority'];
        }
        if (array_key_exists('assigned_to', $_POST)) {
            $assigned = Request::int('assigned_to');
            $changes['assigned_to'] = $assigned > 0 ? $assigned : null;
        }
        if (array_key_exists('follow_up_at', $_POST)) {
            $followUp = trim((string) Request::input('follow_up_at', ''));
            $changes['follow_up_at'] = $followUp !== '' ? date('Y-m-d H:i:s', strtotime($followUp)) : null;
        }
        if (array_key_exists('admin_note', $_POST)) {
            $changes['admin_note'] = trim((string) Request::input('admin_note', '')) ?: null;
        }

        $db->update('b2b_leads', $changes, 'id = :id', ['id' => $id]);
        Audit::log('update', 'b2b', 'Updated B2B lead ' . ($lead['lead_no'] ?: '#' . $id), 'b2b_leads', $id);

        if ($status !== (string) $lead['status']) {
            $db->insert('b2b_lead_activities', [
                'lead_id' => $id, 'admin_id' => AdminAuth::id(), 'type' => 'status',
                'body' => 'Stage changed to ' . ucfirst($status) . '.',
                'old_status' => (string) $lead['status'], 'new_status' => $status,
                'created_at' => $now,
            ]);
            Session::success('Lead updated — stage is now ' . ucfirst($status) . '.');
        } else {
            Session::success('Lead updated successfully.');
        }

        $note = trim((string) Request::input('note', ''));
        if ($note !== '') {
            $type = (string) Request::input('note_type', 'note');
            if (!in_array($type, ['note', 'call', 'email', 'whatsapp', 'meeting'], true)) {
                $type = 'note';
            }
            $db->insert('b2b_lead_activities', [
                'lead_id' => $id, 'admin_id' => AdminAuth::id(), 'type' => $type,
                'body' => $note, 'created_at' => $now,
            ]);
            Session::success('Activity logged.');
        }
    }
    Request::redirect('b2b-leads.php?id=' . $id);
}

$viewId = Request::int('id');
$lead = $viewId > 0 ? $db->first('SELECT l.*, a.name AS owner_name FROM b2b_leads l LEFT JOIN admin_users a ON a.id = l.assigned_to WHERE l.id = :id', ['id' => $viewId]) : null;
$status = (string) Request::query('status', '');
$search = trim((string) Request::query('q', ''));

$where = '1=1';
$params = [];
if (in_array($status, $stages, true)) { $where .= ' AND l.status = :s'; $params['s'] = $status; }
if ($search !== '') {
    $where .= ' AND (l.contact_name LIKE :q OR l.company_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR l.lead_no LIKE :q)';
    $params['q'] = '%' . $search . '%';
}

$total = (int) $db->value("SELECT COUNT(*) FROM b2b_leads l WHERE $where", $params);
$pager = new Pager($total, 20);
$rows = $db->select("SELECT l.*, a.name AS owner_name FROM b2b_leads l LEFT JOIN admin_users a ON a.id = l.assigned_to WHERE $where ORDER BY l.id DESC" . $pager->limitSql(), $params);
$counts = $db->first("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,
    SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won_count
    FROM b2b_leads") ?? [];
$stageCounts = [];
foreach ($db->select('SELECT status, COUNT(*) AS c FROM b2b_leads GROUP BY status') as $r) {
    $stageCounts[(string) $r['status']] = (int) $r['c'];
}
$admins = $db->select("SELECT id, name FROM admin_users WHERE status = 'active' ORDER BY name");
$activities = $lead ? $db->select('SELECT * FROM b2b_lead_activities WHERE lead_id = :id ORDER BY id DESC', ['id' => $lead['id']]) : [];

$pageTitle = 'B2B Leads';
$pageHeading = 'B2B Leads & CRM';
$pageSub = (int) ($counts['new_count'] ?? 0) . ' new · ' . (int) ($counts['won_count'] ?? 0) . ' won · ' . (int) ($counts['total'] ?? 0) . ' total';
$activeModule = 'b2b-leads';
require __DIR__ . '/includes/layout.php';
?>
<?php if ($lead): ?>
  <!-- ------------------------------------------------------------ lead detail -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="b2b-leads.php"><i class="fa-solid fa-arrow-left me-1"></i>All leads</a>
    <div class="d-flex align-items-center gap-2">
      <?= status_badge((string) $lead['status']) ?>
      <span class="chip"><?= e((string) ($lead['lead_no'] ?: '#' . $lead['id'])) ?></span>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="panel p-3 p-md-4 mb-3">
        <h2 class="h6 mb-3"><?= e((string) $lead['contact_name']) ?><?= $lead['company_name'] ? ' · ' . e((string) $lead['company_name']) : '' ?></h2>
        <dl class="row small mb-0">
          <dt class="col-5 text-muted fw-normal">Business type</dt><dd class="col-7"><?= e($businessTypes[(string) $lead['business_type']] ?? 'Other') ?></dd>
          <dt class="col-5 text-muted fw-normal">Mobile</dt><dd class="col-7"><a href="tel:<?= e((string) $lead['phone']) ?>"><?= e((string) $lead['phone']) ?></a></dd>
          <?php if ($lead['whatsapp']): ?><dt class="col-5 text-muted fw-normal">WhatsApp</dt><dd class="col-7"><a href="https://wa.me/91<?= e((string) $lead['whatsapp']) ?>" target="_blank" rel="noopener"><?= e((string) $lead['whatsapp']) ?></a></dd><?php endif; ?>
          <?php if ($lead['email']): ?><dt class="col-5 text-muted fw-normal">Email</dt><dd class="col-7"><a href="mailto:<?= e((string) $lead['email']) ?>"><?= e((string) $lead['email']) ?></a></dd><?php endif; ?>
          <?php if ($lead['designation']): ?><dt class="col-5 text-muted fw-normal">Designation</dt><dd class="col-7"><?= e((string) $lead['designation']) ?></dd><?php endif; ?>
          <dt class="col-5 text-muted fw-normal">Location</dt><dd class="col-7"><?= e(trim(implode(', ', array_filter([(string) ($lead['city'] ?? ''), (string) ($lead['state'] ?? ''), (string) ($lead['pincode'] ?? '')])))) ?: '—' ?></dd>
          <?php if ($lead['gstin']): ?><dt class="col-5 text-muted fw-normal">GSTIN</dt><dd class="col-7"><?= e((string) $lead['gstin']) ?></dd><?php endif; ?>
          <?php if ($lead['annual_turnover']): ?><dt class="col-5 text-muted fw-normal">Turnover</dt><dd class="col-7"><?= e((string) $lead['annual_turnover']) ?></dd><?php endif; ?>
          <?php if ($lead['monthly_volume']): ?><dt class="col-5 text-muted fw-normal">Monthly volume</dt><dd class="col-7"><?= e((string) $lead['monthly_volume']) ?></dd><?php endif; ?>
          <?php if ($lead['product_interest']): ?><dt class="col-5 text-muted fw-normal">Products</dt><dd class="col-7"><?= e((string) $lead['product_interest']) ?></dd><?php endif; ?>
          <dt class="col-5 text-muted fw-normal">Received</dt><dd class="col-7"><?= e((string) $lead['created_at']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Owner</dt><dd class="col-7"><?= e((string) ($lead['owner_name'] ?? 'Unassigned')) ?></dd>
        </dl>
        <?php if ($lead['message']): ?>
          <hr>
          <p class="small mb-0"><?= nl2br(e((string) $lead['message'])) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($canManage): ?>
        <div class="panel p-3 p-md-4">
          <h3 class="h6 mb-3">Pipeline</h3>
          <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
            <input type="hidden" name="action" value="update">
            <div class="col-6">
              <label class="form-label small">Stage</label>
              <select class="form-select form-select-sm" name="status">
                <?php foreach ($stages as $s): ?><option value="<?= e($s) ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small">Priority</label>
              <select class="form-select form-select-sm" name="priority">
                <?php foreach (['low', 'medium', 'high'] as $p): ?><option value="<?= e($p) ?>" <?= $lead['priority'] === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small">Assign to</label>
              <select class="form-select form-select-sm" name="assigned_to">
                <option value="">Unassigned</option>
                <?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $lead['assigned_to'] === (int) $a['id'] ? 'selected' : '' ?>><?= e((string) $a['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small">Follow-up on</label>
              <input type="datetime-local" class="form-control form-control-sm" name="follow_up_at" value="<?= $lead['follow_up_at'] ? e(date('Y-m-d\TH:i', strtotime((string) $lead['follow_up_at']))) : '' ?>">
            </div>
            <div class="col-12">
              <label class="form-label small">Internal note</label>
              <textarea class="form-control form-control-sm" name="admin_note" rows="2"><?= e((string) ($lead['admin_note'] ?? '')) ?></textarea>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
              <button class="btn btn-sm btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Save changes</button>
            </div>
          </form>
          <hr>
          <h3 class="h6 mb-2">Log an activity</h3>
          <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
            <input type="hidden" name="action" value="update">
            <div class="col-5">
              <select class="form-select form-select-sm" name="note_type">
                <?php foreach (['note' => 'Note', 'call' => 'Call', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting'] as $k => $lbl): ?>
                  <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-7"><input class="form-control form-control-sm" name="note" placeholder="What happened?" required></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-plus me-1"></i>Add to timeline</button></div>
          </form>
          <hr>
          <div class="d-flex flex-wrap gap-2">
            <form method="post" onsubmit="return confirm('Mark this lead as won?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
              <input type="hidden" name="action" value="convert">
              <button class="btn btn-sm btn-light-green" type="submit"><i class="fa-solid fa-check me-1"></i>Mark won</button>
            </form>
            <form method="post" onsubmit="return confirm('Delete this lead permanently?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-regular fa-trash-can me-1"></i>Delete lead</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-lg-7">
      <div class="panel p-3 p-md-4">
        <h3 class="h6 mb-3">Activity timeline</h3>
        <?php if (!$activities): ?><p class="small text-muted mb-0">No activity recorded yet.</p><?php endif; ?>
        <ul class="list-unstyled timeline mb-0">
          <?php foreach ($activities as $act): ?>
            <li>
              <div class="d-flex justify-content-between gap-2">
                <strong class="small"><?= e(ucfirst((string) $act['type'])) ?></strong>
                <span class="small text-muted"><?= e(time_ago((string) $act['created_at'])) ?></span>
              </div>
              <div class="small"><?= nl2br(e((string) $act['body'])) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ------------------------------------------------------------ lead list -->
  <form class="filter-bar mb-3 flex-wrap" method="get">
    <a class="btn btn-sm <?= $status === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?">All (<?= (int) ($counts['total'] ?? 0) ?>)</a>
    <?php foreach ($stages as $s): ?>
      <a class="btn btn-sm <?= $status === $s ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= e($s) ?>"><?= e(ucfirst($s)) ?> (<?= (int) ($stageCounts[$s] ?? 0) ?>)</a>
    <?php endforeach; ?>
    <div class="input-group input-group-sm ms-auto" style="max-width:260px">
      <input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, phone, lead no.">
      <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
  </form>

  <div class="panel p-3 p-md-4">
    <?php // The table always renders: an empty <tbody> lets DataTables show its own
          // "No records yet" state, and the markup stays identical either way. ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 data-table">
          <thead>
            <tr>
              <th scope="col">Lead</th>
              <th scope="col">Contact</th>
              <th scope="col">Type</th>
              <th scope="col">Location</th>
              <th scope="col">Stage</th>
              <th scope="col">Owner</th>
              <th scope="col">Received</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <div class="small fw-semibold"><?= e((string) ($row['company_name'] ?: $row['contact_name'])) ?></div>
                  <div class="small text-muted"><?= e((string) ($row['lead_no'] ?: '#' . $row['id'])) ?></div>
                </td>
                <td>
                  <div class="small"><?= e((string) $row['contact_name']) ?></div>
                  <div class="small text-muted"><?= e((string) $row['phone']) ?><?= $row['email'] ? ' · ' . e((string) $row['email']) : '' ?></div>
                </td>
                <td class="small"><?= e($businessTypes[(string) $row['business_type']] ?? 'Other') ?></td>
                <td class="small"><?= e(trim(implode(', ', array_filter([(string) ($row['city'] ?? ''), (string) ($row['state'] ?? '')])))) ?: '—' ?></td>
                <td><?= status_badge((string) $row['status']) ?></td>
                <td class="small"><?= e((string) ($row['owner_name'] ?? '—')) ?></td>
                <td class="small text-muted"><?= e(time_ago((string) $row['created_at'])) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?id=<?= (int) $row['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > 20): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
          <div class="small text-muted"><?= $pager->infoText() ?></div>
          <?= $pager->links(['status' => $status, 'q' => $search]) ?>
        </div>
      <?php endif; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
