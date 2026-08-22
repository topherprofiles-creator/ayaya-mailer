<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/maps_scraper.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');
    if ($action === 'install_maps_scraper') {
        $result = maps_install_local_scraper();
        flash($result['ok'] ? $result['message'] : $result['error'], $result['ok'] ? 'success' : 'error');
        redirect('maps.php');
    }
    if ($action === 'save_maps_settings') {
        $url = rtrim(trim((string) post('maps_api_url')), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            flash('Enter a valid scraper API URL, for example http://127.0.0.1:8088.', 'error');
        } else {
            setting_set('maps_api_url', $url);
            flash('Google Maps scraper API URL saved.');
        }
        redirect('maps.php');
    }
}

function maps_page_value(array $job, string $key): string
{
    foreach ([$key, ucfirst($key), strtoupper($key)] as $candidate) {
        if (array_key_exists($candidate, $job)) {
            return trim((string) $job[$candidate]);
        }
    }
    foreach ($job as $candidate => $value) {
        if (strtolower((string) $candidate) === strtolower($key)) {
            return trim((string) $value);
        }
    }
    return '';
}

$health = maps_api_health();
$managedScraperInstalled = is_file(maps_managed_binary());
$jobsResult = maps_api_jobs();
$jobs = $jobsResult['jobs'];
$TOPBAR = '<a class="btn" href="leadfinder.php">Lead Finder</a><a class="btn" href="' . e(maps_api_url() . '/api/docs') . '" target="_blank" rel="noopener noreferrer">Scraper API docs</a>';

layout_header('Google Maps Leads', 'maps');
?>

<div id="maps-tool" data-api-url="<?= e(maps_api_url()) ?>">
  <?php if (!$health['ok']): ?>
    <div class="alert alert-error"><strong>Google Maps scraper is offline.</strong> <?= e($health['error'] ?: 'Install and start the local scraper below.') ?> <a href="#maps-setup">Set it up automatically</a>.</div>
  <?php else: ?>
    <div class="alert alert-success">Google Maps scraper connected at <code><?= e(maps_api_url()) ?></code>. Searches run only when you press Start scrape.</div>
  <?php endif; ?>

  <div class="split">
    <div>
      <div class="panel">
        <h2>Search Google Maps for prospects</h2>
        <p class="hint">Use focused Nigerian searches such as “new fintech startups in Lagos Nigeria” or “software companies in Abuja Nigeria”. The scraper extracts public websites and emails when available.</p>
        <form id="maps-start-form">
          <label class="field"><span>Search name</span><input type="text" name="name" value="Nigerian startup prospects" maxlength="120"></label>
          <label class="field"><span>Google Maps queries (one per line)</span><textarea name="keywords" rows="5" required>new Nigerian startups in Lagos Nigeria
