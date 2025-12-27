# Node.js Backend for Pips and Profits Academy

This backend handles secure payment verification for Paystack.

## Setup

1.  **Install Node.js**: Ensure Node.js is installed on your computer.
2.  **Install Dependencies**:
    Open a terminal in this folder (`backend`) and run:
    ```bash
    npm install
    ```
    (This has already been done for you).

3.  **Configure Keys**:
    Open the `.env` file in this folder and replace `sk_test_YOUR_SECRET_KEY_HERE` with your actual **Paystack Live Secret Key**.

    ```env
    PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxxxxxxx
    PORT=3000
    ```

## Running the Server

To start the backend server, run:

```bash
npm start
```

The server will run on `http://localhost:3000`.

## Frontend Integration

The frontend (`js/app.js`) is already configured to talk to this server.
Make sure to update `js/app.js` with your **Paystack Live Public Key** (`pk_live_...`).
