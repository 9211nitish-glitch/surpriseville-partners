import mysql.connector

def check_db():
    print("Connecting to partners database...")
    try:
        conn = mysql.connector.connect(
            host="swift.herosite.pro",
            user="surpriseville_partners",
            password="Sv@123@4567",
            database="surpriseville_partners"
        )
        cursor = conn.cursor(dictionary=True)
        
        print("\n--- TABLES IN partners DB ---")
        cursor.execute("SHOW TABLES")
        tables = [list(row.values())[0] for row in cursor.fetchall()]
        print(", ".join(tables))
        
        for table in ['decorator_videos', 'decorator_video_portfolio', 'manual_tasks']:
            if table in tables:
                print(f"\n--- DESCRIBE {table} ---")
                cursor.execute(f"DESCRIBE {table}")
                cols = cursor.fetchall()
                for col in cols:
                    print(f"  {col['Field']}: {col['Type']} | Null: {col['Null']} | Key: {col['Key']} | Default: {col['Default']}")
            else:
                print(f"\nTable {table} not found!")
                
        cursor.close()
        conn.close()
    except Exception as e:
        print(f"Error checking partners DB: {e}")

    print("\nConnecting to main database...")
    try:
        conn_main = mysql.connector.connect(
            host="swift.herosite.pro",
            user="surpriseville_emp",
            password="Sv@123@4567",
            database="surpriseville_emp"
        )
        cursor_main = conn_main.cursor(dictionary=True)
        
        print("\n--- TABLES IN main DB ---")
        cursor_main.execute("SHOW TABLES")
        main_tables = [list(row.values())[0] for row in cursor_main.fetchall()]
        print(", ".join(main_tables))
        
        for table in ['orders', 'order_vendor_assignments']:
            if table in main_tables:
                print(f"\n--- DESCRIBE {table} ---")
                cursor_main.execute(f"DESCRIBE {table}")
                cols = cursor_main.fetchall()
                for col in cols:
                    print(f"  {col['Field']}: {col['Type']} | Null: {col['Null']} | Key: {col['Key']} | Default: {col['Default']}")
            else:
                print(f"\nTable {table} not found in main DB!")
                
        cursor_main.close()
        conn_main.close()
    except Exception as e:
        print(f"Error checking main DB: {e}")

if __name__ == "__main__":
    check_db()