new Nigerian startups in Abuja Nigeria</textarea></label>
          <div class="row">
            <label class="field"><span>Maximum run time</span><select name="max_time"><option value="300">5 minutes</option><option value="600" selected>10 minutes</option><option value="1800">30 minutes</option></select></label>
            <div class="field"><span>&nbsp;</span><label class="check"><input type="checkbox" name="email" checked disabled> Extract public website emails</label></div>
          </div>
          <button class="btn btn-primary" type="submit" <?= $health['ok'] ? '' : 'disabled' ?>>Start scrape</button>
          <span id="maps-start-status" class="hint" style="display:inline-block;margin:0 0 0 10px"></span>
        </form>
      </div>

      <div class="panel">
        <div class="flex-between">
          <div><h2>Scrape jobs</h2><p class="hint mb0">Refresh a job until it is complete, then import its email-bearing businesses into the Lead Finder review queue.</p></div>
          <button class="btn btn-sm" type="button" id="maps-refresh">Refresh jobs</button>
        </div>
        <div id="maps-jobs" class="table-wrap mt16">
          <?php if (!$jobs): ?>
            <div class="empty">No Google Maps jobs yet.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>Name</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($jobs as $job):
                $jobId = maps_page_value($job, 'id');
                $status = strtolower(maps_page_value($job, 'status'));
                $jobData = (array) ($job['Data'] ?? $job['data'] ?? []);
                $emailEnabled = (bool) ($jobData['email'] ?? $jobData['Email'] ?? false);
                $statusClass = $status === 'ok' ? 'badge-ok' : ($status === 'failed' ? 'badge-bad' : 'badge-warn');
              ?>
                <tr data-job-row="<?= e($jobId) ?>">
                  <td class="wrap"><strong><?= e(maps_page_value($job, 'name') ?: 'Google Maps search') ?></strong><br><span class="small muted mono"><?= e($jobId) ?></span></td>
                  <td><span class="badge <?= $statusClass ?>" data-job-status><?= e($status ?: 'unknown') ?></span></td>
                  <td><?= e(maps_page_value($job, 'date') ?: maps_page_value($job, 'created_at')) ?></td>
                  <td class="wrap">
                    <button class="btn btn-sm" type="button" data-maps-job="<?= e($jobId) ?>">Check</button>
                    <button class="btn btn-sm btn-primary" type="button" data-maps-import="<?= e($jobId) ?>" title="<?= $emailEnabled ? 'Import rows with public emails' : 'This job did not extract emails; start a new Ayaya scrape' ?>" <?= $status === 'ok' && $emailEnabled ? '' : 'disabled' ?>><?= $status === 'ok' && !$emailEnabled ? 'No email data' : 'Import leads' ?></button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <div id="maps-result" class="alert" style="display:none;margin-top:14px"></div>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h2>Import rules</h2>
        <p class="hint">Only rows containing a valid website and public email are imported. Existing email or website duplicates are skipped.</p>
        <div class="alert alert-warn small">Google Maps does not prove when a business launched. Imported leads remain unverified and cannot be approved for sending until you add dated launch evidence in the lead review screen.</div>
        <p class="small muted mb0">The scraper’s API and its results stay on this computer. Review every address and honor opt-out requests before sending.</p>
      </div>
      <div class="panel" id="maps-setup">
        <h2>Google Maps setup</h2>
        <p class="hint">On Windows, Ayaya can download the latest verified Google Maps scraper release into its private data folder and start it for you. No Git, Docker, or separate repository install is needed.</p>
        <form method="post" class="mb16">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="install_maps_scraper">
          <button class="btn btn-primary" type="submit"><?= $managedScraperInstalled ? 'Start scraper automatically' : 'Install and start scraper automatically' ?></button>
          <span class="small muted" style="margin-left:8px">Downloads about 60 MB the first time.</span>
        </form>
        <p class="small muted">The downloaded release is SHA-256 checked before Ayaya starts it. The scraper runs only on <code>127.0.0.1:8088</code> and its data stays under Ayaya’s ignored <code>data/</code> folder.</p>
        <details class="small">
          <summary>Manual or Docker setup</summary>
          <p>Use the free open-source <a href="https://github.com/gosom/google-maps-scraper" target="_blank" rel="noopener noreferrer">gosom/google-maps-scraper</a> project if automatic setup is unavailable:</p>
          <pre class="mono small" style="white-space:pre-wrap;margin:8px 0 14px">git clone https://github.com/gosom/google-maps-scraper.git
cd google-maps-scraper
google_maps_scraper.exe -web -addr 127.0.0.1:8088 -data-folder gmapsdata</pre>
          <p class="small mb0">Docker alternative:</p>
          <pre class="mono small" style="white-space:pre-wrap;margin:8px 0 14px">docker run --rm -p 127.0.0.1:8088:8080 \
  -v "${PWD}/gmapsdata:/gmapsdata" \
  gosom/google-maps-scraper -data-folder /gmapsdata</pre>
        </details>
      </div>
      <div class="panel">
        <h2>Next step</h2>
        <p class="hint">After importing, open <a href="leadfinder.php">Lead Finder</a>, review the source pages, and approve only businesses that meet your newly-launched Nigerian startup criteria.</p>
      </div>
      <div class="panel">
        <h2>Scraper connection</h2>
        <p class="hint">Change this only if you move the local scraper to another port or machine.</p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_maps_settings">
          <label class="field"><span>Google Maps scraper API URL</span><input type="url" name="maps_api_url" value="<?= e(maps_api_url()) ?>" required></label>
          <button class="btn btn-sm" type="submit">Save connection</button>
        </form>
      </div>
    </aside>
  </div>
</div>

<?php layout_footer(); ?>
