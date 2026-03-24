// Affiliate Admin Dashboard JavaScript
const AffiliateAdmin = {
    adminData: {
        affiliates: [],
        referrals: [],
        payouts: []
    },
    charts: {
        commissions: null,
        performers: null
    },

    async init() {
        // Ensure user is admin
        const user = App.getCurrentUser();
        if (!user || user.role !== 'admin') {
            window.location.href = 'login.html';
            return;
        }

        this.updateAdminNav(user);
        this.setupEventListeners();
        await this.loadData();
    },

    updateAdminNav(user) {
        const nameEl = document.getElementById('adminName');
        const avatarEl = document.getElementById('adminAvatar');
        if (nameEl) nameEl.textContent = user.name;
        if (avatarEl) {
            avatarEl.src = user.profile_picture || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=212529&color=fff`;
        }
    },

    setupEventListeners() {
        document.getElementById('affiliateSearch')?.addEventListener('input', () => this.filterAffiliates());
        document.getElementById('affiliateStatusFilter')?.addEventListener('change', () => this.filterAffiliates());
        document.getElementById('payoutStatusFilter')?.addEventListener('change', () => this.filterPayouts());
        
        // Sidebar toggle
        document.getElementById('sidebarToggle')?.addEventListener('click', e => {
            e.preventDefault();
            document.getElementById('wrapper').classList.toggle('toggled');
        });
    },

    async loadData() {
        try {
            const [affRes, refRes, payRes] = await Promise.all([
                App.api('affiliate-admin.php?action=get_all_affiliates'),
                App.api('affiliate-admin.php?action=get_all_referrals'),
                App.api('affiliate-admin.php?action=get_all_payouts')
            ]);

            if (affRes.setup_required) {
                this.showSetupRequired();
                return;
            }

            if (affRes.success) this.adminData.affiliates = affRes.affiliates || [];
            if (refRes.success) this.adminData.referrals = refRes.referrals || [];
            if (payRes.success) this.adminData.payouts = payRes.payouts || [];

            this.updateUI();
        } catch (error) {
            console.error('Error loading admin data:', error);
            this.showAlert('Failed to load affiliate data', 'danger');
        }
    },

    showSetupRequired() {
        const container = document.querySelector('.container-fluid.p-4');
        if (container) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <div class="card border-0 shadow-sm mx-auto" style="max-width: 600px;">
                        <div class="card-body p-5">
                            <i class="fa-solid fa-screwdriver-wrench fa-4x text-danger mb-4"></i>
                            <h2 class="fw-bold">Database Migration Required</h2>
                            <p class="text-muted mb-4">The affiliate database tables have not been created yet on this server environment.</p>
                            <div class="bg-light p-3 rounded mb-4 text-start">
                                <p class="small fw-bold mb-2 text-uppercase">How to fix:</p>
                                <ol class="small mb-0">
                                    <li>Ensure your production database credentials are correct.</li>
                                    <li>Run the <code>run_affiliate_migration.php</code> script via browser or CLI.</li>
                                    <li>Verify the <code>affiliate_users</code> table exists.</li>
                                </ol>
                            </div>
                            <button class="btn btn-dark" onclick="location.reload()">
                                <i class="fa-solid fa-sync me-2"></i>Retry Connection
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }
    },

    updateUI() {
        this.updateStats();
        this.renderAffiliatesTable();
        this.renderReferralsTable();
        this.renderPayoutsTable();
        this.initCharts();
    },

    updateStats() {
        const totalAffs = this.adminData.affiliates.length;
        const totalRefs = this.adminData.referrals.length;
        const totalComms = this.adminData.referrals.reduce((sum, r) => sum + (parseFloat(r.commission_amount) || 0), 0);
        const pendingPays = this.adminData.payouts
            .filter(p => p.status === 'pending')
            .reduce((sum, p) => sum + (parseFloat(p.amount) || 0), 0);

        document.getElementById('totalAffiliates').textContent = totalAffs;
        document.getElementById('totalReferrals').textContent = totalRefs;
        document.getElementById('totalCommissions').textContent = '$' + totalComms.toFixed(2);
        document.getElementById('pendingPayouts').textContent = '$' + pendingPays.toFixed(2);
    },

    renderAffiliatesTable(data = null) {
        const tbody = document.getElementById('affiliatesTableBody');
        const affiliates = data || this.adminData.affiliates;
        
        if (!affiliates.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No affiliates found</td></tr>';
            return;
        }

        tbody.innerHTML = affiliates.map(aff => `
            <tr>
                <td class="ps-3">
                    <div class="d-flex align-items-center">
                        <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(aff.name)}&background=6f42c1&color=fff" 
                             class="rounded-circle me-2" width="32" height="32">
                        <div>
                            <div class="fw-bold">${aff.name}</div>
                            <small class="text-muted">${aff.email}</small>
                        </div>
                    </div>
                </td>
                <td><code>${aff.affiliate_code}</code></td>
                <td>$${(parseFloat(aff.total_earnings) || 0).toFixed(2)}</td>
                <td>$${(parseFloat(aff.current_balance) || 0).toFixed(2)}</td>
                <td>${aff.referral_count || 0}</td>
                <td><span class="badge bg-${this.getStatusColor(aff.status)}">${aff.status}</span></td>
                <td class="text-end pe-3">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-purple" onclick="toggleAffiliateStatus(${aff.id}, '${aff.status}')" title="Toggle Status">
                            <i class="fa-solid fa-${aff.status === 'active' ? 'pause' : 'play'}"></i>
                        </button>
                        <button class="btn btn-outline-info" onclick="viewAffiliateDetails(${aff.id})" title="View Details">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    },

    renderReferralsTable() {
        const tbody = document.getElementById('referralsTableBody');
        const referrals = this.adminData.referrals;

        if (!referrals.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No referrals found</td></tr>';
            return;
        }

        tbody.innerHTML = referrals.map(ref => `
            <tr>
                <td class="ps-3">
                    <div class="fw-bold">${ref.referred_name}</div>
                    <small class="text-muted">${ref.referred_email}</small>
                </td>
                <td>${ref.affiliate_name}</td>
                <td><code>${ref.referral_code}</code></td>
                <td>$${(parseFloat(ref.commission_amount) || 0).toFixed(2)}</td>
                <td>${App.formatDate(ref.signup_date)}</td>
                <td><span class="badge bg-${this.getStatusColor(ref.status)}">${ref.status}</span></td>
            </tr>
        `).join('');
    },

    renderPayoutsTable(data = null) {
        const tbody = document.getElementById('payoutsTableBody');
        const payouts = data || this.adminData.payouts;

        if (!payouts.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No payouts found</td></tr>';
            return;
        }

        tbody.innerHTML = payouts.map(p => `
            <tr>
                <td class="ps-3 fw-bold">${p.affiliate_name}</td>
                <td>$${(parseFloat(p.amount) || 0).toFixed(2)}</td>
                <td>${App.formatDate(p.payout_date)}</td>
                <td><span class="badge bg-${this.getStatusColor(p.status)}">${p.status}</span></td>
                <td>${p.processed_date ? App.formatDate(p.processed_date) : '<span class="text-muted">N/A</span>'}</td>
                <td class="text-end pe-3">
                    <div class="btn-group btn-group-sm">
                        ${p.status === 'pending' ? `
                            <button class="btn btn-outline-success" onclick="updatePayoutStatus(${p.id}, 'processing')" title="Start Processing">
                                <i class="fa-solid fa-play"></i>
                            </button>
                        ` : ''}
                        ${p.status === 'processing' ? `
                            <button class="btn btn-outline-success" onclick="updatePayoutStatus(${p.id}, 'completed')" title="Mark Completed">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-outline-info" onclick="viewPayoutDetails(${p.id})" title="View Details">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    },

    getStatusColor(status) {
        switch(status.toLowerCase()) {
            case 'active': case 'confirmed': case 'completed': return 'success';
            case 'pending': case 'processing': return 'warning';
            case 'suspended': case 'cancelled': return 'danger';
            default: return 'secondary';
        }
    },

    initCharts() {
        this.initCommissionsChart();
        this.initPerformersChart();
    },

    initCommissionsChart() {
        const ctx = document.getElementById('monthlyCommissionsChart');
        if (!ctx) return;
        
        if (this.charts.commissions) this.charts.commissions.destroy();

        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const labels = [];
        const data = [];
        const now = new Date();

        for (let i = 5; i >= 0; i--) {
            const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
            labels.push(months[d.getMonth()]);
            
            const monthTotal = this.adminData.referrals
                .filter(r => {
                    const rDate = new Date(r.signup_date);
                    return rDate.getMonth() === d.getMonth() && rDate.getFullYear() === d.getFullYear();
                })
                .reduce((sum, r) => sum + (parseFloat(r.commission_amount) || 0), 0);
            data.push(monthTotal);
        }

        this.charts.commissions = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Commissions ($)',
                    data,
                    borderColor: '#6f42c1',
                    backgroundColor: 'rgba(111, 66, 193, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    },

    initPerformersChart() {
        const ctx = document.getElementById('topPerformersChart');
        if (!ctx) return;

        if (this.charts.performers) this.charts.performers.destroy();

        const top = [...this.adminData.affiliates]
            .sort((a, b) => (parseFloat(b.total_earnings) || 0) - (parseFloat(a.total_earnings) || 0))
            .slice(0, 5);

        this.charts.performers = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: top.map(a => a.name.split(' ')[0]),
                datasets: [{
                    label: 'Earnings ($)',
                    data: top.map(a => parseFloat(a.total_earnings) || 0),
                    backgroundColor: '#6f42c1'
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    },

    async toggleAffiliateStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'suspended' : 'active';
        if (!confirm(`Are you sure you want to ${newStatus} this affiliate?`)) return;

        try {
            const res = await App.api('affiliate-admin.php', 'POST', {
                action: 'update_affiliate_status',
                affiliate_id: id,
                status: newStatus
            });
            if (res.success) {
                this.showAlert('Status updated!', 'success');
                this.loadData();
            } else {
                this.showAlert(res.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Update failed', 'danger');
        }
    },

    async viewAffiliateDetails(id) {
        try {
            const result = await App.api(`affiliate-admin.php?action=get_affiliate_details&id=${id}`);
            if (!result.success) {
                this.showAlert(result.message, 'danger');
                return;
            }

            const { affiliate, bank, referrals } = result;
            const content = document.getElementById('affiliateDetailsContent');
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-4 text-center mb-4">
                        <img src="${affiliate.profile_picture || `https://ui-avatars.com/api/?name=${encodeURIComponent(affiliate.name)}&background=6f42c1&color=fff`}" 
                             class="rounded-circle mb-3" width="120" height="120">
                        <h5 class="fw-bold mb-1">${affiliate.name}</h5>
                        <p class="text-muted small">${affiliate.email}</p>
                        <span class="badge bg-${this.getStatusColor(affiliate.status)} mb-3">${affiliate.status}</span>
                        ${affiliate.status === 'pending' ? `
                            <div class="d-grid gap-2">
                                <button class="btn btn-success btn-sm" onclick="toggleAffiliateStatus(${affiliate.id}, 'pending')">Approve Affiliate</button>
                            </div>
                        ` : ''}
                    </div>
                    <div class="col-md-8">
                        <h6 class="text-muted small text-uppercase fw-bold mb-3">Affiliate Info</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="text-muted small d-block">Affiliate Code</label>
                                <code>${affiliate.affiliate_code}</code>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">Total Earnings</label>
                                <strong>$${(parseFloat(affiliate.total_earnings) || 0).toFixed(2)}</strong>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">Current Balance</label>
                                <strong>$${(parseFloat(affiliate.current_balance) || 0).toFixed(2)}</strong>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">Referral Count</label>
                                <strong>${affiliate.referral_count || 0}</strong>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted small text-uppercase fw-bold mb-0">Bank Details</h6>
                            ${bank ? `
                                <span class="badge bg-${bank.is_verified ? 'success' : 'warning'}">
                                    ${bank.is_verified ? 'Verified' : 'Pending Verification'}
                                </span>
                            ` : ''}
                        </div>
                        ${bank ? `
                            <div class="card bg-light border-0 mb-4">
                                <div class="card-body p-3">
                                    <div class="row g-2">
                                        <div class="col-6 small"><strong>Bank:</strong> ${bank.bank_name}</div>
                                        <div class="col-6 small"><strong>Account:</strong> ${bank.account_number}</div>
                                        <div class="col-6 small"><strong>Name:</strong> ${bank.account_name}</div>
                                        <div class="col-6 small"><strong>Country:</strong> ${bank.country}</div>
                                        <div class="col-6 small"><strong>Currency:</strong> ${bank.currency}</div>
                                        <div class="col-6 small"><strong>Swift/BIC:</strong> ${bank.swift_code || 'N/A'}</div>
                                    </div>
                                    ${!bank.is_verified ? `
                                        <div class="mt-3 text-end">
                                            <button class="btn btn-purple btn-sm" onclick="verifyBankAccount(${bank.id})">
                                                <i class="fa-solid fa-check-circle me-1"></i> Verify Bank Details
                                            </button>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        ` : '<p class="text-muted small mb-4">No bank account linked.</p>'}

                        <h6 class="text-muted small text-uppercase fw-bold mb-3">Recent Referrals</h6>
                        <div class="table-responsive">
                            <table class="table table-sm small">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${referrals.length ? referrals.map(r => `
                                        <tr>
                                            <td>${r.referred_name}</td>
                                            <td>${App.formatDate(r.signup_date)}</td>
                                            <td><span class="badge bg-${this.getStatusColor(r.status)}">${r.status}</span></td>
                                        </tr>
                                    `).join('') : '<tr><td colspan="3" class="text-center">No referrals yet</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;

            new bootstrap.Modal(document.getElementById('affiliateDetailsModal')).show();
        } catch (error) {
            console.error('Error fetching details:', error);
            this.showAlert('Failed to load details', 'danger');
        }
    },

    async verifyBankAccount(bankAccountId) {
        if (!confirm('Are you sure you want to verify these bank details?')) return;

        try {
            const res = await App.api('affiliate-admin.php', 'POST', {
                action: 'verify_bank_account',
                bank_account_id: bankAccountId
            });
            if (res.success) {
                this.showAlert('Bank account verified!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('affiliateDetailsModal')).hide();
                this.loadData();
            } else {
                this.showAlert(res.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Verification failed', 'danger');
        }
    },

    async viewPayoutDetails(id) {
        try {
            const result = await App.api(`affiliate-admin.php?action=get_payout_details&id=${id}`);
            if (!result.success) {
                this.showAlert(result.message, 'danger');
                return;
            }

            const { payout } = result;
            const content = document.getElementById('payoutDetailsContent');
            const actionButtons = document.getElementById('payoutActionButtons');
            
            content.innerHTML = `
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-muted small text-uppercase fw-bold mb-0">Payout Information</h6>
                        <span class="badge bg-${this.getStatusColor(payout.status)}">${payout.status}</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="text-muted small d-block">Affiliate</label>
                            <strong>${payout.affiliate_name}</strong>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">Amount</label>
                            <strong class="text-success">$${parseFloat(payout.amount).toFixed(2)}</strong>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">Requested Date</label>
                            <span>${App.formatDate(payout.payout_date)}</span>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">Processed Date</label>
                            <span>${payout.processed_date ? App.formatDate(payout.processed_date) : 'N/A'}</span>
                        </div>
                    </div>
                </div>

                <div class="mb-0">
                    <h6 class="text-muted small text-uppercase fw-bold mb-3">Banking Details</h6>
                    <div class="card bg-light border-0">
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <div class="col-12 mb-2 border-bottom pb-2">
                                    <label class="text-muted small d-block">Bank Name</label>
                                    <strong>${payout.bank_name || 'N/A'}</strong>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small d-block">Account Name</label>
                                    <strong>${payout.account_name || 'N/A'}</strong>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small d-block">Account Number</label>
                                    <strong>${payout.account_number || 'N/A'}</strong>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small d-block">SWIFT/BIC</label>
                                    <strong>${payout.swift_code || 'N/A'}</strong>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small d-block">Country</label>
                                    <strong>${payout.country || 'N/A'}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Update action buttons based on status
            actionButtons.innerHTML = '';
            if (payout.status === 'pending') {
                actionButtons.innerHTML = `
                    <button class="btn btn-primary" onclick="updatePayoutStatus(${payout.id}, 'processing'); bootstrap.Modal.getInstance(document.getElementById('payoutDetailsModal')).hide();">
                        <i class="fa-solid fa-play me-1"></i> Start Processing
                    </button>
                `;
            } else if (payout.status === 'processing') {
                actionButtons.innerHTML = `
                    <button class="btn btn-success" onclick="updatePayoutStatus(${payout.id}, 'completed'); bootstrap.Modal.getInstance(document.getElementById('payoutDetailsModal')).hide();">
                        <i class="fa-solid fa-check me-1"></i> Mark Completed
                    </button>
                `;
            }

            new bootstrap.Modal(document.getElementById('payoutDetailsModal')).show();
        } catch (error) {
            console.error('Error fetching payout details:', error);
            this.showAlert('Failed to load payout details', 'danger');
        }
    },

    async updatePayoutStatus(id, status) {
        if (!confirm(`Mark payout as ${status}?`)) return;

        try {
            const res = await App.api('affiliate-admin.php', 'POST', {
                action: 'update_payout_status',
                payout_id: id,
                status
            });
            if (res.success) {
                this.showAlert('Payout updated!', 'success');
                this.loadData();
            } else {
                this.showAlert(res.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Update failed', 'danger');
        }
    },

    async processMonthlyPayouts() {
        if (!confirm('This will process all pending commissions and generate payout requests. Continue?')) return;

        try {
            const res = await App.api('affiliate_commissions.php', 'POST', {
                action: 'process_monthly_payouts'
            });
            if (res.success) {
                this.showAlert(res.message || 'Payouts processed!', 'success');
                this.loadData();
            } else {
                this.showAlert(res.message, 'danger');
            }
        } catch (error) {
            this.showAlert('Process failed', 'danger');
        }
    },

    filterAffiliates() {
        const q = document.getElementById('affiliateSearch').value.toLowerCase();
        const s = document.getElementById('affiliateStatusFilter').value;
        
        const filtered = this.adminData.affiliates.filter(a => {
            const matchesQ = a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q) || a.affiliate_code.toLowerCase().includes(q);
            const matchesS = !s || a.status === s;
            return matchesQ && matchesS;
        });
        
        this.renderAffiliatesTable(filtered);
    },

    filterPayouts() {
        const s = document.getElementById('payoutStatusFilter').value;
        const filtered = this.adminData.payouts.filter(p => !s || p.status === s);
        this.renderPayoutsTable(filtered);
    },

    showAlert(message, type) {
        const container = document.getElementById('alertContainer');
        if (!container) return;
        const div = document.createElement('div');
        div.className = `alert alert-${type} alert-dismissible fade show shadow-sm`;
        div.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        container.appendChild(div);
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(div).close(), 5000);
    }
};

// Global wrappers
window.toggleAffiliateStatus = (id, status) => AffiliateAdmin.toggleAffiliateStatus(id, status);
window.updatePayoutStatus = (id, status) => AffiliateAdmin.updatePayoutStatus(id, status);
window.processMonthlyPayouts = () => AffiliateAdmin.processMonthlyPayouts();
window.viewAffiliateDetails = (id) => AffiliateAdmin.viewAffiliateDetails(id);
window.viewPayoutDetails = (id) => AffiliateAdmin.viewPayoutDetails(id);
window.verifyBankAccount = (id) => AffiliateAdmin.verifyBankAccount(id);

// Init
document.addEventListener('DOMContentLoaded', () => AffiliateAdmin.init());
