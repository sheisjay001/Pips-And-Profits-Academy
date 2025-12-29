// Core Application Logic

const App = {
    // --- State Management ---
    KEYS: {
        CURRENT_USER: 'ppa_current_user',
    },

    // --- Initialization ---
    init() {
        if (window.location.protocol === 'file:') {
            alert('Warning: You are running this site directly from a file. Please use a local server (like XAMPP) and access via http://localhost/... to ensure database connections work.');
        }
        // Detect Live Server ports (5500-5510)
        if (window.location.port >= 5500 && window.location.port <= 5510) {
            const phpUrl = 'http://localhost:8000';
            const msg = `⚠️ Incorrect Server Detected!\n\nYou are running on "Live Server" (Port ${window.location.port}), which cannot execute PHP.\n\nPlease click OK to be redirected to the correct PHP Server: ${phpUrl}`;
            if (confirm(msg)) {
                window.location.href = phpUrl + window.location.pathname;
            }
        }
    },

    // --- API Helper ---
    async api(endpoint, method = 'GET', data = null) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' }
        };
        if (data) options.body = JSON.stringify(data);
        
        try {
            const res = await fetch(`api/${endpoint}`, options);
            if (!res.ok) {
                // Try to get text error if possible
                const text = await res.text();
                
                // Specific help for 405 Method Not Allowed (Common when using Live Server instead of PHP)
                if (res.status === 405) {
                    throw new Error(`Server Error (405): You are likely using "Live Server" or a static file viewer. Please use the PHP server URL (http://localhost:8000/...) to run this app.`);
                }

                throw new Error(`Server Error (${res.status}): ${text.substring(0, 100)}...`);
            }
            return await res.json();
        } catch (error) {
            console.error('API Call Failed:', error);
            // Check for syntax error (often means PHP error output instead of JSON)
            if (error instanceof SyntaxError) {
                return { success: false, message: 'Server returned invalid data. Possible PHP error.' };
            }
            return { success: false, message: `Network/Server Error: ${error.message}` };
        }
    },

    // --- Authentication ---
    async register(name, email, password) {
        const result = await this.api('auth.php', 'POST', {
            action: 'register',
            name, email, password
        });
        return result;
    },

    async login(email, password) {
        const result = await this.api('auth.php', 'POST', {
            action: 'login',
            email, password
        });
        
        if (result.success && result.user) {
            this.setCurrentUser(result.user);
        }
        return result;
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

    async updateUserProfile(name, email, bio) {
        const currentUser = this.getCurrentUser();
        if (!currentUser) return;

        const result = await this.api('auth.php', 'POST', {
            action: 'update_profile',
            id: currentUser.id,
            name, email, bio
        });

        if (result.success) {
            // Update local session
            currentUser.name = name;
            currentUser.email = email;
            currentUser.bio = bio;
            this.setCurrentUser(currentUser);
        }
        return result;
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

    async getUsers() {
        const result = await this.api('auth.php', 'POST', { action: 'get_users' });
        return Array.isArray(result) ? result : [];
    },

    async deleteUser(id) {
        const result = await this.api('auth.php', 'POST', { 
            action: 'delete_user',
            id: id
        });
        return result;
    },

    // --- Signals ---
    async getSignals() {
        // Returns array of signals
        const signals = await this.api('signals.php', 'GET');
        return Array.isArray(signals) ? signals : [];
    },

    async getActiveSignalsCount() {
        const signals = await this.getSignals();
        return signals.filter(s => s.status === 'Running' || s.status === 'Active').length;
    },

    async getWinRate() {
        const signals = await this.getSignals();
        const closedSignals = signals.filter(s => s.status !== 'Active' && s.status !== 'Running' && s.status !== 'Pending');
        
        if (closedSignals.length === 0) return '0%';
        
        const wins = closedSignals.filter(s => 
            s.status.toLowerCase().includes('profit') || 
            s.status.toLowerCase().includes('won') || 
            s.status.toLowerCase().includes('tp')
        ).length;

        return Math.round((wins / closedSignals.length) * 100) + '%';
    },

    // --- Signals (Admin) ---
    async addSignal(signal) {
        // Normalize keys for API
        const payload = {
            pair: signal.pair,
            type: signal.type,
            entry_price: signal.entry ?? signal.entry_price,
            stop_loss: signal.sl ?? signal.stop_loss,
            take_profit: signal.tp ?? signal.take_profit,
            status: signal.status ?? 'Running'
        };
        const result = await this.api('signals.php', 'POST', payload);
        return result;
    },

    // --- Plans ---
    async upgradePlan(plan) {
        const user = this.getCurrentUser();
        if (!user) {
            window.location.href = 'login.html';
            return;
        }
        const result = await this.api('auth.php', 'POST', { action: 'update_plan', id: user.id, plan });
        if (result.success) {
            user.plan = plan;
            this.setCurrentUser(user);
        }
        return result;
    },

    // --- Utilities ---
    formatDate(isoString) {
        return new Date(isoString).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    },

    getPairIcon(pair) {
        if (pair.includes('EUR')) return 'https://flagcdn.com/20x15/eu.png';
        if (pair.includes('USD')) return 'https://flagcdn.com/20x15/us.png';
        if (pair.includes('GBP')) return 'https://flagcdn.com/20x15/gb.png';
        if (pair.includes('JPY')) return 'https://flagcdn.com/20x15/jp.png';
        if (pair.includes('XAU')) return 'https://flagcdn.com/20x15/us.png'; 
        if (pair.includes('BTC')) return 'https://cryptologos.cc/logos/bitcoin-btc-logo.png?v=025';
        return 'https://flagcdn.com/20x15/un.png';
    },

    // --- Courses ---
    async getCourses() {
        const result = await this.api('courses.php', 'GET');
        return Array.isArray(result) ? result : [];
    },

    async uploadCourse(formData) {
        const res = await fetch('api/courses.php', { method: 'POST', body: formData });
        return await res.json();
    },

    // --- Payment Methods ---
    async initiatePayment(method, plan = 'pro') {
        this.currentPaymentPlan = plan; // Store plan context
        const amountInUSD = plan === 'elite' ? 199 : 49;
        const planName = plan === 'elite' ? 'Elite Plan' : 'Pro Plan';

        if (method === 'paystack') {
            if (typeof PaystackPop === 'undefined') {
                alert('Paystack is unable to load. Please check your internet connection or try again later.');
                return;
            }
            const user = this.getCurrentUser();
            if (!user) {
                alert('Please login first to make a payment.');
                window.location.href = 'login.html';
                return;
            }

            // Public Key is safe to be on the frontend
            const publicKey = 'pk_live_764b7c6590906e7aade5d4baac08b7d711b'; // Live Public Key

            // Exchange rate: adjust as needed
            const exchangeRate = 1600; 
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
                            value: `${planName} ($${amountInUSD})`
                        }
                    ],
                    plan_code: plan
                },
                onClose: function() {
                    alert('Transaction was not completed, window closed.');
                },
                callback: async (response) => {
                    try {
                        const verifyRes = await fetch('http://localhost:3000/api/verify-payment', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ reference: response.reference })
                        });
                        const verifyData = await verifyRes.json();
                        if (!verifyRes.ok || !verifyData.status) {
                            alert(verifyData.message || 'Payment verification failed');
                            return;
                        }
                        const update = await this.api('auth.php', 'POST', { action: 'update_plan', id: user.id, plan });
                        if (update && update.success) {
                            user.plan = plan;
                            this.setCurrentUser(user);
                            alert('Payment verified. Your plan is now ' + plan.toUpperCase());
                            window.location.href = 'dashboard.html?payment=success&plan=' + encodeURIComponent(plan);
                        } else {
                            alert(update.message || 'Verified, but failed to update plan. Contact support.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Network error during verification. Please contact support with your reference: ' + response.reference);
                    }
                }
            });

            handler.openIframe();

        } else if (method === 'crypto') {
            const cryptoModal = new bootstrap.Modal(document.getElementById('cryptoPaymentModal'));
            // Update modal text
            const amountEl = document.getElementById('cryptoAmount');
            if(amountEl) amountEl.textContent = `$${amountInUSD}.00`;

            cryptoModal.show();
        }
    },

    copyToClipboard(elementId) {
        const copyText = document.getElementById(elementId);
        copyText.select();
        copyText.setSelectionRange(0, 99999); 
        navigator.clipboard.writeText(copyText.value).then(() => {
            alert("Address copied to clipboard!");
        });
    },

    async submitCryptoPayment(event) {
        event.preventDefault();
        const user = this.getCurrentUser();
        if (!user) {
            alert('Please login first.');
            window.location.href = 'login.html';
            return;
        }

        const plan = this.currentPaymentPlan || 'pro'; // Default to pro if lost
        const amount = plan === 'elite' ? 199 : 49;

        const fileInput = document.getElementById('paymentProof');
        const file = fileInput.files[0];
        if (!file) {
            alert('Please upload a screenshot of your payment.');
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            const pending = JSON.parse(localStorage.getItem('ppa_crypto_pending') || '[]');
            const item = {
                id: Date.now(),
                userId: user.id,
                userName: user.name,
                amount: amount,
                plan: plan,
                proof: reader.result,
                date: new Date().toISOString()
            };
            pending.unshift(item);
            localStorage.setItem('ppa_crypto_pending', JSON.stringify(pending));
            alert('Payment submitted for verification. You will be upgraded after admin approval.');
            const modalEl = document.getElementById('cryptoPaymentModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        };
        reader.readAsDataURL(file);
    },

    // --- Ticket Methods (Still LocalStorage for now) ---
    getTickets() {
        return JSON.parse(localStorage.getItem('ppa_tickets') || '[]');
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
        localStorage.setItem('ppa_tickets', JSON.stringify(tickets));
        return newTicket;
    }
};

// Initialize App on load
App.init();

// Admin Tag Logic for Settings Page
document.addEventListener('DOMContentLoaded', () => {
    const user = App.getCurrentUser();
    if (user) {
        // Update Admin Badge if elements exist
        const roleBadgeText = document.getElementById('userRoleText');
        const roleBadge = document.getElementById('userRoleBadge');
        
        if (roleBadgeText || roleBadge) {
            let roleLabel = 'Student'; // Default
            if (user.role === 'admin') roleLabel = 'Administrator';
            else if (user.role === 'user') roleLabel = 'Student'; // Or Pro Member based on other flags
            
            // Override for "Pro Member" if that's the default expectation for paid users
            // For now, let's distinguish Admin vs Student
            
            if (roleBadgeText) roleBadgeText.textContent = roleLabel;
            if (roleBadge) {
                roleBadge.textContent = roleLabel;
                if (user.role === 'admin') {
                    roleBadge.classList.remove('bg-purple');
                    roleBadge.classList.add('bg-danger');
                } else {
                     roleBadge.classList.remove('bg-danger');
                     roleBadge.classList.add('bg-purple');
                }
            }
        }
    }
});
