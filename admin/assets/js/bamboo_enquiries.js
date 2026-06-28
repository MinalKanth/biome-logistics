(function() {

    console.log("bamboo_enquiries.js loaded");
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }

    /* ---------- Modal open/close helpers ---------- */
    function openModal(modal) {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.js-modal-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = document.getElementById(btn.dataset.target);
            if (target) closeModal(target);
        });
    });

    // Click on the dark backdrop (but not the box itself) closes the modal
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal(overlay);
        });
    });

    // Escape key closes whichever modal is open
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
        }
    });

    /* ---------- VIEW modal: populate from the clicked row's data-* ---------- */
    const viewModal = document.getElementById('viewModal');
    document.querySelectorAll('.js-view-enquiry').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const d = btn.dataset;

            document.getElementById('viewModalId').textContent = '#' + (d.id || '—');
            document.getElementById('viewName').textContent = d.name || '—';
            document.getElementById('viewMobile').textContent = d.mobile || '—';
            document.getElementById('viewEmail').textContent = d.email || 'Not provided';
            document.getElementById('viewCompany').textContent = d.company || 'Not provided';
            document.getElementById('viewLocation').textContent = d.location || '—';
            document.getElementById('viewQuantity').textContent = d.quantity || '—';
            document.getElementById('viewDelivery').textContent = d.delivery || '—';
            document.getElementById('viewIp').textContent = d.ip || '—';
            document.getElementById('viewCreated').textContent = d.created || '—';
            document.getElementById('viewMessage').textContent = d.message || 'No additional requirements provided.';

            const tagsWrap = document.getElementById('viewProducts');
            tagsWrap.innerHTML = '';
            const products = (d.products || '').split(',').map(function(p) { return p.trim(); }).filter(Boolean);
            if (products.length) {
                products.forEach(function(p) {
                    const span = document.createElement('span');
                    span.className = 'badge badge-muted';
                    span.textContent = p;
                    tagsWrap.appendChild(span);
                });
            } else {
                tagsWrap.textContent = '—';
            }

            openModal(viewModal);
        });
    });

    /* ---------- DELETE modal: confirm before submitting, cancel does nothing ---------- */
    const deleteModal = document.getElementById('deleteModal');
    const confirmDeleteBtn = deleteModal.querySelector('.js-confirm-delete');
    let formPendingDelete = null;

    document.querySelectorAll('.js-delete-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            formPendingDelete = btn.closest('form.js-delete-form');
            document.getElementById('deleteModalName').textContent = btn.dataset.name || 'this person';
            openModal(deleteModal);
        });
    });

    confirmDeleteBtn.addEventListener('click', function() {
        if (formPendingDelete) {
            formPendingDelete.submit(); // only fires when the user explicitly confirms
        }
        closeModal(deleteModal);
    });

    // If the modal is dismissed any other way (Cancel, backdrop, Escape), nothing is submitted
})();