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

    updateUserProfile(name, email, bio) {
        const currentUser = this.getCurrentUser();
        if (!currentUser) return;

        currentUser.name = name;
        currentUser.email = email;
        currentUser.bio = bio;
        
        this.setCurrentUser(currentUser);

        // Update in main users list
        const users = this.getUsers();
        const index = users.findIndex(u => u.id === currentUser.id);
        if (index !== -1) {
            users[index].name = name;
            users[index].email = email;
            users[index].bio = bio;
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
        const transactions = JSON.parse(localStorage.getItem('ppa_transactions') || '[]');
        
        const total = transactions.reduce((sum, tx) => sum + parseFloat(tx.amount), 0);
        
        // Calculate this month's revenue
        const now = new Date();
        const thisMonth = transactions.filter(tx => {
            const txDate = new Date(tx.date);
            return txDate.getMonth() === now.getMonth() && txDate.getFullYear() === now.getFullYear();
        }).reduce((sum, tx) => sum + parseFloat(tx.amount), 0);

        return {
            total: total.toFixed(2),
            monthly: thisMonth.toFixed(2),
            transactions: transactions
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

            // Public Key is safe to be on the frontend
            const publicKey = 'pk_live_764b7c6590906e7aade5d4baac08b7d711bbf2fe'; // <--- REPLACE WITH YOUR LIVE PUBLIC KEY (starts with pk_live_)

            // Exchange rate: 1 USD = 1500 NGN (Example rate, adjust as needed)
            const exchangeRate = 1600; 
            const amountInUSD = 199;
            const amountInNGN = amountInUSD * exchangeRate * 100; // Convert to kobo

            const handler = PaystackPop.setup({
                key: publicKey, 
                email: user.email,
                amount: amountInNGN, 
                currency: 'NGN', 
                ref: '' + Math.floor((Math.random() * 1000000000) + 1), 
                metadata: {
                    custom_fields: [
                        {
                            display_name: "Plan",
                            variable_name: "plan",
                            value: "Pro Trader Plan ($199)"
                        }
                    ]
                },
                onClose: function() {
                    alert('Transaction was not completed, window closed.');
                },
                callback: function(response) {
                    // Send reference to backend for secure verification
                    fetch('http://localhost:3000/api/verify-payment', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ reference: response.reference })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status) {
                            alert('Payment verified successfully!');
                            window.location.href = 'dashboard.html?payment=success';
                        } else {
                            alert('Payment verification failed: ' + data.message);
                        }
                    })
                    .catch(err => {
                        console.error('Error verifying payment:', err);
                        alert('Error verifying payment. Please contact support.');
                    });
                }
            });

            handler.openIframe();

        } else if (method === 'crypto') {
            // Show Crypto Modal
            const cryptoModal = new bootstrap.Modal(document.getElementById('cryptoPaymentModal'));
            cryptoModal.show();
        }
    },

    copyToClipboard(elementId) {
        const copyText = document.getElementById(elementId);
        copyText.select();
        copyText.setSelectionRange(0, 99999); // For mobile devices
        navigator.clipboard.writeText(copyText.value).then(() => {
            alert("Address copied to clipboard!");
        });
    },

    async submitCryptoPayment(event) {
        event.preventDefault();
        
        const fileInput = document.getElementById('paymentProof');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please upload a screenshot of your payment.');
            return;
        }

        if (file.size > 2 * 1024 * 1024) { // 2MB limit
            alert('File is too large. Max size is 2MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const base64Image = e.target.result;
            const user = App.getCurrentUser();
            
            const pendingPayment = {
                id: Date.now(),
                userId: user.id,
                userName: user.name,
                userEmail: user.email,
                amount: 199.00,
                currency: 'USD',
                method: 'Crypto',
                proof: base64Image,
                status: 'Pending',
                date: new Date().toISOString()
            };

            const pending = JSON.parse(localStorage.getItem('ppa_crypto_pending') || '[]');
            pending.push(pendingPayment);
            localStorage.setItem('ppa_crypto_pending', JSON.stringify(pending));

            alert('Payment proof submitted successfully! Admin will verify shortly.');
            
            // Close modal
            const modalEl = document.getElementById('cryptoPaymentModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            
            // Reset form
            document.getElementById('cryptoPaymentForm').reset();
        };
        
        reader.readAsDataURL(file);
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
