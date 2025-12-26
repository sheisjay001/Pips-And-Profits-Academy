/**
 * Pips and Profit Academy - Core Application Logic
 * Uses LocalStorage to simulate backend database
 */

const App = {
    // Keys for LocalStorage
    KEYS: {
        SIGNALS: 'ppa_signals',
        USERS: 'ppa_users',
        CURRENT_USER: 'ppa_current_user',
        COURSES: 'ppa_courses'
    },

    // Initial Mock Data
    initialSignals: [
        { id: 1, pair: 'EUR/USD', type: 'BUY', entry: '1.0850', sl: '1.0820', tp: '1.0900', status: 'Profit (+25 Pips)', date: new Date().toISOString() },
        { id: 2, pair: 'GBP/JPY', type: 'SELL', entry: '182.40', sl: '182.80', tp: '181.50', status: 'Running', date: new Date(Date.now() - 7200000).toISOString() },
        { id: 3, pair: 'XAU/USD', type: 'BUY', entry: '2035.50', sl: '2030.00', tp: '2050.00', status: 'Profit (+120 Pips)', date: new Date(Date.now() - 86400000).toISOString() }
    ],

    initialCourses: [
        { 
            id: 1, 
            title: 'Forex Basics 101', 
            level: 'Beginner', 
            progress: 0, 
            thumbnail: 'https://images.unsplash.com/photo-1611974765270-ca1258634369?w=400', 
            desc: 'Introduction to currency trading.',
            videoUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ' // Example YouTube Embed URL
        },
        { 
            id: 2, 
            title: 'Technical Analysis Masterclass', 
            level: 'Intermediate', 
            progress: 0, 
            thumbnail: 'https://images.unsplash.com/photo-1590283603385-17ffb3a7f29f?w=400', 
            desc: 'Chart patterns and indicators.',
            videoUrl: '' // Add your video URL here
        },
        { 
            id: 3, 
            title: 'Risk Management & Psychology', 
            level: 'Advanced', 
            progress: 0, 
            thumbnail: 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=400', 
            desc: 'Protecting your capital.',
            videoUrl: '' // Add your video URL here
        }
    ],

    init() {
        // Initialize data if not exists
        if (!localStorage.getItem(this.KEYS.SIGNALS)) {
            localStorage.setItem(this.KEYS.SIGNALS, JSON.stringify(this.initialSignals));
        }
        if (!localStorage.getItem(this.KEYS.COURSES)) {
            localStorage.setItem(this.KEYS.COURSES, JSON.stringify(this.initialCourses));
        }
        // Create default admin user if no users exist
        if (!localStorage.getItem(this.KEYS.USERS)) {
            const adminUser = { id: 1, name: 'Admin', email: 'admin@pips.com', password: 'admin', role: 'admin' };
            localStorage.setItem(this.KEYS.USERS, JSON.stringify([adminUser]));
        }
    },

    // --- Authentication Methods ---

    register(name, email, password) {
        const users = JSON.parse(localStorage.getItem(this.KEYS.USERS) || '[]');
        if (users.find(u => u.email === email)) {
            return { success: false, message: 'Email already exists' };
        }
        const newUser = { id: Date.now(), name, email, password, role: 'user' };
        users.push(newUser);
        localStorage.setItem(this.KEYS.USERS, JSON.stringify(users));
        this.login(email, password); // Auto login
        return { success: true };
    },

    login(email, password) {
        const users = JSON.parse(localStorage.getItem(this.KEYS.USERS) || '[]');
        const user = users.find(u => u.email === email && u.password === password);
        if (user) {
            localStorage.setItem(this.KEYS.CURRENT_USER, JSON.stringify(user));
            return { success: true, role: user.role };
        }
        return { success: false, message: 'Invalid credentials' };
    },

    logout() {
        localStorage.removeItem(this.KEYS.CURRENT_USER);
        window.location.href = 'index.html';
    },

    getCurrentUser() {
        return JSON.parse(localStorage.getItem(this.KEYS.CURRENT_USER));
    },

    checkAuth() {
        const user = this.getCurrentUser();
        if (!user) {
            window.location.href = 'login.html';
        }
        // Initialize sidebar if on a page with sidebar
        this.initSidebar();
        return user;
    },

    initSidebar() {
        const toggle = document.getElementById("sidebarToggle");
        const wrapper = document.getElementById("wrapper");
        const pageContent = document.getElementById("page-content-wrapper");

        if (toggle && wrapper) {
            // Remove existing listeners to prevent duplicates if called multiple times?
            // A simple way is to clone and replace, but let's assume it's called once per page load.
            
            // Check if listener already attached? 
            // Better to rely on the fact that checkAuth runs once.
            
            toggle.onclick = function(e) {
                e.preventDefault();
                wrapper.classList.toggle("toggled");
                document.body.classList.toggle("sidebar-open");
            };

            // Close sidebar when clicking outside on mobile
            if (pageContent) {
                pageContent.onclick = function(e) {
                    if (window.innerWidth <= 768 && wrapper.classList.contains("toggled")) {
                        // Check if click is NOT on the toggle button
                        if (!toggle.contains(e.target)) {
                            wrapper.classList.remove("toggled");
                            document.body.classList.remove("sidebar-open");
                        }
                    }
                };
            }
        }
    },

    // --- Signal Methods ---

    getSignals() {
        return JSON.parse(localStorage.getItem(this.KEYS.SIGNALS) || '[]');
    },

    addSignal(signal) {
        const signals = this.getSignals();
        const newSignal = {
            id: Date.now(),
            status: 'Running',
            date: new Date().toISOString(),
            ...signal
        };
        signals.unshift(newSignal); // Add to top
        localStorage.setItem(this.KEYS.SIGNALS, JSON.stringify(signals));
        return newSignal;
    },

    getActiveSignalsCount() {
        const signals = this.getSignals();
        return signals.filter(s => s.status === 'Running' || s.status === 'Pending').length;
    },

    // --- Course Methods ---
    getCourses() {
        // Merge stored progress with initial structure to ensure new fields (like videoUrl) appear
        const storedCourses = JSON.parse(localStorage.getItem(this.KEYS.COURSES) || '[]');
        return this.initialCourses.map(initial => {
            const stored = storedCourses.find(c => c.id === initial.id);
            // Preserve progress if course exists, otherwise return initial
            return stored ? { ...initial, progress: stored.progress } : initial;
        }).concat(storedCourses.filter(c => !this.initialCourses.find(ic => ic.id === c.id)));
    },

    addCourse(course) {
        const courses = JSON.parse(localStorage.getItem(this.KEYS.COURSES) || '[]');
        const newCourse = {
            id: Date.now(),
            progress: 0,
            ...course
        };
        courses.push(newCourse);
        localStorage.setItem(this.KEYS.COURSES, JSON.stringify(courses));
        return newCourse;
    },

    // --- User Methods ---
    getUsers() {
        return JSON.parse(localStorage.getItem(this.KEYS.USERS) || '[]');
    },

    deleteUser(userId) {
        let users = this.getUsers();
        users = users.filter(u => u.id !== userId);
        localStorage.setItem(this.KEYS.USERS, JSON.stringify(users));
        return true;
    },

    // --- Revenue Methods ---
    getRevenueStats() {
        // Mock revenue data calculation
        const users = this.getUsers();
        // Assume 20% of users are Pro ($49) and 5% are Elite ($199)
        // This is just for demonstration purposes
        let totalRevenue = 0;
        let monthlyRevenue = 0;
        
        users.forEach(user => {
            if (user.role !== 'admin') {
                // Randomly assign simulated revenue to users for display
                const rand = user.id % 10;
                if (rand > 7) {
                    totalRevenue += 199; // Elite
                } else if (rand > 4) {
                    totalRevenue += 49; // Pro
                }
            }
        });

        return {
            total: totalRevenue,
            monthly: Math.floor(totalRevenue * 0.15), // Mock monthly growth
            transactions: [
                { id: 101, user: 'Alice Smith', amount: 49, date: '2023-10-24', plan: 'Pro Trader' },
                { id: 102, user: 'Bob Jones', amount: 199, date: '2023-10-23', plan: 'Elite Mentorship' },
                { id: 103, user: 'Charlie Brown', amount: 49, date: '2023-10-22', plan: 'Pro Trader' },
                { id: 104, user: 'David Lee', amount: 49, date: '2023-10-20', plan: 'Pro Trader' },
                { id: 105, user: 'Eva Green', amount: 199, date: '2023-10-18', plan: 'Elite Mentorship' }
            ]
        };
    },

    // --- UI Rendering Helpers ---

    formatDate(isoString) {
        const date = new Date(isoString);
        const now = new Date();
        const diff = (now - date) / 1000; // seconds

        if (diff < 60) return 'Just now';
        if (diff < 3600) return `${Math.floor(diff / 60)} mins ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`;
        return date.toLocaleDateString();
    },

    getPairIcon(pair) {
        if (pair.includes('EUR')) return 'https://flagcdn.com/20x15/eu.png';
        if (pair.includes('GBP')) return 'https://flagcdn.com/20x15/gb.png';
        if (pair.includes('USD') && !pair.includes('XAU')) return 'https://flagcdn.com/20x15/us.png';
        if (pair.includes('JPY')) return 'https://flagcdn.com/20x15/jp.png';
        if (pair.includes('XAU')) return 'https://flagcdn.com/20x15/us.png'; // Gold
        if (pair.includes('BTC')) return 'https://cryptologos.cc/logos/bitcoin-btc-logo.png?v=025';
        return 'https://flagcdn.com/20x15/un.png';
    },

    // --- Payment Methods ---
    initiatePayment(method) {
        if (method === 'paystack') {
            alert('Redirecting to Paystack Secure Checkout...\n(Integration Pending)');
        } else if (method === 'crypto') {
            alert('Generating Crypto Payment Address (USDT/BTC)...\n(Integration Pending)');
        }
    }
};

// Initialize App on load
App.init();
