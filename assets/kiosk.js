(function () {
  'use strict';

  var CFG = window.KIOSK || { now: Date.now() / 1000, tz: 'UTC', csrf: '' };
  var offset = CFG.now - Date.now() / 1000; // add to local epoch to get true UTC epoch

  function correctedEpoch() { return Date.now() / 1000 + offset; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmtHM(secs) {
    if (secs < 0) secs = 0;
    var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60);
    return h + 'h ' + pad(m) + 'm';
  }

  // ---- live wall clock (shown in the configured timezone) ----
  var clockEl = document.getElementById('clock');
  var clockFmt;
  try {
    clockFmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: CFG.tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    });
  } catch (e) { clockFmt = null; }

  function tickClock() {
    if (!clockEl) return;
    var d = new Date(correctedEpoch() * 1000);
    clockEl.textContent = clockFmt ? clockFmt.format(d) : (pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()));
  }
  tickClock();
  setInterval(tickClock, 1000);

  // ---- per-person elapsed timers ----
  function refreshElapsed() {
    var tiles = document.querySelectorAll('.tile.on');
    for (var i = 0; i < tiles.length; i++) {
      var since = parseInt(tiles[i].getAttribute('data-since'), 10);
      var out = tiles[i].querySelector('[data-elapsed]');
      if (out && since) out.textContent = fmtHM(correctedEpoch() - since);
    }
  }
  function updateOnCount() {
    var el = document.getElementById('oncount');
    if (!el) return;
    var n = document.querySelectorAll('.tile[data-status="on"]').length;
    el.textContent = n;
    var wrap = el.closest('.k-onnow');
    if (wrap) wrap.classList.toggle('live', n > 0);
  }

  refreshElapsed();
  setInterval(refreshElapsed, 20000);

  // ---- overlays ----
  var pinOverlay = document.getElementById('pin-overlay');
  var brandOverlay = document.getElementById('brand-overlay');
  var resultOverlay = document.getElementById('result-overlay');
  var pinName = document.getElementById('pin-name');
  var pinMsg = document.getElementById('pin-msg');
  var pinDots = document.getElementById('pin-dots').querySelectorAll('span');
  var brandName = document.getElementById('brand-name');
  var brandChoices = document.getElementById('brand-choices');

  var current = null;   // { tile, id, name, brands }
  var pin = '';
  var busy = false;
  var resultTimer = null;

  function show(el) { el.hidden = false; }
  function hide(el) { el.hidden = true; }

  function renderDots() {
    for (var i = 0; i < pinDots.length; i++) {
      pinDots[i].className = i < pin.length ? 'filled' : '';
    }
  }

  function openPin(tile) {
    current = {
      tile: tile,
      id: tile.getAttribute('data-id'),
      name: tile.getAttribute('data-name'),
      brands: JSON.parse(tile.getAttribute('data-brands') || '[]')
    };
    pin = '';
    renderDots();
    pinMsg.textContent = '';
    pinName.textContent = current.name;
    hide(brandOverlay);
    show(pinOverlay);
  }

  function closeAll() {
    hide(pinOverlay); hide(brandOverlay); hide(resultOverlay);
    pin = ''; busy = false; current = null;
    if (resultTimer) { clearTimeout(resultTimer); resultTimer = null; }
  }

  function pushDigit(d) {
    if (busy || pin.length >= 4) return;
    pin += d;
    renderDots();
    if (pin.length === 4) submit(null);
  }

  function back() { if (!busy && pin.length) { pin = pin.slice(0, -1); renderDots(); } }

  function shake(el) {
    el.classList.remove('shake');
    void el.offsetWidth;
    el.classList.add('shake');
  }

  function submit(brandId) {
    if (busy || !current) return;
    busy = true;
    pinMsg.textContent = '';

    var body = 'staff_id=' + encodeURIComponent(current.id) +
               '&pin=' + encodeURIComponent(pin) +
               '&csrf=' + encodeURIComponent(CFG.csrf);
    if (brandId != null) body += '&brand_id=' + encodeURIComponent(brandId);

    fetch('clock.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          busy = false;
          pin = '';
          renderDots();
          pinMsg.textContent = data.error || 'Something went wrong.';
          shake(document.querySelector('#pin-overlay .sheet'));
          return;
        }
        if (data.need_brand) {
          busy = false; // keep pin in memory for the brand step
          showBrands(data);
          return;
        }
        showResult(data);
      })
      .catch(function () {
        busy = false;
        pin = '';
        renderDots();
        pinMsg.textContent = 'Network problem. Try again.';
      });
  }

  function showBrands(data) {
    brandName.textContent = data.name || current.name;
    brandChoices.innerHTML = '';
    (data.brands || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.className = 'brand-btn';
      btn.textContent = b.name;
      btn.addEventListener('click', function () { busy || submit(b.id); });
      brandChoices.appendChild(btn);
    });
    hide(pinOverlay);
    show(brandOverlay);
  }

  function showResult(data) {
    var mark = document.getElementById('result-mark');
    var big = document.getElementById('result-big');
    var sub = document.getElementById('result-sub');
    var goingIn = data.state === 'in';

    resultOverlay.className = 'overlay result ' + (goingIn ? 'is-in' : 'is-out');
    mark.textContent = '✓';
    big.textContent = goingIn ? 'On shift' : 'Shift ended';

    var line = data.name + ' · ' + (goingIn ? 'in at ' : 'out at ') + data.time;
    if (goingIn && data.brand) line += ' · ' + data.brand;
    if (!goingIn && data.worked) line += '  —  worked ' + data.worked;
    sub.textContent = line;

    // update the tile in place
    if (current && current.tile) {
      var t = current.tile;
      if (goingIn) {
        t.setAttribute('data-status', 'on');
        t.setAttribute('data-since', String(data.since || Math.round(correctedEpoch())));
        t.className = 'tile on';
        var st = t.querySelector('.tile-state-text'); if (st) st.textContent = 'On shift';
      } else {
        t.setAttribute('data-status', 'off');
        t.setAttribute('data-since', '');
        t.className = 'tile off';
        var st2 = t.querySelector('.tile-state-text'); if (st2) st2.textContent = 'Off';
        var el = t.querySelector('[data-elapsed]'); if (el) el.textContent = '';
      }
    }

    updateOnCount();

    hide(pinOverlay); hide(brandOverlay);
    show(resultOverlay);
    refreshElapsed();
    resultTimer = setTimeout(closeAll, 3500);
  }

  // ---- wiring ----
  var tiles = document.querySelectorAll('.tile');
  for (var i = 0; i < tiles.length; i++) {
    tiles[i].addEventListener('click', function () { openPin(this); });
  }

  document.querySelector('.keypad').addEventListener('click', function (ev) {
    var t = ev.target;
    if (t.hasAttribute('data-key')) pushDigit(t.getAttribute('data-key'));
    else if (t.hasAttribute('data-back')) back();
  });

  var cancels = document.querySelectorAll('[data-cancel]');
  for (var c = 0; c < cancels.length; c++) {
    cancels[c].addEventListener('click', closeAll);
  }

  // tap the dark background to dismiss
  pinOverlay.addEventListener('click', function (e) { if (e.target === pinOverlay) closeAll(); });
  brandOverlay.addEventListener('click', function (e) { if (e.target === brandOverlay) closeAll(); });
  resultOverlay.addEventListener('click', closeAll);

  // physical keyboard (handy for setup/testing)
  document.addEventListener('keydown', function (e) {
    if (pinOverlay.hidden) return;
    if (e.key >= '0' && e.key <= '9') pushDigit(e.key);
    else if (e.key === 'Backspace') back();
    else if (e.key === 'Escape') closeAll();
  });
})();
