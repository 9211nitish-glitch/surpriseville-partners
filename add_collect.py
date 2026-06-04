import mysql.connector

try:
    conn = mysql.connector.connect(
        host='swift.herosite.pro',
        user='surpriseville_partners',
        password='Sv@123@4567',
        database='surpriseville_partners'
    )
    cursor = conn.cursor()
    
    # 1. Add amount_to_collect column to crm_bookings
    try:
        cursor.execute("ALTER TABLE crm_bookings ADD COLUMN amount_to_collect DECIMAL(10,2) DEFAULT 0.00")
        print("amount_to_collect column added to crm_bookings successfully!")
    except mysql.connector.Error as err:
        if err.errno == 1060: # Column already exists
            print("amount_to_collect column already exists.")
        else:
            print(f"Error: {err}")
            
    conn.commit()
    cursor.close()
    conn.close()
except Exception as e:
    print(f"Connection error: {e}")
