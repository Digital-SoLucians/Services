// SLU Services: small progressive enhancements. Every page works without this file.
(function () {
  'use strict';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  };

  // Dismiss flash messages
  document.addEventListener('click', function (e) {
    var x = e.target.closest('.flash-x');
    if (x) x.closest('.flash').remove();
  });

  // Ask before destructive actions: <button data-confirm="Sure?">
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // Copy text: <button data-copy="#id">
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var src = $(b.getAttribute('data-copy'));
    if (!src) return;
    var text = (src.value || src.textContent || '').replace(/\s+/g, src.tagName === 'CODE' && src.id === 'totp-key' ? '' : ' ').trim();
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () {});
    else {
      var r = document.createRange(); r.selectNodeContents(src);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      try { document.execCommand('copy'); done(); } catch (err) { /* select is enough */ }
    }
  });

  // Submit filter forms on change
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) el.form.submit(); });
  });

  // Requirement checklists remember their ticks on this device
  $$('[data-checklist]').forEach(function (list) {
    var key = 'slu:' + list.getAttribute('data-checklist');
    var saved = [];
    try { saved = JSON.parse(store.get(key) || '[]') || []; } catch (e) { saved = []; }
    var boxes = $$('input[type=checkbox]', list);
    boxes.forEach(function (b) {
      if (saved.indexOf(b.getAttribute('data-i')) !== -1) b.checked = true;
      b.addEventListener('change', function () {
        store.set(key, JSON.stringify(boxes.filter(function (x) { return x.checked; }).map(function (x) { return x.getAttribute('data-i'); })));
      });
    });
  });

  // Tabs that switch between sibling panes: <nav data-tabs><a href="#pane">
  $$('[data-tabs]').forEach(function (nav) {
    var links = $$('a[href^="#"]', nav);
    var show = function (id) {
      links.forEach(function (a) {
        var on = a.getAttribute('href') === '#' + id;
        a.classList.toggle('on', on);
        a.setAttribute('aria-selected', on ? 'true' : 'false');
        var pane = document.getElementById(a.getAttribute('href').slice(1));
        if (pane) pane.hidden = !on;
      });
    };
    links.forEach(function (a) {
      a.setAttribute('role', 'tab');
      a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('href').slice(1)); });
    });
    var fromHash = location.hash && links.some(function (a) { return a.getAttribute('href') === location.hash; }) ? location.hash.slice(1) : null;
    var initial = fromHash || (links.filter(function (a) { return a.classList.contains('on'); })[0] || links[0]).getAttribute('href').slice(1);
    show(initial);
  });

  // Request form builder: add a question row
  $$('[data-add-row]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tbody = $('tbody', btn.closest('[data-request-builder]'));
      var last = tbody && tbody.lastElementChild;
      if (!last) return;
      var row = last.cloneNode(true);
      $$('input, select', row).forEach(function (el) {
        if (el.type === 'checkbox') { el.checked = false; el.value = String(tbody.children.length); }
        else if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = '';
      });
      tbody.appendChild(row);
      var first = $('input[name="rf_label[]"]', row);
      if (first) first.focus();
    });
  });

  // Service editor: the request form only matters for "apply here" services
  $$('[data-mode]').forEach(function (sel) {
    var builder = $('[data-request-builder]', sel.form || document);
    if (!builder) return;
    var sync = function () { builder.hidden = sel.value !== 'request'; };
    sel.addEventListener('change', sync);
    sync();
  });

  // ID form: passports have no back side
  $$('[data-id-type]').forEach(function (sel) {
    var form = sel.form || document;
    var back = $('[data-back]', form);
    if (!back) return;
    var input = $('input[type=file]', back);
    var sync = function () {
      var passport = sel.value === 'passport';
      back.hidden = passport;
      if (input) { input.required = !passport && sel.value !== ''; if (passport) input.value = ''; }
    };
    sel.addEventListener('change', sync);
    sync();
  });

  // Show the chosen file name on compact file pickers
  $$('.file-pick input[type=file]').forEach(function (input) {
    var label = $('span', input.closest('.file-pick'));
    var original = label ? label.textContent : '';
    input.addEventListener('change', function () {
      if (label) label.textContent = input.files && input.files[0] ? input.files[0].name : original;
    });
  });

  // Drop files on a dropzone
  $$('.dropzone').forEach(function (zone) {
    var input = $('input[type=file]', zone);
    if (!input) return;
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.remove('over'); }); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) {
        try { input.files = e.dataTransfer.files; } catch (err) { /* older browsers: use the picker */ }
        var strong = $('strong', zone);
        if (strong) strong.textContent = e.dataTransfer.files[0].name;
      }
    });
    input.addEventListener('change', function () {
      var strong = $('strong', zone);
      if (strong && input.files && input.files[0]) strong.textContent = input.files[0].name;
    });
  });

  // Close the account menu when clicking elsewhere or pressing Escape
  document.addEventListener('click', function (e) {
    $$('details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $$('details.menu[open]').forEach(function (d) { d.removeAttribute('open'); var s = $('summary', d); if (s) s.focus(); });
  });

  // Installable app (offline notice only); needs HTTPS
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    var base = ($('meta[name="base"]') || {}).content || '';
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(base.replace(/\/$/, '') + '/sw.js', { scope: base.replace(/\/?$/, '/') }).catch(function () {});
    });
  }
})();
