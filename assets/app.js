/* Ayaya Mailer - front-end. Vanilla JS, no build step. */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name=csrf]') || {}).content ||
    (document.querySelector('input[name=csrf]') || {}).value || '';

  function api(action, data) {
    var body = new FormData();
    body.append('csrf', CSRF);
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
    }).then(function (r) {
      return r.json().catch(function () {
        throw new Error('Server returned a non-JSON response (HTTP ' + r.status + ').');
      });
    });
  }

  /* --------------------------------------------- Google Maps lead importer */

  var mapsTool = document.getElementById('maps-tool');
  if (mapsTool) {
    var mapsNotice = document.getElementById('maps-result');
    var mapsStartStatus = document.getElementById('maps-start-status');

    function mapsShow(message, type) {
      if (!mapsNotice) return;
      mapsNotice.className = 'alert alert-' + (type || 'info');
      mapsNotice.textContent = message;
      mapsNotice.style.display = 'block';
    }

    function mapsStatus(row, status) {
      if (!row) return;
      status = String(status || 'unknown').toLowerCase();
      var badge = row.querySelector('[data-job-status]');
      var importBtn = row.querySelector('[data-maps-import]');
      if (badge) {
        badge.className = 'badge ' + (status === 'ok' ? 'badge-ok' : (status === 'failed' ? 'badge-bad' : 'badge-warn'));
        badge.textContent = status;
      }
      if (importBtn) importBtn.disabled = status !== 'ok';
    }

    function mapsCheck(jobId) {
      var row = document.querySelector('[data-job-row="' + CSS.escape(jobId) + '"]');
      return api('maps_job', { job_id: jobId }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not read scraper job.');
        var job = res.job || {};
        mapsStatus(row, job.Status || job.status);
        return job;
      });
    }

    var mapsStartForm = document.getElementById('maps-start-form');
    if (mapsStartForm) mapsStartForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var button = mapsStartForm.querySelector('button[type=submit]');
      var data = new FormData(mapsStartForm);
      if (button) { button.disabled = true; button.textContent = 'Starting...'; }
      if (mapsStartStatus) mapsStartStatus.textContent = 'Creating a scraper job...';
      api('maps_start', {
        name: data.get('name') || '',
        keywords: data.get('keywords') || '',
        max_time: data.get('max_time') || '600'
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not start the scraper.');
        if (mapsStartStatus) mapsStartStatus.textContent = 'Job started: ' + res.job_id;
        window.setTimeout(function () { window.location.reload(); }, 500);
      }).catch(function (err) {
        if (mapsStartStatus) mapsStartStatus.textContent = err.message;
        if (button) { button.disabled = false; button.textContent = 'Start scrape'; }
      });
    });

    document.querySelectorAll('[data-maps-job]').forEach(function (button) {
      button.addEventListener('click', function () {
        var jobId = button.getAttribute('data-maps-job');
        button.disabled = true;
        button.textContent = 'Checking...';
        mapsCheck(jobId).catch(function (err) { mapsShow(err.message, 'error'); })
          .then(function () { button.disabled = false; button.textContent = 'Check'; });
      });
    });

    document.querySelectorAll('[data-maps-import]').forEach(function (button) {
      button.addEventListener('click', function () {
        var jobId = button.getAttribute('data-maps-import');
        if (!window.confirm('Import public-email businesses from this completed Google Maps job into the Lead Finder review queue?')) return;
        button.disabled = true;
        button.textContent = 'Importing...';
        api('maps_import', { job_id: jobId }).then(function (res) {
          if (!res.ok) throw new Error(res.error || 'Import failed.');
          mapsShow('Imported ' + res.added + ' lead(s); skipped ' + res.skipped + ' row(s) (' + (res.missing_email || 0) + ' without public email, ' + (res.missing_website || 0) + ' without website) and ' + res.duplicates + ' duplicate(s).', 'success');
          button.textContent = 'Imported';
        }).catch(function (err) {
          mapsShow(err.message, 'error');
          button.disabled = false;
          button.textContent = 'Import leads';
        });
      });
    });

    var refreshMaps = document.getElementById('maps-refresh');
    if (refreshMaps) refreshMaps.addEventListener('click', function () { window.location.reload(); });

    // Keep pending jobs current without starting or importing anything.
    window.setInterval(function () {
      document.querySelectorAll('[data-job-row]').forEach(function (row) {
        var badge = row.querySelector('[data-job-status]');
        var status = badge ? badge.textContent.toLowerCase() : '';
        if (status === 'pending' || status === 'working') {
          mapsCheck(row.getAttribute('data-job-row')).catch(function () {});
        }
      });
    }, 10000);
  }

  /* ------------------------------------------------ generic behaviours */

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  });

  document.querySelectorAll('.pill input[type=checkbox], .pill input[type=radio]').forEach(function (input) {
    var sync = function () {
      var name = input.name;
      if (input.type === 'radio' && name) {
        document.querySelectorAll('input[name="' + name + '"]').forEach(function (other) {
          other.closest('.pill').classList.toggle('checked', other.checked);
        });
      } else {
        input.closest('.pill').classList.toggle('checked', input.checked);
      }
    };
    input.addEventListener('change', sync);
    sync();
  });

  /* ------------------------------------------------------- SMTP tester */

  document.querySelectorAll('[data-test-smtp]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-test-smtp');
      var out = document.getElementById('smtp-test-result');
      var to = (document.getElementById('test-to') || {}).value || '';
      var label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Testing...';
      if (out) { out.className = 'alert alert-info'; out.textContent = 'Connecting to the SMTP server...'; out.style.display = 'block'; }

      api('test_smtp', { id: id, test_to: to }).then(function (res) {
        if (out) {
          out.className = 'alert ' + (res.ok ? 'alert-success' : 'alert-error');
          out.textContent = res.message || (res.ok ? 'OK' : 'Failed');
          if (res.log) {
            var pre = document.createElement('pre');
            pre.className = 'mono small';
            pre.style.cssText = 'white-space:pre-wrap;margin:10px 0 0;max-height:220px;overflow:auto;opacity:.85';
            pre.textContent = res.log;
            out.appendChild(pre);
          }
        }
        var dot = document.querySelector('[data-smtp-status="' + id + '"]');
        if (dot) {
          dot.className = 'badge ' + (res.ok ? 'badge-ok' : 'badge-bad');
          dot.textContent = res.ok ? 'working' : 'failed';
        }
      }).catch(function (err) {
        if (out) { out.className = 'alert alert-error'; out.textContent = err.message; }
      }).then(function () {
        btn.disabled = false;
        btn.textContent = label;
      });
    });
  });

  document.querySelectorAll('[data-test-imap]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-test-imap');
      var out = document.getElementById('smtp-test-result');
      var label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Testing...';
      if (out) { out.className = 'alert alert-info'; out.textContent = 'Connecting to the inbox...'; out.style.display = 'block'; }
      api('test_imap', { id: id }).then(function (res) {
        if (out) { out.className = 'alert ' + (res.ok ? 'alert-success' : 'alert-error'); out.textContent = res.message; }
        var dot = document.querySelector('[data-imap-status="' + id + '"]');
        if (dot) { dot.className = 'badge ' + (res.ok ? 'badge-ok' : 'badge-bad'); dot.textContent = res.ok ? 'IMAP ready' : 'IMAP failed'; }
      }).catch(function (err) {
        if (out) { out.className = 'alert alert-error'; out.textContent = err.message; }
      }).then(function () { btn.disabled = false; btn.textContent = label; });
    });
  });

  /* ---------------------------------------------------------- send run */

  var runner = document.getElementById('send-runner');
  if (runner) {
    var campaignId = runner.getAttribute('data-campaign');
    var startBtn = document.getElementById('btn-start');
    var pauseBtn = document.getElementById('btn-pause');
    var bar = document.querySelector('#send-progress > div');
    var elSent = document.getElementById('stat-sent');
    var elFailed = document.getElementById('stat-failed');
    var elLeft = document.getElementById('stat-left');
    var elPct = document.getElementById('stat-pct');
    var consoleBox = document.getElementById('send-console');
    var running = false;

    function log(text, cls) {
      var line = document.createElement('div');
      var time = document.createElement('span');
      time.className = 't';
      time.textContent = new Date().toLocaleTimeString();
      var msg = document.createElement('span');
      msg.className = 'l-' + (cls || 'info');
      msg.textContent = text;
      line.appendChild(time);
      line.appendChild(msg);
      consoleBox.appendChild(line);
      consoleBox.scrollTop = consoleBox.scrollHeight;
    }

    function paint(p) {
      var total = p.total || 0;
      var done = (p.sent || 0) + (p.failed || 0);
      var pct = total ? Math.round((done / total) * 100) : 0;
      bar.style.width = pct + '%';
      elSent.textContent = p.sent || 0;
      elFailed.textContent = p.failed || 0;
      elLeft.textContent = Math.max(total - done, 0);
      elPct.textContent = pct + '%';
    }

    function setStatus(status) {
      var badge = document.getElementById('status-badge');
      if (!badge || !status) return;
      var map = { draft: 'muted', running: 'accent', paused: 'warn', done: 'ok' };
      badge.className = 'badge badge-' + (map[status] || 'muted');
      badge.textContent = status;
    }

    function setRunning(on) {
      running = on;
      startBtn.disabled = on;
      pauseBtn.disabled = !on;
      startBtn.textContent = on ? 'Sending...' : 'Start sending';
    }

    function loop() {
      if (!running) return;
      api('send_batch', { campaign_id: campaignId }).then(function (res) {
        (res.results || []).forEach(function (r) {
          if (r.ok) {
            log('SENT  ' + r.email + '   [' + r.smtp + ']', 'ok');
          } else {
            log('FAIL  ' + r.email + '   ' + r.error, 'bad');
          }
        });
        if (res.progress) { paint(res.progress); }
        setStatus(res.status);

        if (!res.ok) {
          log(res.error || 'Unknown error', 'bad');
          log('Remaining recipients are still queued - fix the problem and press Resume.', 'info');
          setRunning(false);
          return;
        }

        if (res.status === 'paused') {
          log('Paused.', 'info');
          setRunning(false);
          return;
        }
        if (res.done) {
          log('Campaign finished. ' + res.progress.sent + ' sent, ' + res.progress.failed + ' failed.', 'ok');
          setRunning(false);
          pauseBtn.disabled = true;
          return;
        }
        setTimeout(loop, 150);
      }).catch(function (err) {
        log('Request failed: ' + err.message, 'bad');
        setRunning(false);
      });
    }

    startBtn.addEventListener('click', function () {
      api('start_campaign', { campaign_id: campaignId }).then(function (res) {
        if (!res.ok) { log(res.error || 'Could not start.', 'bad'); return; }
        log('Started. ' + res.progress.total + ' recipients queued.', 'info');
        paint(res.progress);
        setStatus('running');
        setRunning(true);
        loop();
      }).catch(function (err) { log(err.message, 'bad'); });
    });

    pauseBtn.addEventListener('click', function () {
      running = false;
      api('pause_campaign', { campaign_id: campaignId }).then(function () {
        log('Pause requested - finishing current batch.', 'info');
        setStatus('paused');
        setRunning(false);
      });
    });
  }

  /* --------------------------------------------------- compose preview */

  var previewBtn = document.getElementById('btn-preview');
  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      var box = document.getElementById('preview-box');
      var body = document.querySelector('[name=body]').value;
      var isHtml = document.querySelector('[name=is_html]').value === '1';
      var sample = { email: 'sample@example.com', name: 'Sample Name' };
      var out = body
        .replace(/\{\{email\}\}/g, sample.email)
        .replace(/\{\{name\}\}/g, sample.name)
        .replace(/\{\{first_name\}\}/g, 'Sample')
        .replace(/\{\{date\}\}/g, new Date().toISOString().slice(0, 10));
      box.style.display = 'block';
      if (isHtml) {
        box.innerHTML = '<div style="background:#fff;color:#111;padding:16px;border-radius:8px">' + out + '</div>';
      } else {
        box.textContent = out;
      }
    });
  }

  /* --------------------------------------- file picker label niceties */

  document.querySelectorAll('input[type=file][data-label]').forEach(function (input) {
    input.addEventListener('change', function () {
      var target = document.getElementById(input.getAttribute('data-label'));
      if (!target) return;
      target.textContent = input.files.length
        ? Array.prototype.map.call(input.files, function (f) { return f.name; }).join(', ')
        : '';
    });
  });
})();
