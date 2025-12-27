// Core Application Logic

const App = {
    // --- State Management ---
    KEYS: {
        USERS: 'ppa_users',
        CURRENT_USER: 'ppa_current_user',
        SIGNALS: 'ppa_signals',
        TICKETS: 'ppa_tickets'
    },

    // --- Initialization ---
    init() {
        this.initStorage();
    },

    initStorage() {
        if (!localStorage.getItem(this.KEYS.USERS)) {
            localStorage.setItem(this.KEYS.USERS, JSON.stringify([]));
        }
        // Initial Mock Data (REMOVED)
        if (!localStorage.getItem(this.KEYS.SIGNALS)) {
            const initialSignals = [];
            localStorage.setItem(this.KEYS.SIGNALS, JSON.stringify(initialSignals));
        }
    },

    // --- Authentication ---
    register(name, email, password) {
        const users = this.getUsers();
        if (users.find(u => u.email === email)) {
            return { success: false, message: 'Email already exists' };
        }
        
        const newUser = {
            id: Date.now(),
            name,
            email,
            password, // In real app, hash this!
            role: 'user', // user | admin
            joinDate: new Date().toISOString(),
            avatar: '' // Add avatar field
        };
        
        users.push(newUser);
        localStorage.setItem(this.KEYS.USERS, JSON.stringify(users));
        return { success: true };
    },

    login(email, password) {
        // Admin Bypass
        if (email === 'admin@admin.com' && password === 'admin123') {
            const adminUser = {
                id: 1,
                name: 'Administrator',
                email: 'admin@admin.com',
                role: 'admin',
                avatar: ''
            };
            this.setCurrentUser(adminUser);
            return { success: true, user: adminUser };
        }

        const users = this.getUsers();
        const user = users.find(u => u.email === email && u.password === password);
        
        if (user) {
            this.setCurrentUser(user);
            return { success: true, user };
        }
        return { success: false, message: 'Invalid credentials' };
    },

    logout() {
        localStorage.removeItem(this.KEYS.CURRENT_USER);
        window.location.href = 'login.html';
    },

    getCurrentUser() {
        return JSON.parse(localStorage.getItem(this.KEYS.CURRENT_USER));
    },

    setCurrentUser(user) {
        localStorage.setItem(this.KEYS.CURRENT_USER, JSON.stringify(user));
    },

    getUsers() {
        return JSON.parse(localStorage.getItem(this.KEYS.USERS) || '[]');
    },

    updateUserAvatar(avatarUrl) {
        const currentUser = this.getCurrentUser();
        if (!currentUser) return;

        currentUser.avatar = avatarUrl;
        this.setCurrentUser(currentUser);

        // Update in main users list
        const users = this.getUsers();
        const index = users.findIndex(u => u.id === currentUser.id);
        if (index !== -1) {
            users[index].avatar = avatarUrl;
            localStorage.setItem(this.KEYS.USERS, JSON.stringify(users));
        }
    },

    checkAuth() {
        const user = this.getCurrentUser();
        if (!user) {
            window.location.href = 'login.html';
            return;
        }
        
        // Redirect admin to admin dashboard if on user dashboard
        if (user.role === 'admin' && window.location.pathname.includes('dashboard.html')) {
            window.location.href = 'admin-dashboard.html';
        }
    },

    // --- Signals (Mock Data Logic) ---
    getSignals() {
        return JSON.parse(localStorage.getItem(this.KEYS.SIGNALS) || '[]');
    },

    getActiveSignalsCount() {
        const signals = this.getSignals();
        return signals.filter(s => s.status === 'Active').length;
    },

    // --- Utilities ---
    formatDate(isoString) {
        return new Date(isoString).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    },

    getPairIcon(pair) {
        // Simple mock icon logic
        if (pair.includes('EUR')) return 'https://flagcdn.com/20x15/eu.png';
        if (pair.includes('USD')) return 'https://flagcdn.com/20x15/us.png';
        if (pair.includes('GBP')) return 'https://flagcdn.com/20x15/gb.png';
        if (pair.includes('JPY')) return 'https://flagcdn.com/20x15/jp.png';
        if (pair.includes('XAU')) return 'https://flagcdn.com/20x15/us.png'; // Gold
        if (pair.includes('BTC')) return 'https://cryptologos.cc/logos/bitcoin-btc-logo.png?v=025';
        return 'https://flagcdn.com/20x15/un.png';
    },

    // --- Courses ---
    getCourses() {
        return JSON.parse(localStorage.getItem('ppa_courses') || '[]');
    },
    
    addCourse(course) {
        const courses = this.getCourses();
        course.id = Date.now();
        course.date = new Date().toISOString();
        courses.push(course);
        localStorage.setItem('ppa_courses', JSON.stringify(courses));
        return course;
    },

    // --- Signals (Admin) ---
    addSignal(signal) {
        const signals = this.getSignals();
        signal.id = Date.now();
        signal.status = 'Active'; // Default status
        signal.date = new Date().toISOString();
        signal.outcome = 'Running';
        signals.unshift(signal);
        localStorage.setItem(this.KEYS.SIGNALS, JSON.stringify(signals));
        return signal;
    },

    // --- Revenue ---
    getRevenueStats() {
        // Mock revenue data
        return {
            total: 12500.00,
            monthly: 1200.00,
            transactions: [
                { user: 'John Doe', plan: 'Pro', amount: 199, date: 'Oct 24, 2023' },
                { user: 'Jane Smith', plan: 'Pro', amount: 199, date: 'Oct 22, 2023' },
                { user: 'Mike Ross', plan: 'Pro', amount: 199, date: 'Oct 20, 2023' }
            ]
        };
    },

    // --- Payment Methods ---
    async initiatePayment(method) {
        if (method === 'paystack') {
            const user = this.getCurrentUser();
            if (!user) {
                alert('Please login first to make a payment.');
                window.location.href = 'login.html';
                return;
            }

            // Client-Side Paystack Implementation (No PHP Required)
            // Warning: Requires Public Key
            const publicKey = 'pk_live_YOUR_PUBLIC_KEY_HERE'; // <--- REPLACE THIS WITH YOUR PUBLIC KEY

            if (publicKey.includes('YOUR_PUBLIC_KEY_HERE')) {
                alert('Configuration Error: Public Key Missing.\n\nPlease open js/app.js and replace "pk_live_YOUR_PUBLIC_KEY_HERE" with your actual Paystack Public Key (starts with pk_live_ or pk_test_).');
                return;
            }

            const handler = PaystackPop.setup({
                key: publicKey, 
                email: user.email,
                amount: 19900, // 199.00 NGN/USD in kobo/cents
                currency: 'USD', // Change to NGN if using Naira
                ref: '' + Math.floor((Math.random() * 1000000000) + 1), 
                onClose: function() {
                    alert('Transaction was not completed, window closed.');
                },
                callback: function(response) {
                    // Payment successful!
                    alert('Payment successful! Reference: ' + response.reference);
                    // Here you would typically verify the transaction on your backend
                    // For this static version, we'll redirect to success
                    window.location.href = 'dashboard.html?payment=success';
                }
            });

            handler.openIframe();

        } else if (method === 'crypto') {
            alert('Generating Crypto Payment Address (USDT/BTC)...\n(Integration Pending)');
        }
    },

    // --- Ticket Methods ---
    getTickets() {
        return JSON.parse(localStorage.getItem(this.KEYS.TICKETS) || '[]');
    },

    getUserTickets() {
        const user = this.getCurrentUser();
        if (!user) return [];
        const tickets = this.getTickets();
        return tickets.filter(t => t.userId === user.id);
    },

    createTicket(subject, message) {
        const user = this.getCurrentUser();
        if (!user) return;

        const tickets = this.getTickets();
        const newTicket = {
            id: Date.now(),
            userId: user.id,
            userName: user.name,
            userEmail: user.email,
            subject,
            message,
            status: 'Open',
            date: new Date().toISOString()
        };
        tickets.unshift(newTicket);
        localStorage.setItem(this.KEYS.TICKETS, JSON.stringify(tickets));
        return newTicket;
    }
};

// Initialize App on load
App.init();
