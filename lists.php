<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

/* ------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'upload') {
        $name = trim((string) post('name'));
        $raw  = '';
        $srcNames = [];

        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
                if (!is_uploaded_file($tmp)) {
                    continue;
                }
                $orig = (string) $_FILES['files']['name'][$i];
                $ext  = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, ['txt', 'csv', 'text', ''], true)) {
                    flash('Only .txt or .csv files are accepted (' . e($orig) . ' was skipped).', 'warn');
                    continue;
                }
                $raw .= "\n" . (string) file_get_contents($tmp);
                $srcNames[] = $orig;

                // Keep the original upload so the source can be re-checked later.
                $stored = date('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                @move_uploaded_file($tmp, AYAYA_UPLOADS . '/lists/' . $stored);
            }
        }

        $pasted = trim((string) post('pasted'));
        if ($pasted !== '') {
            $raw .= "\n" . $pasted;
            $srcNames[] = 'pasted';
        }

        if (trim($raw) === '') {
            flash('Nothing to import - upload a .txt file or paste some addresses.', 'error');
            redirect('lists.php');
        }

        $parsed = parse_recipients($raw);
        if (!$parsed['contacts']) {
            flash('No valid email addresses found in that input.', 'error');
            redirect('lists.php');
        }

        if ($name === '') {
            $name = $srcNames ? pathinfo((string) $srcNames[0], PATHINFO_FILENAME) : 'List ' . date('Y-m-d H:i');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO mail_lists (name, source_file, total, invalid, duplicates) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, implode(', ', $srcNames), count($parsed['contacts']), count($parsed['invalid']), $parsed['duplicates']]);
        $listId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO list_contacts (list_id, email, name, extra) VALUES (?,?,?,?)');
        foreach ($parsed['contacts'] as $c) {
            $ins->execute([$listId, $c['email'], $c['name'], $c['extra']]);
        }
        $pdo->commit();

        $msg = 'Imported <strong>' . number_format(count($parsed['contacts'])) . '</strong> contacts into <strong>' . e($name) . '</strong>.';
        if ($parsed['duplicates']) {
            $msg .= ' ' . $parsed['duplicates'] . ' duplicate' . ($parsed['duplicates'] === 1 ? '' : 's') . ' removed.';
        }
        if ($parsed['invalid']) {
            $msg .= ' ' . count($parsed['invalid']) . ' invalid line' . (count($parsed['invalid']) === 1 ? '' : 's') . ' skipped.';
        }
        flash($msg);
        redirect('lists.php?view=' . $listId);
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM mail_lists WHERE id = ?');
        $stmt->execute([(int) post('id')]);
        flash('List deleted.');
        redirect('lists.php');
    }

    if ($action === 'rename') {
        $stmt = $pdo->prepare('UPDATE mail_lists SET name = ? WHERE id = ?');
        $stmt->execute([trim((string) post('name')), (int) post('id')]);
        flash('List renamed.');
        redirect('lists.php?view=' . (int) post('id'));
    }
}

/* ----------------------------------------------------- single list view */

