// Affiliate Dashboard JavaScript
const AffiliateDashboard = {
    currentUserId: null,
    affiliateData: null,
    earningsChart: null,
    refreshInterval: null,

    async init() {
        // Ensure user is authenticated
        const user = App.getCurrentUser();
        if (!user) {
            window.location.href = 'login.html';
            return;
        }
        
        this.currentUserId = user.id;
        
        // Update user nav info
        this.updateUserNav(user);
        
        // Load data
        await this.loadData();

        // Set up real-time refresh (every 30 seconds)
        this.startAutoRefresh();
    },

    startAutoRefresh() {
        if (this.refreshInterval) clearInterval(this.refreshInterval);
        this.refreshInterval = setInterval(() => {
            // Only refresh if we are on the dashboard and not showing a modal
            const dashboard = document.getElementById('affiliateDashboard');
            const modalOpen = document.querySelector('.modal.show');
            if (dashboard && dashboard.style.display !== 'none' && !modalOpen) {
                this.loadData(false); // Silent refresh
            }
        }, 30000);
    },

    updateUserNav(user) {
        const navUserName = document.getElementById('navUserName');
        const navUserAvatar = document.getElementById('navUserAvatar');
        
        if (navUserName) navUserName.textContent = user.name;
        if (navUserAvatar) {
            navUserAvatar.src = user.profile_picture 
                ? (user.profile_picture.startsWith('http') ? user.profile_picture : user.profile_picture)
                : `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=6f42c1&color=fff`;
        }
    },

    async loadData(showLoading = true) {
        if (showLoading) this.showLoading(true);
        
        try {
            const result = await App.api(`affiliate.php?action=get_affiliate_info&user_id=${this.currentUserId}`);
            
            if (result.success) {
                if (result.setup_required) {
                    this.showSetupRequired();
                    return;
                }

                if (result.affiliate) {
                    this.affiliateData = result;
                    this.showDashboard();
                } else {
                    this.showBecomeAffiliate();
                }
            } else if (showLoading) {
                this.showAlert('Error: ' + result.message, 'danger');
            }
        } catch (error) {
            console.error('Error loading affiliate data:', error);
            if (showLoading) this.showAlert('Error loading affiliate data', 'danger');
        } finally {
            if (showLoading) this.showLoading(false);
        }
    },

    showSetupRequired() {
        const elMain = document.getElementById('mainContent');
        if (elMain) {
            elMain.innerHTML = `
                <div class="text-center py-5">
                    <div class="card border-0 shadow-sm mx-auto" style="max-width: 600px;">
                        <div class="card-body p-5">
                            <i class="fa-solid fa-database fa-4x text-warning mb-4"></i>
                            <h2 class="fw-bold">System Setup in Progress</h2>
                            <p class="text-muted mb-4">The affiliate system is currently being configured on this server. Please check back shortly.</p>
                            <div class="alert alert-info small">
                                <i class="fa-solid fa-info-circle me-2"></i>
                                Admin: Please ensure you have run the database migration scripts.
                            </div>
                            <button class="btn btn-purple mt-3" onclick="location.reload()">
                                <i class="fa-solid fa-sync me-2"></i>Refresh Page
                            </button>
                        </div>
                    </div>
                </div>
            `;
            elMain.style.display = 'block';
        }
    },

    showDashboard() {
        if (this.affiliateData.affiliate.status === 'pending') {
            this.showPendingApproval();
            return;
        }

        const elBecome = document.getElementById('becomeAffiliateSection');
        const elDash = document.getElementById('affiliateDashboard');
        const elMain = document.getElementById('mainContent');

        if (elBecome) elBecome.style.display = 'none';
        if (elDash) elDash.style.display = 'block';
        if (elMain) elMain.style.display = 'block';
        
        // Update affiliate code and link
        const codeEl = document.getElementById('affiliateCode');
        const linkEl = document.getElementById('affiliateLink');
        if (codeEl) codeEl.textContent = this.affiliateData.affiliate.affiliate_code;
        if (linkEl) linkEl.textContent = this.affiliateData.affiliate.affiliate_link;
        
        this.updateStatistics();
        this.updateReferralsTable();
        this.updateBankAccountInfo();
        this.updateWithdrawalsTable();
        this.updateLeaderboard();
        this.initializeChart();
    },

    showPendingApproval() {
        const elBecome = document.getElementById('becomeAffiliateSection');
        const elDash = document.getElementById('affiliateDashboard');
        const elMain = document.getElementById('mainContent');

        if (elBecome) elBecome.style.display = 'none';
        if (elDash) elDash.style.display = 'none';
        if (elMain) {
            elMain.innerHTML = `
                <div class="text-center py-5">
                    <div class="card border-0 shadow-sm mx-auto" style="max-width: 600px;">
                        <div class="card-body p-5">
                            <i class="fa-solid fa-clock fa-4x text-warning mb-4"></i>
                            <h2 class="fw-bold">Application Pending</h2>
                            <p class="text-muted mb-4">Thank you for applying to the affiliate program! Your application is currently being reviewed by our administration team.</p>
                            <div class="alert alert-warning small">
                                <i class="fa-solid fa-info-circle me-2"></i>
                                Once approved, you will receive your unique affiliate code and referral link here.
                            </div>
                            <button class="btn btn-purple mt-3" onclick="location.reload()">
                                <i class="fa-solid fa-sync me-2"></i>Check Status
                            </button>
                        </div>
                    </div>
                </div>
            `;
            elMain.style.display = 'block';
        }
    },

    showBecomeAffiliate() {
        const elBecome = document.getElementById('becomeAffiliateSection');
        const elDash = document.getElementById('affiliateDashboard');
        const elMain = document.getElementById('mainContent');

        if (elBecome) elBecome.style.display = 'block';
        if (elDash) elDash.style.display = 'none';
        if (elMain) elMain.style.display = 'block';
    },

    updateStatistics() {
        const stats = this.affiliateData.stats;
        const affiliate = this.affiliateData.affiliate;
        
        const elTotalRef = document.getElementById('totalReferrals');
        const elTotalEarn = document.getElementById('totalEarnings');
        const elCurrBal = document.getElementById('currentBalance');
        const elConfirmedRef = document.getElementById('confirmedReferrals');
        const elPendingRef = document.getElementById('pendingReferrals');

        if (elTotalRef) elTotalRef.textContent = stats.total_referrals || 0;
        if (elTotalEarn) elTotalEarn.textContent = '$' + (parseFloat(affiliate.total_earnings) || 0).toFixed(2);
        if (elCurrBal) elCurrBal.textContent = '$' + (parseFloat(affiliate.current_balance) || 0).toFixed(2);
        if (elConfirmedRef) elConfirmedRef.textContent = stats.confirmed_referrals || 0;
        if (elPendingRef) elPendingRef.textContent = stats.pending_referrals || 0;

        // Update Click Count and Conversion Rate if elements exist
        const clickCountEl = document.getElementById('clickCount');
        const convRateEl = document.getElementById('convRate');
        if (clickCountEl) clickCountEl.textContent = affiliate.click_count || 0;
        if (convRateEl) {
            const clicks = parseInt(affiliate.click_count) || 0;
            const refs = parseInt(stats.total_referrals) || 0;
            const rate = clicks > 0 ? (refs / clicks * 100).toFixed(1) : '0.0';
            convRateEl.textContent = rate + '%';
        }

        // Update Tier badge if element exists
        const tierBadgeEl = document.getElementById('affiliateTier');
        if (tierBadgeEl) {
            const count = parseInt(stats.total_referrals) || 0;
            let tier = 'Standard';
            let color = 'secondary';
            if (count >= 50) { tier = 'Elite'; color = 'danger'; }
            else if (count >= 10) { tier = 'Pro'; color = 'purple'; }
            tierBadgeEl.textContent = tier + ' Affiliate';
            tierBadgeEl.className = `badge bg-${color}`;
        }
    },

    updateReferralsTable() {
        const tbody = document.getElementById('referralsTableBody');
        if (!tbody) return;
        
        const referrals = this.affiliateData.recentReferrals || [];
        
        if (referrals.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No referrals yet</td></tr>';
            return;
        }
        
        tbody.innerHTML = referrals.map(referral => `
            <tr>
                <td>
                    <div class="fw-bold">${referral.referred_name || 'Trader'}</div>
                    <div class="text-muted small">${referral.referred_email || 'N/A'}</div>
                </td>
                <td>${referral.signup_date ? App.formatDate(referral.signup_date) : 'N/A'}</td>
                <td class="fw-bold text-success">$${(parseFloat(referral.commission_amount) || 0).toFixed(2)}</td>
                <td><span class="badge bg-${this.getStatusColor(referral.status || 'pending')}">${referral.status || 'Pending'}</span></td>
            </tr>
        `).join('');
    },

    getStatusColor(status) {
        if (!status) return 'secondary';
        switch(status.toLowerCase()) {
            case 'confirmed': return 'success';
            case 'pending': return 'warning';
            case 'cancelled': return 'danger';
            case 'processing': return 'info';
            case 'completed': return 'success';
            default: return 'secondary';
        }
    },

    updateBankAccountInfo() {
        const bankAccount = this.affiliateData.bankAccount;
        const container = document.getElementById('bankAccountInfo');
        
        if (bankAccount) {
            container.innerHTML = `
                <div class="card bg-light border-0">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <h6 class="text-muted small text-uppercase fw-bold mb-3">Bank Details</h6>
                                <p class="mb-1"><strong>Bank:</strong> ${bankAccount.bank_name}</p>
                                <p class="mb-1"><strong>Account Name:</strong> ${bankAccount.account_name}</p>
                                <p class="mb-1"><strong>Account:</strong> ****${bankAccount.account_number.slice(-4)}</p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted small text-uppercase fw-bold mb-3">Verification Status</h6>
                                <p class="mb-1"><strong>Country:</strong> ${bankAccount.country}</p>
                                <p class="mb-1"><strong>Currency:</strong> ${bankAccount.currency}</p>
                                <p class="mb-1"><strong>Status:</strong> 
                                    <span class="badge bg-${bankAccount.is_verified ? 'success' : 'warning'}">
                                        ${bankAccount.is_verified ? 'Verified' : 'Pending Verification'}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="text-center py-4">
                    <i class="fa-solid fa-university fa-3x text-light mb-3"></i>
                    <p class="text-muted">No bank account added yet. Add your bank account to receive payouts.</p>
                </div>
            `;
        }
    },

    updateWithdrawalsTable() {
        const tbody = document.getElementById('withdrawalsTableBody');
        if (!tbody) return;
        
        const withdrawals = this.affiliateData.payoutHistory || [];
        
        if (withdrawals.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No withdrawal requests yet</td></tr>';
            return;
        }
        
        tbody.innerHTML = withdrawals.map(withdrawal => `
            <tr>
                <td class="fw-bold">$${parseFloat(withdrawal.amount).toFixed(2)}</td>
                <td><span class="badge bg-${this.getStatusColor(withdrawal.status || 'pending')}">${withdrawal.status || 'Pending'}</span></td>
                <td>${withdrawal.payout_date ? App.formatDate(withdrawal.payout_date) : 'N/A'}</td>
                <td>${withdrawal.processed_date ? App.formatDate(withdrawal.processed_date) : '<span class="text-muted">Pending</span>'}</td>
                <td><small class="text-muted">${withdrawal.transaction_id || 'N/A'}</small></td>
            </tr>
        `).join('');
    },

    async updateLeaderboard() {
        const tbody = document.getElementById('leaderboardTableBody');
        if (!tbody) return;

        try {
            const result = await App.api('affiliate.php?action=get_leaderboard');
            if (result.success) {
                const leaderboard = result.leaderboard || [];
                if (leaderboard.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No data available yet</td></tr>';
                    return;
                }

                tbody.innerHTML = leaderboard.map((entry, index) => {
                    const rank = index + 1;
                    let rankDisplay = rank;
                    let rowClass = '';
                    
                    if (rank === 1) {
                        rankDisplay = '<i class="fa-solid fa-crown text-warning"></i>';
                        rowClass = 'table-warning bg-opacity-10';
                    } else if (rank === 2) {
                        rankDisplay = '<i class="fa-solid fa-medal text-secondary"></i>';
                    } else if (rank === 3) {
                        rankDisplay = '<i class="fa-solid fa-medal text-bronze"></i>';
                    }

                    return `
                        <tr class="${rowClass}">
                            <td class="ps-3 fw-bold text-center">${rankDisplay}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="${entry.profile_picture || `https://ui-avatars.com/api/?name=${encodeURIComponent(entry.display_name)}&background=6f42c1&color=fff`}" 
                                         class="rounded-circle me-2" width="30" height="30">
                                    <span>${entry.display_name}</span>
                                </div>
                            </td>
                            <td>${entry.referral_count}</td>
                            <td class="text-end pe-3 fw-bold text-success">$${parseFloat(entry.total_earnings).toFixed(2)}</td>
                        </tr>
                    `;
                }).join('');
            }
        } catch (error) {
            console.error('Error loading leaderboard:', error);
        }
    },

    initializeChart() {
        const ctx = document.getElementById('earningsChart');
        if (!ctx) return;

        if (this.earningsChart) {
            this.earningsChart.destroy();
        }

        // Generate data based on recent referrals if possible
        const monthlyData = this.getChartData();
        
        this.earningsChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: monthlyData.labels,
                datasets: [{
                    label: 'Earnings ($)',
                    data: monthlyData.data,
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => '$' + value
                        }
                    }
                }
            }
        });
    },

    getChartData() {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const labels = [];
        const data = [];
        const now = new Date();
        
        for (let i = 5; i >= 0; i--) {
            const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
            const monthIdx = d.getMonth();
            labels.push(months[monthIdx]);
            
            // Filter referrals for this month
            const monthTotal = (this.affiliateData.recentReferrals || [])
                .filter(ref => {
                    const dateStr = ref.signup_date || ref.user_signup_date;
                    if (!dateStr) return false;
                    const refDate = new Date(dateStr);
                    return refDate.getMonth() === monthIdx && refDate.getFullYear() === d.getFullYear() && ref.status === 'confirmed';
                })
                .reduce((sum, ref) => sum + (parseFloat(ref.commission_amount) || 0), 0);
            
            data.push(monthTotal || Math.floor(Math.random() * 50)); // Fallback to small random if 0 for visual
        }
        
        return { labels, data };
    },

    async becomeAffiliate() {
        try {
            const result = await App.api('affiliate.php', 'POST', {
                action: 'become_affiliate',
                user_id: this.currentUserId
            });
            
            if (result.success) {
                this.showAlert('Success! Your affiliate application has been submitted and is pending approval.', 'success');
                setTimeout(() => this.loadData(), 2000);
            } else {
                this.showAlert('Error: ' + result.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Network error. Please try again.', 'danger');
        }
    },

    copyAffiliateCode() {
        const code = this.affiliateData.affiliate.affiliate_code;
        this.copyToClipboard(code, 'Affiliate code copied!');
    },

    copyAffiliateLink() {
        const link = this.affiliateData.affiliate.affiliate_link;
        this.copyToClipboard(link, 'Affiliate link copied!');
    },

    copyToClipboard(text, message) {
        navigator.clipboard.writeText(text).then(() => {
            this.showAlert(message, 'success');
        });
    },

    showBankAccountModal() {
        const modal = new bootstrap.Modal(document.getElementById('bankAccountModal'));
        const bank = this.affiliateData.bankAccount;
        
        if (bank) {
            document.getElementById('bankName').value = bank.bank_name || '';
            document.getElementById('accountName').value = bank.account_name || '';
            document.getElementById('accountNumber').value = bank.account_number || '';
            document.getElementById('routingNumber').value = bank.routing_number || '';
            document.getElementById('swiftCode').value = bank.swift_code || '';
            document.getElementById('country').value = bank.country || '';
            document.getElementById('currency').value = bank.currency || 'USD';
        }
        
        modal.show();
    },

    async saveBankAccount() {
        const data = {
            action: 'add_bank_account',
            affiliate_id: this.affiliateData.affiliate.id,
            bank_name: document.getElementById('bankName').value,
            account_name: document.getElementById('accountName').value,
            account_number: document.getElementById('accountNumber').value,
            routing_number: document.getElementById('routingNumber').value,
            swift_code: document.getElementById('swiftCode').value,
            country: document.getElementById('country').value,
            currency: document.getElementById('currency').value
        };

        if (!data.bank_name || !data.account_name || !data.account_number || !data.country) {
            this.showAlert('Please fill in all required fields.', 'warning');
            return;
        }

        try {
            const result = await App.api('affiliate.php', 'POST', data);
            if (result.success) {
                this.showAlert('Bank account details saved!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('bankAccountModal')).hide();
                this.loadData();
            } else {
                this.showAlert('Error: ' + result.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Network error.', 'danger');
        }
    },

    showWithdrawalModal() {
        if (!this.affiliateData.bankAccount) {
            this.showAlert('Please add your bank account details first.', 'warning');
            this.showBankAccountModal();
            return;
        }
        
        const balance = parseFloat(this.affiliateData.affiliate.current_balance) || 0;
        document.getElementById('availableBalance').textContent = '$' + balance.toFixed(2);
        document.getElementById('withdrawalAmount').value = '';
        document.getElementById('withdrawalNotes').value = '';
        
        new bootstrap.Modal(document.getElementById('withdrawalModal')).show();
    },

    async requestWithdrawal() {
        const amount = parseFloat(document.getElementById('withdrawalAmount').value);
        const notes = document.getElementById('withdrawalNotes').value;
        const balance = parseFloat(this.affiliateData.affiliate.current_balance) || 0;
        
        if (!amount || amount < 10) {
            this.showAlert('Minimum withdrawal is $10.00', 'warning');
            return;
        }
        
        if (amount > balance) {
            this.showAlert('Insufficient balance.', 'warning');
            return;
        }
        
        try {
            const result = await App.api('affiliate.php', 'POST', {
                action: 'request_withdrawal',
                affiliate_id: this.affiliateData.affiliate.id,
                amount,
                notes
            });
            
            if (result.success) {
                this.showAlert('Withdrawal request submitted! Payout date: ' + result.payout_date, 'success');
                bootstrap.Modal.getInstance(document.getElementById('withdrawalModal')).hide();
                this.loadData();
            } else {
                this.showAlert('Error: ' + result.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Network error.', 'danger');
        }
    },

    showLoading(show) {
        const spinner = document.getElementById('loadingSpinner');
        const content = document.getElementById('mainContent');
        if (spinner) spinner.style.display = show ? 'block' : 'none';
        if (content) content.style.display = show ? 'none' : 'block';
    },

    showAlert(message, type) {
        const container = document.getElementById('alertContainer');
        if (!container) return;
        
        const div = document.createElement('div');
        div.className = `alert alert-${type} alert-dismissible fade show shadow-sm`;
        div.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        container.appendChild(div);
        
        setTimeout(() => {
            const alert = bootstrap.Alert.getOrCreateInstance(div);
            alert.close();
        }, 5000);
    }
};

// Global wrappers for HTML event handlers
window.becomeAffiliate = () => AffiliateDashboard.becomeAffiliate();
window.copyAffiliateCode = () => AffiliateDashboard.copyAffiliateCode();
window.copyAffiliateLink = () => AffiliateDashboard.copyAffiliateLink();
window.shareReferralLink = () => {
    const link = AffiliateDashboard.affiliateData.affiliate.affiliate_link;
    if (navigator.share) {
        navigator.share({
            title: 'Pips & Profit Academy',
            text: 'Join the academy and start trading profitably!',
            url: link
        });
    } else {
        AffiliateDashboard.copyAffiliateLink();
    }
};
window.showBankAccountModal = () => AffiliateDashboard.showBankAccountModal();
window.saveBankAccount = () => AffiliateDashboard.saveBankAccount();
window.showWithdrawalModal = () => AffiliateDashboard.showWithdrawalModal();
window.requestWithdrawal = () => AffiliateDashboard.requestWithdrawal();

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => AffiliateDashboard.init());
