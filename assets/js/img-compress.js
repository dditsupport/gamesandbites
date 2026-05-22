/*
 * Client-side image compression.
 * Any <input type="file" class="js-compress"> will have its selected image
 * resized + re-encoded to JPEG in the browser before the form is submitted,
 * cutting upload size and server storage. Falls back to the original file if
 * the browser lacks support or the result wouldn't be smaller.
 */
(function () {
  var MAX_DIM = 1600;   // longest edge in pixels
  var QUALITY = 0.82;   // JPEG quality (0-1)

  // Bail out gracefully on browsers without the needed APIs.
  var supported = typeof DataTransfer !== 'undefined'
    && typeof File !== 'undefined'
    && !!document.createElement('canvas').toBlob;

  function compress(file) {
    return new Promise(function (resolve) {
      if (!supported || !file || file.type.indexOf('image/') !== 0 || file.type === 'image/gif') {
        resolve(file);
        return;
      }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight;
        if (!w || !h) { resolve(file); return; }
        var scale = Math.min(1, MAX_DIM / Math.max(w, h));
        w = Math.round(w * scale);
        h = Math.round(h * scale);
        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        canvas.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { resolve(file); return; } // keep original if not smaller
          var name = file.name.replace(/\.(png|webp|jpe?g|bmp)$/i, '') + '.jpg';
          try {
            resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
          } catch (e) {
            resolve(file);
          }
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function wire(input) {
    input.addEventListener('change', function () {
      if (!input.files || !input.files.length) return;
      var original = input.files[0];
      var form = input.form;
      var btns = form ? form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]') : [];
      var i;
      for (i = 0; i < btns.length; i++) btns[i].disabled = true;
      compress(original).then(function (out) {
        if (out && out !== original) {
          try {
            var dt = new DataTransfer();
            dt.items.add(out);
            input.files = dt.files;
          } catch (e) { /* keep original selection */ }
        }
      }).finally(function () {
        for (i = 0; i < btns.length; i++) btns[i].disabled = false;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var inputs = document.querySelectorAll('input[type=file].js-compress');
    for (var i = 0; i < inputs.length; i++) wire(inputs[i]);
  });
})();
