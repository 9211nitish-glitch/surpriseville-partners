import mysql.connector
import requests
import sys

# DB config from config.php
DB_CONFIG = {
    'host': 'swift.herosite.pro',
    'user': 'surpriseville_emp',
    'password': 'Sv@123@4567',
    'database': 'surpriseville_emp'
}

def verify_otp_flow():
    print("--- TESTING OTP FLOW ---")
    test_phone = "9876543210"
    
    # 1. Trigger request_otp API
    url = "https://surpriseville.co.in/user-auth.php?action=request_otp"
    payload = {'phone': test_phone}
    print(f"Requesting OTP for phone: {test_phone} at {url}...")
    
    try:
        response = requests.post(url, data=payload, verify=False, timeout=10)
        print(f"HTTP Status Code: {response.status_code}")
        print(f"API Response: {response.text}")
        res_data = response.json()
    except Exception as e:
        print(f"Error calling OTP API: {e}")
        return False

    # 2. Check in database for the generated OTP
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # Look for the last generated OTP for our test phone
        query = "SELECT * FROM user_otps WHERE phone = %s ORDER BY id DESC LIMIT 1"
        cursor.execute(query, (test_phone,))
        db_otp = cursor.fetchone()
        
        if db_otp:
            print("\nDatabase Record Found:")
            print(f"  ID: {db_otp['id']}")
            print(f"  Phone: {db_otp['phone']}")
            print(f"  OTP: {db_otp['otp']}")
            print(f"  Expiry: {db_otp['expiry']}")
            print("OTP database insertion is working properly!")
            
            # Clean up the test OTP
            del_query = "DELETE FROM user_otps WHERE phone = %s"
            cursor.execute(del_query, (test_phone,))
            conn.commit()
            print("Cleaned up test OTP from database.")
        else:
            print("\nWarning: No OTP record found in user_otps database for this phone number.")
            
        cursor.close()
        conn.close()
    except Exception as e:
        print(f"Database error checking OTP: {e}")

def verify_chat_flow():
    print("\n--- TESTING CHAT FLOW ---")
    # 1. Fetch an active order to test
    order_id = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT id, status, name, phone FROM orders ORDER BY id DESC LIMIT 1")
        order = cursor.fetchone()
        if order:
            print(f"Using Order ID: {order['id']} (Status: {order['status']}, Name: {order['name']}) for testing.")
            order_id = order['id']
        else:
            print("No orders found in database to test.")
        cursor.close()
        conn.close()
    except Exception as e:
        print(f"Database error fetching order: {e}")
        return

    if not order_id:
        return

    # 2. Test sending a message via HTTP
    # We will simulate a cross-domain request from vendor origin
    chat_url = "https://surpriseville.co.in/ajax/chat_api.php"
    headers = {
        'Origin': 'https://partners.surpriseville.co.in'
    }
    chat_payload = {
        'action': 'send_message',
        'order_id': str(order_id),
        'message': 'Automated Test Message from Python Script',
        'vendor_id': '99999' # Simulated vendor_id (cross-domain bypass)
    }
    
    print(f"Sending test chat message to {chat_url}...")
    try:
        res = requests.post(chat_url, data=chat_payload, headers=headers, verify=False, timeout=10)
        print(f"HTTP Status Code: {res.status_code}")
        print(f"API Response: {res.text}")
        res_json = res.json()
    except Exception as e:
        print(f"Error calling Chat API: {e}")
        return

    # 3. Verify in database
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM chat_messages WHERE order_id = %s ORDER BY id DESC LIMIT 1", (order_id,))
        msg = cursor.fetchone()
        
        if msg:
            print("\nDatabase Chat Message Record Found:")
            print(f"  ID: {msg['id']}")
            print(f"  Order ID: {msg['order_id']}")
            print(f"  Sender Type: {msg['sender_type']}")
            print(f"  Sender ID: {msg['sender_id']}")
            print(f"  Message: {msg['message']}")
            print(f"  Created At: {msg['created_at']}")
            
            # Clean up the test message
            cursor.execute("DELETE FROM chat_messages WHERE id = %s", (msg['id'],))
            conn.commit()
            print("Cleaned up test chat message from database.")
        else:
            print("\nError: Test message was not found in the database!")
            
        cursor.close()
        conn.close()
    except Exception as e:
        print(f"Database error checking chat: {e}")

if __name__ == "__main__":
    verify_otp_flow()
    verify_chat_flow()
