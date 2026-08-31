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
    
    window.openFormInSheet = function(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        openSheet(form.outerHTML);
    };
    
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
    
    document.querySelectorAll('.fab').forEach(fab => {
        fab.addEventListener('click', function() {
            const target = this.getAttribute('data-form');
            if (target) openFormInSheet(target);
        });
    });
    
    // Page transitions
    const container = document.querySelector('.container');
    const loader = document.getElementById('page-loader');
    
    function navigateTo(url) {
        if (!container) { window.location.href = url; return; }
        container.classList.add('fading');
        loader.classList.add('loading');
        setTimeout(() => {
            window.location.href = url;
        }, 200);
    }
    
    document.querySelectorAll('.tabbar a').forEach(a => {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            navigateTo(url);
        });
    });
    
    window.addEventListener('popstate', function() {
        window.location.reload();
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
