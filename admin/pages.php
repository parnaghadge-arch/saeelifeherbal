<?php
/**
 * CMS page builder: page meta, banners, rich content and ordering.
 * Sections (page_sections) are managed from the same screen.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('cms.pages');

use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
$id = Request::int('id');
$page = $id ? $db->first('SELECT * FROM website_pages WHERE id = :id', ['id' => $id]) : null;
$errors = [];

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'save');

    if ($action === 'delete') {
        $target = $db->first('SELECT * FROM website_pages WHERE id = :id', ['id' => Request::int('id')]);
        if ($target && (int) $target['is_system'] === 1) {
            Session::error('System pages (policy pages) cannot be deleted. Unpublish them instead.');
        } elseif ($target) {
            $db->delete('page_sections', 'page_id = :p', ['p' => (int) $target['id']]);
            $db->delete('website_pages', 'id = :id', ['id' => (int) $target['id']]);
            Audit::log('delete', 'cms.pages', 'Deleted page ' . $target['slug'], 'website_pages', (int) $target['id']);
            Session::success('Page deleted.');
        }
        Request::redirect('pages.php');
    }

    if ($action === 'section-save') {
        $pageId = Request::int('page_id');
        $data = [
            'page_id' => $pageId,
            'section_key' => slugify((string) Request::input('section_key', 'section')) ?: 'section',
            'title' => (string) Request::input('section_title', ''),
            'subtitle' => (string) Request::input('section_subtitle', ''),
            'content' => Security::cleanHtml((string) Request::raw('section_content', '')),
            'sort_order' => Request::int('section_sort', 0),
            'status' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($_FILES['section_image']['name'])) {
            $up = Uploader::store($_FILES['section_image'], 'pages');
            if ($up['ok']) { $data['image'] = $up['path']; }
        }
        $sectionId = Request::int('section_id');
        if ($sectionId) {
            $db->update('page_sections', $data, 'id = :id', ['id' => $sectionId]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('page_sections', $data);
        }
        Audit::log('update', 'cms.pages', 'Saved page section on page #' . $pageId, 'page_sections', $sectionId ?: null);
        Session::success('Section saved.');
        Request::redirect('pages.php?id=' . $pageId);
    }

    if ($action === 'section-delete') {
        $pageId = Request::int('page_id');
        $db->delete('page_sections', 'id = :id', ['id' => Request::int('section_id')]);
        Audit::log('delete', 'cms.pages', 'Deleted page section on page #' . $pageId, 'page_sections', Request::int('section_id'));
        Session::success('Section removed.');
        Request::redirect('pages.php?id=' . $pageId);
    }

    // ---- page save
    $data = [
        'title' => trim((string) Request::input('title', '')),
        'slug' => trim((string) Request::input('slug', '')),
        'meta_title' => (string) Request::input('meta_title', ''),
        'meta_description' => (string) Request::input('meta_description', ''),
        'meta_keywords' => (string) Request::input('meta_keywords', ''),
        'canonical_url' => (string) Request::input('canonical_url', ''),
        'content' => Security::cleanHtml((string) Request::raw('content', '')),
        'custom_css' => (string) Request::raw('custom_css', ''),
        'template' => (string) Request::input('template', 'default'),
        'robots' => (string) Request::input('robots', 'index,follow'),
        'visibility' => (string) Request::input('visibility', 'public'),
        'show_in_nav' => Request::bool('show_in_nav') ? 1 : 0,
        'show_in_footer' => Request::bool('show_in_footer') ? 1 : 0,
        'status' => Request::input('status') === 'published' ? 'published' : 'draft',
        'sort_order' => Request::int('sort_order', 0),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($data['title'] === '') { $errors['title'] = ['Title is required.']; }
    if ($data['slug'] === '') { $data['slug'] = unique_slug('website_pages', 'slug', $data['title'], $page['id'] ?? null); }
    foreach (['featured_image' => 'pages', 'banner_image' => 'pages', 'og_image' => 'pages'] as $field => $folder) {
        if (!empty($_FILES[$field]['name'])) {
            $up = Uploader::store($_FILES[$field], $folder);
            if ($up['ok']) { $data[$field] = $up['path']; } else { $errors[$field] = [$up['message']]; }
        }
    }
    if (empty($errors)) {
        try {
            if ($page) {
                $db->update('website_pages', $data, 'id = :id', ['id' => $page['id']]);
                Audit::log('update', 'cms.pages', 'Updated page ' . $data['slug'], 'website_pages', (int) $page['id'], Audit::diff($page, $data));
                Session::success('Page updated.');
                Request::redirect('pages.php?id=' . (int) $page['id']);
            }
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['author_id'] = (int) $adminUser['id'];
            $data['published_at'] = $data['status'] === 'published' ? date('Y-m-d H:i:s') : null;
            $newId = $db->insert('website_pages', $data);
            Audit::log('create', 'cms.pages', 'Created page ' . $data['slug'], 'website_pages', $newId);
            Session::success('Page created.');
            Request::redirect('pages.php?id=' . $newId);
        } catch (Throwable $e) {
            $errors['_general'] = ['Could not save: ' . $e->getMessage()];
        }
    }
}

$search = trim((string) Request::query('q', ''));
$where = '1=1';
$params = [];
if ($search !== '') {
    $where = '(title LIKE :q OR slug LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$total = (int) $db->value("SELECT COUNT(*) FROM website_pages WHERE $where", $params);
$pager = new Pager($total, 20);
$rows = $db->select("SELECT * FROM website_pages WHERE $where ORDER BY sort_order ASC, id ASC" . $pager->limitSql(), $params);
$sections = $page ? $db->select('SELECT * FROM page_sections WHERE page_id = :p ORDER BY sort_order ASC, id ASC', ['p' => (int) $page['id']]) : [];

$pageTitle = 'CMS Pages';
$pageHeading = 'CMS Pages';
$pageSub = $total . ' pages · full page builder with sections, SEO and custom CSS';
$activeModule = 'pages';
require __DIR__ . '/includes/layout.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel p-3 p-md-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0"><?= $page ? 'Edit page' : 'Create page' ?></h2>
        <?php if ($page): ?><a class="btn btn-sm btn-outline-secondary" href="pages.php">New page</a><?php endif; ?>
      </div>
      <?php if (!empty($errors['_general'])): ?><div class="alert alert-danger small"><?= e($errors['_general'][0]) ?></div><?php endif; ?>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($page): ?><input type="hidden" name="id" value="<?= (int) $page['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label">Title <span class="required-mark">*</span></label><input name="title" class="form-control<?= invalid_class($errors, 'title') ?>" value="<?= e((string) ($page['title'] ?? '')) ?>" required><?= field_error($errors, 'title') ?></div>
        <div class="mb-3"><label class="form-label">Slug</label><input name="slug" class="form-control" value="<?= e((string) ($page['slug'] ?? '')) ?>" placeholder="auto-generated"><div class="form-text">Public URL: /<?= e($page['slug'] ?? 'your-slug') ?></div></div>
        <div class="mb-3"><label class="form-label">Meta title</label><input name="meta_title" class="form-control" maxlength="191" value="<?= e((string) ($page['meta_title'] ?? '')) ?>"></div>
        <div class="mb-3"><label class="form-label">Meta description</label><textarea name="meta_description" class="form-control" rows="2" maxlength="255"><?= e((string) ($page['meta_description'] ?? '')) ?></textarea></div>
        <div class="mb-3"><label class="form-label">Meta keywords</label><input name="meta_keywords" class="form-control" value="<?= e((string) ($page['meta_keywords'] ?? '')) ?>"></div>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
              <option value="draft" <?= ($page['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label small">Visibility</label>
            <select name="visibility" class="form-select form-select-sm">
              <?php foreach (['public' => 'Public', 'members' => 'Members only', 'private' => 'Private'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($page['visibility'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3"><label class="form-label">Banner image</label>
          <?php if (!empty($page['banner_image'])): ?><div class="mb-1"><img class="crud-thumb" src="<?= e(Url::media((string) $page['banner_image'])) ?>" alt=""></div><?php endif; ?>
          <input type="file" name="banner_image" class="form-control" accept="image/*">
        </div>
        <div class="mb-3"><label class="form-label">Featured image</label>
          <?php if (!empty($page['featured_image'])): ?><div class="mb-1"><img class="crud-thumb" src="<?= e(Url::media((string) $page['featured_image'])) ?>" alt=""></div><?php endif; ?>
          <input type="file" name="featured_image" class="form-control" accept="image/*">
        </div>
        <div class="mb-3"><label class="form-label">OG / social image</label>
          <?php if (!empty($page['og_image'])): ?><div class="mb-1"><img class="crud-thumb" src="<?= e(Url::media((string) $page['og_image'])) ?>" alt=""></div><?php endif; ?>
          <input type="file" name="og_image" class="form-control" accept="image/*">
        </div>
        <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control richtext" rows="12"><?= e((string) ($page['content'] ?? '')) ?></textarea></div>
        <div class="mb-3"><label class="form-label">Custom CSS</label><textarea name="custom_css" class="form-control" rows="3" placeholder=".my-section { }"><?= e((string) ($page['custom_css'] ?? '')) ?></textarea></div>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small">Canonical URL</label><input name="canonical_url" class="form-control form-control-sm" value="<?= e((string) ($page['canonical_url'] ?? '')) ?>"></div>
          <div class="col-3"><label class="form-label small">Robots</label>
            <select name="robots" class="form-select form-select-sm">
              <?php foreach (['index,follow' => 'Index', 'noindex,follow' => 'No index', 'noindex,nofollow' => 'No index / no follow'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($page['robots'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-3"><label class="form-label small">Order</label><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) ($page['sort_order'] ?? 0) ?>"></div>
        </div>
        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="show_in_nav" value="1" id="sn" <?= (int) ($page['show_in_nav'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="sn">Show in header navigation</label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="show_in_footer" value="1" id="sf" <?= (int) ($page['show_in_footer'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="sf">Show in footer links</label></div>
        <button class="btn btn-primary w-100"><?= $page ? 'Update page' : 'Create page' ?></button>
      </form>
      <?php if ($page): ?>
        <div class="d-flex gap-2 mt-2">
          <a class="btn btn-sm btn-outline-secondary flex-grow-1" target="_blank" rel="noopener" href="<?= e(Url::to((string) $page['slug'])) ?>">Preview</a>
          <?php if ((int) $page['is_system'] !== 1): ?>
            <form method="post" class="flex-grow-1" data-confirm="Delete this page and its sections?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $page['id'] ?>">
              <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($page): ?>
      <div class="panel p-3 p-md-4">
        <h2 class="h6 mb-3">Page sections</h2>
        <?php if (!$sections): ?><p class="small text-muted">No sections yet.</p><?php endif; ?>
        <?php foreach ($sections as $section): ?>
          <form method="post" enctype="multipart/form-data" class="border rounded p-2 mb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="section-save">
            <input type="hidden" name="page_id" value="<?= (int) $page['id'] ?>">
            <input type="hidden" name="section_id" value="<?= (int) $section['id'] ?>">
            <div class="row g-2">
              <div class="col-6"><input class="form-control form-control-sm" name="section_key" value="<?= e((string) $section['section_key']) ?>" placeholder="key"></div>
              <div class="col-3"><input class="form-control form-control-sm" type="number" name="section_sort" value="<?= (int) $section['sort_order'] ?>"></div>
              <div class="col-3"><button class="btn btn-sm btn-outline-secondary w-100">Save</button></div>
              <div class="col-12"><input class="form-control form-control-sm" name="section_title" value="<?= e((string) $section['title']) ?>" placeholder="Section title"></div>
              <div class="col-12"><input class="form-control form-control-sm" name="section_subtitle" value="<?= e((string) $section['subtitle']) ?>" placeholder="Subtitle"></div>
              <div class="col-12"><textarea class="form-control form-control-sm richtext" name="section_content" rows="6" placeholder="Content"><?= e((string) $section['content']) ?></textarea></div>
              <div class="col-12"><input type="file" name="section_image" class="form-control form-control-sm" accept="image/*"></div>
            </div>
          </form>
          <form method="post" class="text-end mb-3" data-confirm="Remove this section?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="section-delete">
            <input type="hidden" name="page_id" value="<?= (int) $page['id'] ?>">
            <input type="hidden" name="section_id" value="<?= (int) $section['id'] ?>">
            <button class="btn btn-sm btn-link text-danger p-0">Remove section</button>
          </form>
        <?php endforeach; ?>
        <hr>
        <h3 class="h6">Add section</h3>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="section-save">
          <input type="hidden" name="page_id" value="<?= (int) $page['id'] ?>">
          <div class="row g-2">
            <div class="col-6"><input class="form-control form-control-sm" name="section_key" placeholder="sec-hero" required></div>
            <div class="col-6"><input class="form-control form-control-sm" type="number" name="section_sort" value="<?= count($sections) + 1 ?>"></div>
            <div class="col-12"><input class="form-control form-control-sm" name="section_title" placeholder="Title"></div>
            <div class="col-12"><input class="form-control form-control-sm" name="section_subtitle" placeholder="Subtitle"></div>
            <div class="col-12"><textarea class="form-control form-control-sm richtext" name="section_content" rows="6" placeholder="Content"></textarea></div>
            <div class="col-12"><input type="file" name="section_image" class="form-control form-control-sm" accept="image/*"></div>
            <div class="col-12"><button class="btn btn-sm btn-primary w-100">Add section</button></div>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <div class="panel p-3 p-md-4">
      <form class="d-flex gap-2 mb-3" method="get">
        <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search pages">
        <button class="btn btn-sm btn-dark">Search</button>
      </form>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Nav</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td data-label="Title" class="small fw-semibold"><?= e((string) $row['title']) ?><?= (int) $row['is_system'] === 1 ? ' <span class="chip">system</span>' : '' ?></td>
              <td data-label="Slug" class="small text-muted">/<?= e((string) $row['slug']) ?></td>
              <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
              <td data-label="Nav" class="small"><?= (int) $row['show_in_nav'] === 1 ? 'Header' : '—' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-light-green" href="?id=<?= (int) $row['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(Url::to((string) $row['slug'])) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="small text-muted"><?= $pager->infoText() ?></div>
        <?= $pager->links(['q' => $search]) ?>
      </div>
    </div>
  </div>
</div>
<?php
$extraScripts = richtext_scripts('textarea.richtext', 460);
require __DIR__ . '/includes/layout-end.php';
