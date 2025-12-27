const express = require('express');
const cors = require('cors');
const axios = require('axios');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());

// Paystack Secret Key from Environment Variables
const PAYSTACK_SECRET_KEY = process.env.PAYSTACK_SECRET_KEY;

// Verify Payment Endpoint
app.post('/api/verify-payment', async (req, res) => {
    try {
        const { reference } = req.body;

        if (!reference) {
            return res.status(400).json({ status: false, message: 'No reference provided' });
        }

        // Verify with Paystack
        const response = await axios.get(`https://api.paystack.co/transaction/verify/${reference}`, {
            headers: {
                Authorization: `Bearer ${PAYSTACK_SECRET_KEY}`
            }
        });

        const data = response.data;

        if (data.status && data.data.status === 'success') {
            // Payment verified successfully
            // In a real app, you would update the user's status in your database here
            
            return res.json({
                status: true,
                message: 'Payment verified successfully',
                data: data.data
            });
        } else {
            return res.status(400).json({
                status: false,
                message: 'Payment verification failed',
                data: data.data
            });
        }

    } catch (error) {
        console.error('Payment Verification Error:', error.response ? error.response.data : error.message);
        res.status(500).json({
            status: false,
            message: 'Internal Server Error verifying payment'
        });
    }
});

app.get('/', (req, res) => {
    res.send('Pips and Profits Academy Backend is Running');
});

app.listen(PORT, () => {
    console.log(`Server running on http://localhost:${PORT}`);
});
