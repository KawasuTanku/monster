(function() {
    'use strict';
    
    // Active tab highlighting
    function highlightTab() {
        const path = window.location.pathname;
        document.querySelectorAll('.tabbar a').forEach(a => {
            const route = a.getAttribute('data-route');
            if (route && path.startsWith('/' + route)) {
                a.classList.add('active');
            }
        });
    }
    
    // Bottom sheet
    const sheet = document.getElementById('sheet');
    const backdrop = document.getElementById('sheet-backdrop');
    const content = document.getElementById('sheet-content');
    
    function openSheet(html) {
        content.innerHTML = html;
        sheet.classList.add('open');
        backdrop.classList.add('open');
        // Re-bind any inline scripts in the sheet
        content.querySelectorAll('script').forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }
    
    function closeSheet() {
        sheet.classList.remove('open');
        backdrop.classList.remove('open');
    }
    
    backdrop.addEventListener('click', closeSheet);
    
    // Open a form (by element ID) into the bottom sheet
    window.openFormInSheet = function(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        openSheet(form.outerHTML);
    };
    
    // Handle form submissions inside sheet via fetch
    content.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.tagName !== 'FORM') return;
        e.preventDefault();
        const formData = new FormData(form);
        fetch(form.action, {
            method: form.method || 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.text()).then(html => {
            closeSheet();
            window.location.reload();
        }).catch(err => {
            alert('Error: ' + err);
        });
    });
    
    // FAB button: open its target form in sheet
    document.querySelectorAll('.fab').forEach(fab => {
        fab.addEventListener('click', function() {
            const target = this.getAttribute('data-form');
            if (target) openFormInSheet(target);
        });
    });
    
    // Pull-to-refresh
    let startY = 0;
    let isPulling = false;
    const indicator = document.createElement('div');
    indicator.className = 'ptr-indicator';
    indicator.textContent = '↓ Pull to refresh';
    
    document.addEventListener('touchstart', e => {
        if (window.scrollY === 0) {
            startY = e.touches[0].clientY;
            isPulling = true;
        }
    });
    
    document.addEventListener('touchmove', e => {
        if (!isPulling) return;
        const diff = e.touches[0].clientY - startY;
        if (diff > 80 && window.scrollY === 0) {
            indicator.textContent = '↓ Release to refresh';
            document.querySelector('.container').prepend(indicator);
        }
    });
    
    document.addEventListener('touchend', () => {
        if (isPulling && indicator.parentNode) {
            indicator.textContent = 'Refreshing...';
            setTimeout(() => window.location.reload(), 300);
        }
        isPulling = false;
    });
    
    highlightTab();
})();
