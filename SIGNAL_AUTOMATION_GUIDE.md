# 🚀 Automated Signal Integration Guide

Pips and Profit Academy now supports automated signal execution and notifications. This allows you to bridge signals from your academy dashboard directly to MetaTrader 4/5, Telegram, or custom external tools—completely for free.

---

## 🔗 1. Signal Bridge (MetaTrader 4/5 Integration)
The Signal Bridge allows your MetaTrader platform to "listen" for new signals from your academy and execute them instantly.

### **Step-by-Step MetaTrader Setup:**

#### **A. Authorize the Academy Domain**
MetaTrader restricts external connections for security. You must explicitly allow it to talk to your academy:
1.  Open **MetaTrader 4** or **MetaTrader 5**.
2.  Go to **Tools > Options** (or press `Ctrl+O`).
3.  Click the **Expert Advisors** tab.
4.  Check the box: **"Allow WebRequest for listed URL"**.
5.  Double-click the green plus icon `(+)` and add your live academy URL:
    *   `https://pips-and-profits-academy.vercel.app`
6.  Click **OK**.

#### **B. Install a Signal Receiver EA**
Since MetaTrader doesn't have a built-in JSON reader, you need a "Receiver EA" (Expert Advisor):
1.  **Download/Obtain an EA**: Use any free "JSON Signal Receiver" or "Webhook to MT4" EA from the MQL5 Market or your community resources.
2.  **Install the EA**:
    *   In MetaTrader, go to **File > Open Data Folder**.
    *   Navigate to `MQL4/Experts` (or `MQL5/Experts`).
    *   Paste the `.ex4` or `.ex5` file there.
    *   Restart MetaTrader or right-click **Experts** in the Navigator and click **Refresh**.

#### **C. Link your Feed**
1.  Go to your Academy **Admin Dashboard > Global Settings**.
2.  Copy your unique **Signal Feed URL** (e.g., `https://.../api/signals.php?action=feed`).
3.  In MetaTrader, drag the **Signal Receiver EA** onto any chart (H1 timeframe recommended).
4.  In the EA's **Inputs** tab:
    *   Find the field labeled **"Signal URL"** or **"JSON Feed"**.
    *   Paste your copied **Signal Feed URL**.
    *   Set **"Check Frequency"** to `1 Minute` (or `60 seconds`).
5.  Ensure **"Algo Trading"** (or **"AutoTrading"**) is turned **ON** (Green) at the top of your MetaTrader window.

### **How it works:**
The EA will now "ping" your academy every minute. The moment you post a new signal, the EA will detect it, read the Entry, SL, and TP, and place the trade on your MetaTrader account automatically.

---

## 📢 2. Telegram Integration
Automatically push your academy signals to a Telegram Channel or Group.

### **How to setup:**
1.  **Create a Bot**: Message [@BotFather](https://t.me/botfather) on Telegram to create a new bot and get your **API Token**.
2.  **Get Chat ID**:
    *   Add your bot to your target Telegram Channel/Group.
    *   Make the bot an **Administrator**.
    *   Use a tool like [@IDBot](https://t.me/myidbot) to get the Chat ID (it usually starts with `-100`).
3.  **Configure Academy**:
    *   Go to **Admin Dashboard > Global Settings**.
    *   Enter your **Bot Token** and **Chat ID**.
    *   Toggle **"Enable Automated Signal Notifications"** to ON.
    *   Click **Save Settings**.

---

## ⚡ 3. Custom Webhooks (Developer API)
Integrate with third-party automation tools like Zapier, IFTTT, or your own custom servers.

### **Technical Specification:**
*   **Method**: `POST`
*   **Payload Type**: `JSON`
*   **Data Structure**:
    ```json
    {
      "id": 123,
      "pair": "EUR/USD",
      "type": "BUY",
      "entry_price": 1.0850,
      "stop_loss": 1.0800,
      "take_profit": 1.0950,
      "status": "Running",
      "created_at": "2024-03-23 14:30:00"
    }
    ```

### **How to setup:**
1.  Generate a Webhook URL from your target tool (e.g., a Zapier "Catch Webhook" trigger).
2.  Paste the URL into **Admin Dashboard > Global Settings > Custom Webhook URL**.
3.  New signals will now be sent to that URL as soon as they are posted.

---

## 🛠️ Troubleshooting
*   **No notifications?**: Ensure "Enable Automated Signal Notifications" is toggled **ON** in Admin Settings.
*   **MT4/5 not receiving?**: Check the "Journal" tab in MetaTrader for WebRequest errors. Ensure your domain is added to the "Allow WebRequest" list.
*   **Localhost issues?**: Webhooks and Telegram notifications may fail on local XAMPP environments if your server doesn't have an active internet connection or valid SSL. They work best on your live Vercel/Production server.

---
*© 2024 Pips and Profit Academy - World Standard Trading Education*
