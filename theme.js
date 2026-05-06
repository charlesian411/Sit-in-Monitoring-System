// ─── CCS Theme & Navigation Controller ───
// Apply saved theme immediately (prevents flash)
(function() {
    var saved = localStorage.getItem('ccs_theme');
    if (saved === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

    // ── Theme Toggle ──
    function createThemeToggle() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'theme-toggle-btn';
        btn.title = 'Toggle Dark Mode';
        btn.innerHTML = isDark ? '☀️' : '🌙';
        btn.addEventListener('click', function() {
            var current = document.documentElement.getAttribute('data-theme');
            if (current === 'dark') {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('ccs_theme', 'light');
                btn.innerHTML = '🌙';
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('ccs_theme', 'dark');
                btn.innerHTML = '☀️';
            }
        });
        return btn;
    }

    // ── Hamburger Menu ──
    var nav = document.querySelector('.admin-top-nav, .student-dashboard-nav, nav');
    if (!nav) return;

    var ul = nav.querySelector('ul');
    if (!ul) return;

    // Inject theme toggle into nav
    var themeLi = document.createElement('li');
    themeLi.className = 'theme-toggle-li';
    themeLi.appendChild(createThemeToggle());
    var lastItem = ul.lastElementChild;
    if (lastItem) {
        ul.insertBefore(themeLi, lastItem);
    } else {
        ul.appendChild(themeLi);
    }

    // Create hamburger button
    var hamburger = document.createElement('button');
    hamburger.type = 'button';
    hamburger.className = 'hamburger-btn';
    hamburger.setAttribute('aria-label', 'Toggle Menu');
    hamburger.innerHTML = '<span></span><span></span><span></span>';

    // Create overlay for mobile menu
    var overlay = document.createElement('div');
    overlay.className = 'nav-overlay';
    document.body.appendChild(overlay);

    // Insert hamburger into nav (after brand)
    var brand = nav.querySelector('.nav-brand');
    if (brand) {
        brand.insertAdjacentElement('afterend', hamburger);
    } else {
        nav.insertBefore(hamburger, ul);
    }

    // Toggle menu
    function toggleMenu() {
        var isOpen = ul.classList.contains('nav-open');
        if (isOpen) {
            ul.classList.remove('nav-open');
            hamburger.classList.remove('is-active');
            overlay.classList.remove('is-visible');
            document.body.style.overflow = '';
        } else {
            ul.classList.add('nav-open');
            hamburger.classList.add('is-active');
            overlay.classList.add('is-visible');
            document.body.style.overflow = 'hidden';
        }
    }

    hamburger.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);

    // Close menu on link click
    ul.querySelectorAll('a, button').forEach(function(el) {
        el.addEventListener('click', function() {
            if (ul.classList.contains('nav-open')) {
                ul.classList.remove('nav-open');
                hamburger.classList.remove('is-active');
                overlay.classList.remove('is-visible');
                document.body.style.overflow = '';
            }
        });
    });

    // Close on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && ul.classList.contains('nav-open')) {
            toggleMenu();
        }
    });

    // Handle window resize
    var mql = window.matchMedia('(min-width: 1025px)');
    function handleResize(e) {
        if (e.matches && ul.classList.contains('nav-open')) {
            ul.classList.remove('nav-open');
            hamburger.classList.remove('is-active');
            overlay.classList.remove('is-visible');
            document.body.style.overflow = '';
        }
    }
    if (mql.addEventListener) {
        mql.addEventListener('change', handleResize);
    } else if (mql.addListener) {
        mql.addListener(handleResize);
    }
});
