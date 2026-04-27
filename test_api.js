
const axios = require('axios');

async function testCreateUser() {
    try {
        const response = await axios.post('http://127.0.0.1/hotel-backend/public/api/users', {
            name: 'Test User',
            username: 'testuser' + Math.floor(Math.random() * 1000),
            password: 'password123',
            password_confirmation: 'password123',
            is_admin: false,
            permissions: []
        }, {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                // We might need a token if it's protected
            }
        });
        console.log('Success:', response.status, response.data);
    } catch (error) {
        console.error('Error:', error.response ? error.response.status : error.message);
        if (error.response && error.response.data) {
            console.error('Data:', JSON.stringify(error.response.data, null, 2));
        }
    }
}

testCreateUser();
