(() => {
    const shell = document.querySelector('.admin-shell');
    const sidebar = document.getElementById('adminSidebar');
    if (!shell || !sidebar) return;

    const collapseButton = document.getElementById('sidebarCollapseBtn');
    const mobileMenuButton = document.getElementById('mobileMenuBtn');
    const closeButton = document.getElementById('sidebarCloseBtn');
    const backdrop = document.getElementById('sidebarBackdrop');
    const collapsedStorageKey = 'balangay_sidebar_collapsed';

    sidebar.querySelectorAll('.admin-nav-link').forEach(link => {
        const label = link.textContent.trim().replace(/\s+/g, ' ');
        link.title = label;
        link.setAttribute('aria-label', label);
    });
    sidebar.querySelectorAll('.sidebar-bottom button').forEach(button => {
        const label = button.textContent.trim().replace(/\s+/g, ' ');
        button.title = label;
        button.setAttribute('aria-label', label);
    });

    const setCollapsed = collapsed => {
        shell.classList.toggle('sidebar-collapsed', collapsed);
        if (collapseButton) {
            collapseButton.setAttribute('aria-expanded', String(!collapsed));
            collapseButton.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
            collapseButton.title = collapsed ? 'Expand navigation' : 'Collapse navigation';
        }
    };

    setCollapsed(localStorage.getItem(collapsedStorageKey) === 'true');
    collapseButton?.addEventListener('click', () => {
        const collapsed = !shell.classList.contains('sidebar-collapsed');
        setCollapsed(collapsed);
        localStorage.setItem(collapsedStorageKey, String(collapsed));
    });

    const setDrawerOpen = open => {
        sidebar.classList.toggle('is-open', open);
        backdrop?.classList.toggle('is-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
        mobileMenuButton?.setAttribute('aria-expanded', String(open));
    };

    mobileMenuButton?.addEventListener('click', () => setDrawerOpen(true));
    closeButton?.addEventListener('click', () => setDrawerOpen(false));
    backdrop?.addEventListener('click', () => setDrawerOpen(false));
    sidebar.querySelectorAll('.admin-nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) setDrawerOpen(false);
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) setDrawerOpen(false);
    });
})();