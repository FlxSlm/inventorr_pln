// public/assets/js/main.js
// Utility JS for simple UI interactions: confirm dialogs, form helpers, request_loan dynamic text

document.addEventListener('DOMContentLoaded', function () {
  // Confirm delete links (non-form elements) that have data-confirm attribute
  document.querySelectorAll('a[data-confirm], button[data-confirm]:not([type="submit"])').forEach(function (el) {
    el.addEventListener('click', function (e) {
      const message = el.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });

  // Confirm forms (for approve/reject forms) - only for forms, not buttons inside
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      const message = form.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });

  // If there is a request loan page: update stock display when option selected
  const invSelect = document.querySelector('select[name="inventory_id"]');
  if (invSelect) {
    const stockDisplay = document.createElement('div');
    stockDisplay.className = 'small-muted mt-2';
    invSelect.parentNode.appendChild(stockDisplay);

    function updateStockInfo() {
      const opt = invSelect.options[invSelect.selectedIndex];
      const available = opt ? opt.getAttribute('data-available') : null;
      if (available !== null) {
        stockDisplay.textContent = 'Stok tersedia: ' + available;
      } else {
        stockDisplay.textContent = '';
      }
    }

    invSelect.addEventListener('change', updateStockInfo);
    updateStockInfo();
  }

  // Simple client-side validation for login/register if needed
  const loginForm = document.getElementById('loginForm');
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      const email = loginForm.querySelector('input[name="email"]').value.trim();
      const pass = loginForm.querySelector('input[name="password"]').value;
      if (!email || !pass) {
        alert('Isi semua field login.');
        e.preventDefault();
      }
    });
  }
});