$viewId = (int) query('view', 0);
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mail_lists WHERE id = ?');
    $stmt->execute([$viewId]);
    $list = $stmt->fetch();
    if (!$list) {
        flash('That list no longer exists.', 'error');
        redirect('lists.php');
    }

    $perPage = 100;
    $page    = max(1, (int) query('page', 1));
    $offset  = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT * FROM list_contacts WHERE list_id = ? ORDER BY id LIMIT ? OFFSET ?');
    $stmt->execute([$viewId, $perPage, $offset]);
    $contacts = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM list_contacts WHERE list_id = ?');
    $stmt->execute([$viewId]);
    $count = (int) $stmt->fetch()['c'];
    $pages = max(1, (int) ceil($count / $perPage));

    $TOPBAR = '<a class="btn" href="lists.php">All lists</a>'
        . '<a class="btn btn-primary" href="campaign.php?list_id=' . $viewId . '">Mail this list</a>';

    layout_header($list['name'], 'lists');
    ?>
    <div class="grid grid-4" style="margin-bottom:20px">
      <div class="stat accent"><div class="label">Contacts</div><div class="value"><?= number_format($count) ?></div></div>
      <div class="stat"><div class="label">Duplicates removed</div><div class="value"><?= (int) $list['duplicates'] ?></div></div>
      <div class="stat"><div class="label">Invalid skipped</div><div class="value"><?= (int) $list['invalid'] ?></div></div>
      <div class="stat"><div class="label">Imported</div><div class="value small" style="font-size:15px;padding-top:8px"><?= e(human_time($list['created_at'])) ?></div></div>
    </div>

    <div class="panel">
      <div class="flex-between" style="margin-bottom:14px">
        <form method="post" class="row" style="align-items:flex-end;gap:8px;margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="id" value="<?= $viewId ?>">
          <label class="field mb0" style="flex:1 1 220px">
            <span>List name</span>
            <input type="text" name="name" value="<?= e($list['name']) ?>">
          </label>
          <button class="btn" type="submit">Rename</button>
        </form>
        <form method="post" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $viewId ?>">
          <button class="btn btn-danger" type="submit" data-confirm="Delete this list and all its contacts?">Delete list</button>
        </form>
      </div>

      <div class="table-wrap">
        <table>
          <thead><tr><th style="width:60px">#</th><th>Email</th><th>Name</th><th>Extra</th></tr></thead>
          <tbody>
          <?php foreach ($contacts as $i => $c): ?>
            <tr>
              <td class="muted mono"><?= $offset + $i + 1 ?></td>
              <td class="mono"><?= e($c['email']) ?></td>
              <td><?= e($c['name']) ?></td>
              <td class="muted small"><?= e($c['extra']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="flex-between mt16">
          <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
          <div class="row" style="flex:0">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="lists.php?view=<?= $viewId ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="lists.php?view=<?= $viewId ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
    layout_footer();
    exit;
}

/* ------------------------------------------------------------- index */

$lists = $pdo->query('SELECT * FROM mail_lists ORDER BY id DESC')->fetchAll();

layout_header('Mail Lists', 'lists');
?>

<div class="split">
  <div class="panel">
    <h2>Your lists</h2>
    <p class="hint">Each import is stored as its own list, ready to attach to a campaign.</p>

    <?php if (!$lists): ?>
      <div class="empty">No lists yet. Upload a <code>.txt</code> file to get started.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>List</th><th>Contacts</th><th>Source</th><th>Imported</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lists as $l): ?>
            <tr>
              <td><a href="lists.php?view=<?= (int) $l['id'] ?>"><strong><?= e($l['name']) ?></strong></a></td>
              <td class="mono"><?= number_format((int) $l['total']) ?></td>
              <td class="muted small wrap"><?= e(mb_strimwidth((string) $l['source_file'], 0, 40, '...')) ?></td>
              <td class="muted small"><?= e(human_time($l['created_at'])) ?></td>
              <td class="right">
                <a class="btn btn-sm" href="lists.php?view=<?= (int) $l['id'] ?>">Open</a>
                <a class="btn btn-sm btn-primary" href="campaign.php?list_id=<?= (int) $l['id'] ?>">Mail</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete the list &quot;<?= e($l['name']) ?>&quot;?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Import recipients</h2>
    <p class="hint">One address per line. Duplicates and invalid lines are dropped automatically.</p>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">

      <label class="field">
        <span>List name (optional)</span>
        <input type="text" name="name" placeholder="Newsletter August">
      </label>

      <label class="field">
        <span>.txt files (you can pick several)</span>
        <input type="file" name="files[]" accept=".txt,.csv,text/plain" multiple data-label="file-names">
        <span id="file-names" class="small muted"></span>
      </label>

      <label class="field">
        <span>...or paste addresses</span>
        <textarea name="pasted" class="code" rows="6" placeholder="john@example.com&#10;jane@example.com,Jane Doe&#10;Bob Smith &lt;bob@example.com&gt;"></textarea>
      </label>

      <button class="btn btn-primary" type="submit">Import list</button>
    </form>

    <div class="alert alert-info mt16 small mb0">
      <strong>Accepted line formats</strong><br>
      <code>john@example.com</code><br>
      <code>john@example.com,John Doe</code><br>
      <code>john@example.com;John;VIP</code><br>
      <code>John Doe &lt;john@example.com&gt;</code><br>
      Lines starting with <code>#</code> are ignored.
    </div>
  </div>
</div>

<?php layout_footer(); ?>
