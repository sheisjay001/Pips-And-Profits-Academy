# Pips and Profits Academy 🚀

Pips and Profits Academy is a world-class trading education platform designed to empower traders with real-time signals, comprehensive courses, and advanced performance analysis tools.

## Key Features

### 1. AI-Powered Performance Auditor (New!)
Unlike traditional academies, we don't just tell you what to do; we identify why you are personally failing. This digital mentor analyzes your trade history to uncover psychological biases and bad habits.

#### Features of the AI Performance Auditor:
- **Trading Overview**: Net Profit, Win Rate, Total Trades, and Profit Factor (over any time period (All Time, Last 7 Days, Last 30 Days, Last 90 Days).
- **Clarity Card**: Displays your Max Drawdown, Peak Equity, and Current Equity with risk assessment.
- **AI Insights**:
  - Biggest recurring mistake in your last 50 trades.
  - Most and least profitable trading sessions.
  - Most and least profitable currency pairs.
  - Analysis of your last trade's reward vs. potential.
- **Trading History & Journal**: Complete trade-by-trade breakdown, with ability to add notes to each trade.
- **Detected Biases & Bad Habits**: Flags issues like Friday Overtrading, Late-Night Fatigue, etc.

#### How to Use the AI Performance Auditor:
1.  **Export Your Trade History:**
    -   Open your **MetaTrader 4 (MT4)** or **MetaTrader 5 (MT5)** terminal.
    -   Go to the **Account History** tab.
    -   Right-click anywhere in the history list.
    -   Select **"Save as Report"** (for HTML/CSV) or **"Export to CSV"**.
2.  **Upload to the Academy:**
    -   Navigate to the [AI Performance Auditor](auditor.html) menu in your dashboard.
    -   Click the **"Select CSV Export"** button and choose your exported file.
    -   Click **"Upload & Analyze"**.
3.  **Understand Your Report:**
    -   **Win Rate & Profit:** Real-time stats from your actual trades.
    -   **Detected Biases:** Our AI flags issues like "Friday Overtrading," "Late-Night Fatigue," or "Gold Volatility Issues."
    -   **Add Notes to Trades:** Use the journal feature to document your thoughts on each trade.

### 2. Premium Trading Signals
Get high-probability trade setups directly on your dashboard.
- **Signal Details**: Includes Entry Price, Stop Loss, and Take Profit.
- **Real-Time Status Updates**: Pending, Running, Hit TP, Hit SL.
- **Admin Dashboard for Signal Management**: Create, edit, and delete signals.
- **WhatsApp Community Broadcast**: Generate pre-formatted signal messages for quick sharing to your WhatsApp community.

### 3. Professional Affiliate Program
Earn commissions by referring new students.
- **Tiered Commissions**: Earn up to 75% commission based on your referral volume.
- **Real-Time Tracking**: Monitor clicks, conversions, and earnings instantly on your affiliate dashboard.
- **Leaderboard**: Compete with top performers for extra rewards.

### 4. Courses & Education
- Structured lessons to improve your trading skills.

### 5. Account Features
- User registration and login.
- Email verification.
- Profile management (including phone number, bio, and profile picture).
- Settings page to update your account info.
- Forgot password functionality.

---

## Technical Setup

### Prerequisites
- **XAMPP** (or any PHP/MySQL server).
- **Node.js** (for payment verification server, optional).

### Installation
1. **Database Configuration:**
   - Import `database.sql` into your MySQL/TiDB database.
   - Configure your database credentials in `api/db_connect.php` (use `api/db_connect.sample.php` as a template).
   - The app will auto-migrates the users table to add missing columns like phone, bio, etc., on first run.

2. **Payment Server (Node.js, optional):
   - Navigate to the `backend/` folder.
   - Run `npm install`.
   - Create a `.env` file based on `.env.example` and add your **Paystack Secret Key**.
   - Start the server with `npm start`.

3. **Frontend:**
   - Ensure `js/app.js` has the correct `API_BASE_URL` if you are hosting on a custom domain.
   - For Vercel deployment, ensure the `vercel.json` is configured.

---

## Deployment
The project is configured for deployment on **Vercel** with a PHP-compatible runtime.
- Ensure your database is hosted on a cloud provider like **TiDB Cloud** or **PlanetScale**.
- Update all environment variables in the Vercel dashboard.

---

## License
© 2026 Pips and Profits Academy. All Rights Reserved.
