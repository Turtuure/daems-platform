/*
 * Default-site form-validation hooks (Wave I1 skeleton).
 *
 * Minimal client-side validation. Server still validates authoritatively.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var emailInput = document.getElementById('join-email');
    if (emailInput) {
      emailInput.addEventListener('blur', function () {
        var v = emailInput.value.trim();
        var ok = v === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        var row = emailInput.closest('.form-row');
        if (row) {
          row.classList.toggle('has-error', !ok);
        }
      });
    }
  });
})();
