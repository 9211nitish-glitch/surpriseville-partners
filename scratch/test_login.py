import mysql.connector
import hashlib

# Connection configuration matches db.php & db_main.php
db_config = {
    'host': 'swift.herosite.pro',
    'user': 'surpriseville_partners',
    'password': 'Sv@123@4567',
    'database': 'surpriseville_partners'
}

vendor_email = 'nitish@gmail.com'
vendor_pass = '123456789'

print("=== VENDOR CHECK ===")
try:
    conn = mysql.connector.connect(**db_config)
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT id, name, email, password FROM vendors WHERE email = %s LIMIT 1", (vendor_email,))
    vendor = cursor.fetchone()
    if vendor:
        print(f"Found Vendor ID: {vendor['id']}, Name: {vendor['name']}")
        print(f"Password in DB: {vendor['password']}")
        md5_hash = hashlib.md5(vendor_pass.encode()).hexdigest()
        if vendor['password'] == md5_hash or vendor['password'] == vendor_pass:
            print("Password MATCH success!")
        else:
            print("Password MISMATCH!")
    else:
        print(f"Vendor not found by email: {vendor_email}")
        
    print("\n=== ADMIN CHECK ===")
    cursor.execute("SELECT id, username, password FROM admin WHERE username = 'admin' LIMIT 1")
    admin = cursor.fetchone()
    if admin:
        print(f"Found Admin ID: {admin['id']}, Username: {admin['username']}")
        print(f"Password in DB: {admin['password']}")
        md5_hash = hashlib.md5(b"123456789").hexdigest()
        if admin['password'] == md5_hash or admin['password'] == '123456789':
            print("Password MATCH success!")
        else:
            print("Password MISMATCH!")
    else:
        print("Admin not found")
        
    cursor.close()
    conn.close()
except Exception as e:
    print(f"Error: {e}")
