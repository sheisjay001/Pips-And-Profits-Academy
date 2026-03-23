# 🚀 Automated Signal Integration Guide

Pips and Profit Academy now supports automated signal execution and notifications. This allows you to bridge signals from your academy dashboard directly to MetaTrader 4/5, Telegram, or custom external tools—completely for free.

---

## 🔗 1. Signal Feed (For MetaTrader 4/5)
The Signal Feed is a machine-readable JSON endpoint that allows Expert Advisors (EAs) to "read" your signals automatically.

### **How to use it:**
1.  **Get your Feed URL**: Go to your **Admin Dashboard > Global Settings**. Copy the **Signal Feed URL**.
    *   *Example: `https://your-academy.com/api/signals.php?action=feed`*
2.  **MetaTrader Setup**:
    *   Open MetaTrader 4/5.
    *   Go to **Tools > Options > Expert Advisors**.
    *   Check **"Allow WebRequest for listed URL"**.
    *   Add your Academy domain (e.g., `https://your-academy.com`) to the list.
3.  **Use a Signal Receiver EA**:
    *   Load any standard "JSON Signal Receiver" EA onto your chart.
    *   Paste your **Signal Feed URL** into the EA's settings.
    *   The EA will now automatically execute trades whenever you post a signal in the Academy.

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
