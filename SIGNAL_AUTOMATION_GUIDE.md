# 🚀 Automated Signal Integration Guide (Mobile & Cloud)

Pips and Profit Academy now supports automated signal execution. This allows you to bridge signals from your academy dashboard directly to your trading account using your **mobile phone**—no 24/7 PC required.

---

## � 1. Mobile Setup (No PC Required)
To receive and execute signals directly on your phone, we use **Cloud Trade Copiers**. These services act as a bridge between the Academy and your Broker account.

### **Recommended Services:**
*   **SignalStart**
*   **SocialTraderTools**
*   **DupliTrade**

### **How to setup on Mobile:**
1.  **Create a Cloud Copier Account**: Sign up for one of the services above (most offer a free trial).
2.  **Connect your Broker**: Link your MetaTrader 4 or 5 account to the Cloud service using your login credentials.
3.  **Get your Webhook URL**: In your Cloud Copier dashboard, locate the **"Webhook Receiver"** or **"Signal API"** section. Copy the unique Webhook URL provided.
4.  **Connect the Academy**:
    *   **Students**: Provide your Webhook URL to the Academy Admin or paste it into your profile settings (if enabled).
    *   **Admins**: Go to **Global Settings** and paste the **Custom Webhook URL**.
5.  **Done!**: When a signal is posted, it is sent to the Cloud service, which instantly places the trade on your account. You can monitor everything from your mobile MT4/MT5 app.

---

## 🔗 2. Desktop Setup (MetaTrader 4/5 Integration)
If you prefer using a desktop platform, follow these steps to bridge signals directly.

### **Step-by-Step MetaTrader Setup:**

#### **A. Authorize the Academy Domain**
1.  Open **MetaTrader 4** or **MetaTrader 5**.
2.  Go to **Tools > Options** (or press `Ctrl+O`).
3.  Click the **Expert Advisors** tab.
4.  Check: **"Allow WebRequest for listed URL"**.
5.  Add: `https://pips-and-profits-academy.vercel.app`
6.  Click **OK**.

#### **B. Install a Signal Receiver EA**
1.  **Download**: Use any free "JSON Signal Receiver" EA from the MQL5 Market.
2.  **Install**: Paste the EA into your `MQL4/Experts` folder.
3.  **Link your Feed**:
    *   **Students**: Click **"Connect Account"** on your Signals page to get your Feed URL.
    *   **MetaTrader**: Drag the EA onto a chart and paste your **Signal Feed URL** into the settings.

---

## ⚡ 3. Custom Webhooks (Developer API)
Integrate with third-party automation tools like Zapier or your own custom servers.

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

---

## 🛠️ Troubleshooting
*   **Localhost issues?**: Webhooks work best on your live Vercel/Production server.
*   **Mobile Tracking**: Use the official MT4/MT5 mobile app to monitor trades placed by the cloud bridge.

---
*© 2024 Pips and Profit Academy - World Standard Trading Education*
