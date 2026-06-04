import mysql.connector
import re
import os

def test_query():
    # Read config from db.php (simulate)
    # We know credentials from setup_decorator_db.py: Host=swift.herosite.pro, DB=surpriseville_partners, User=surpriseville_partners
    
    # Just extract the password from setup_decorator_db.py or db.php
    with open('db.php', 'r') as f:
        content = f.read()
        pwd_match = re.search(r'\$password\s*=\s*[\'"](.*?)[\'"]', content)
        pwd = pwd_match.group(1) if pwd_match else ''
        
    try:
        conn = mysql.connector.connect(
            host="swift.herosite.pro",
            user="surpriseville_partners",
            password=pwd,
            database="surpriseville_partners"
        )
        
        cursor = conn.cursor()
        
        query = """
            SELECT dv.*, v.name, v.business_name
            FROM decorator_videos dv
            JOIN vendors v ON dv.vendor_id = v.id
            WHERE dv.video_status = 'pending'
            ORDER BY dv.created_at ASC
            LIMIT 50
        """
        
        cursor.execute(query)
        print("Success! Fetched rows:")
        for row in cursor.fetchall():
            print(row)
            
    except mysql.connector.Error as err:
        print(f"Error: {err}")

if __name__ == "__main__":
    test_query()
