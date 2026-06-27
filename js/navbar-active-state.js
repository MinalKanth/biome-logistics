(function() {
    'use strict';

    class NavbarActiveState {
        constructor() {
            this.navLinks = document.querySelectorAll('.nav-link');
            this.dropdownItems = document.querySelectorAll('.dropdown-item');
            this.init();
        }

        init() {
            // Set active state on page load (instant, no delay)
            this.setActiveLink();

            // Also update on hash change (for single-page apps)
            window.addEventListener('hashchange', () => this.setActiveLink());

            // Update on click for smoother transitions
            this.attachClickListeners();
        }

        setActiveLink() {
            const currentPage = this.getCurrentPage();

            // Remove active class from all links
            this.navLinks.forEach(link => link.classList.remove('active'));
            this.dropdownItems.forEach(item => item.classList.remove('active'));

            // Add active class to current page link
            if (currentPage) {
                this.markActiveLink(currentPage);
            }
        }

        getCurrentPage() {
            // Get current URL path
            const pathname = window.location.pathname;
            const filename = pathname.substring(pathname.lastIndexOf('/') + 1) || 'index.php';

            return filename;
        }

        markActiveLink(currentPage) {
            // Check main nav links
            this.navLinks.forEach(link => {
                const href = link.getAttribute('href');

                if (href === currentPage ||
                    (currentPage === '' && href === 'index.php') ||
                    (currentPage === '/' && href === 'index.php')) {
                    link.classList.add('active');
                    return;
                }
            });

            // Check dropdown items
            this.dropdownItems.forEach(item => {
                const href = item.getAttribute('href');

                if (href === currentPage) {
                    item.classList.add('active');

                    // Also highlight parent dropdown toggle
                    const dropdownElement = item.closest('.dropdown');
                    if (dropdownElement) {
                        const dropdownToggle = dropdownElement.querySelector('.dropdown-toggle');
                        if (dropdownToggle) {
                            dropdownToggle.classList.add('active');
                        }
                    }
                    return;
                }
            });
        }

        attachClickListeners() {
            // Update immediately on link click (for better UX)
            this.navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    setTimeout(() => this.setActiveLink(), 50);
                });
            });

            this.dropdownItems.forEach(item => {
                item.addEventListener('click', () => {
                    setTimeout(() => this.setActiveLink(), 50);
                });
            });
        }
    }

    // ============ INITIALIZE ON DOM READY ============
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            new NavbarActiveState();
        });
    } else {
        new NavbarActiveState();
    }
})();