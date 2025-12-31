document.addEventListener('DOMContentLoaded', function() {
    // Close navbar when a link is clicked on mobile
    const navLinks = document.querySelectorAll('.nav-link');
    const navbarCollapse = document.getElementById('navbarNav');

    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            if (window.getComputedStyle(navbarCollapse).display !== 'none' && window.innerWidth < 992) {
                const bsCollapse = new bootstrap.Collapse(navbarCollapse, {
                    toggle: true
                });
            }
        });
    });

    // Add scroll class to navbar
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (window.scrollY > 50) {
            navbar.classList.add('shadow-sm');
        } else {
            navbar.classList.remove('shadow-sm');
        }
    });

    // Sidebar Toggle Logic
    const sidebarToggle = document.getElementById("sidebarToggle");
    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function(e) {
            e.preventDefault();
            document.getElementById("wrapper").classList.toggle("toggled");
            
            // Handle mobile body scroll
            if (window.innerWidth <= 768) {
                document.body.classList.toggle("sidebar-open");
            }
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('wrapper');
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const toggleBtn = document.getElementById('sidebarToggle');
        
        if (window.innerWidth <= 768 && 
            wrapper.classList.contains('toggled') && 
            !sidebarWrapper.contains(e.target) && 
            !toggleBtn.contains(e.target)) {
            
            wrapper.classList.remove('toggled');
            document.body.classList.remove('sidebar-open');
        }
    });

    

    const sections = document.querySelectorAll('section[id]');
    const navLinksMap = {};
    document.querySelectorAll('.navbar .nav-link[href^="#"]').forEach(link => {
        const id = link.getAttribute('href').substring(1);
        navLinksMap[id] = link;
    });
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const id = entry.target.getAttribute('id');
            const link = navLinksMap[id];
            if (link) {
                if (entry.isIntersecting) {
                    Object.values(navLinksMap).forEach(l => l.classList.remove('active'));
                    link.classList.add('active');
                }
            }
        });
    }, { rootMargin: '-50% 0px -50% 0px', threshold: 0.1 });
    sections.forEach(sec => observer.observe(sec));

    const backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) {
                backToTop.classList.add('show');
            } else {
                backToTop.classList.remove('show');
            }
        });
        backToTop.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
});
