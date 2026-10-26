/* Identity upload: a friendly pre-check (type and size) and previews. The server checks the real file content again. */
(function () {
  'use strict';
  var form = document.getElementById('vfy-form');
  if (!form) { return; }
  var MAX = 5 * 1024 * 1024;
  var NAMES = ['ID photo', 'selfie'];
  var ok = [false, false];
  var submit = document.getElementById('vfy-submit');
  var confirmBox = document.getElementById('vfy-confirm');
  var hint = document.getElementById('vfy-hint');

  function sync() {
    var ready = ok[0] && ok[1] && confirmBox.checked;
    submit.disabled = !ready;
    hint.hidden = ready;
  }

  /* JPEG and PNG start with fixed bytes: catches a renamed file before it is uploaded. */
  function looksLikeImage(file, cb) {
    var r = new FileReader();
    r.onload = function () {
      var b = new Uint8Array(r.result);
      var jpeg = b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF;
      var png = b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47;
      cb(jpeg || png);
    };
    r.onerror = function () { cb(false); };
    r.readAsArrayBuffer(file.slice(0, 4));
  }

  form.querySelectorAll('input[type=file]').forEach(function (input) {
    var i = +input.getAttribute('data-slot');
    var drop = document.getElementById('vfy-drop-' + i);
    var msg = document.getElementById('vfy-msg-' + i);
    var img = drop.querySelector('img');

    function fail(text) {
      msg.textContent = text; msg.hidden = false;
      drop.classList.add('is-bad'); drop.classList.remove('has-photo');
      img.hidden = true; input.value = ''; ok[i] = false; sync();
    }

    input.addEventListener('change', function () {
      msg.hidden = true; drop.classList.remove('is-bad');
      var f = input.files[0];
      if (!f) { ok[i] = false; drop.classList.remove('has-photo'); img.hidden = true; sync(); return; }
      if (['image/jpeg', 'image/png'].indexOf(f.type) === -1) { fail('Only JPEG or PNG photos are accepted.'); return; }
      if (f.size > MAX) { fail('This ' + NAMES[i] + ' is over 5 MB. Choose a smaller one.'); return; }
      looksLikeImage(f, function (good) {
        if (!good) { fail('Only JPEG or PNG photos are accepted.'); return; }
        if (img.dataset.url) { URL.revokeObjectURL(img.dataset.url); }
        img.dataset.url = URL.createObjectURL(f);
        img.src = img.dataset.url; img.alt = 'Preview of your ' + NAMES[i]; img.hidden = false;
        drop.classList.add('has-photo'); ok[i] = true; sync();
      });
    });
  });

  confirmBox.addEventListener('change', sync);
  form.addEventListener('submit', function (e) {
    if (submit.disabled) { e.preventDefault(); return; }
    submit.disabled = true; submit.setAttribute('aria-busy', 'true');
  });
  sync();
})();
