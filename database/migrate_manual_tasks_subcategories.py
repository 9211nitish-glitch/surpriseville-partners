#!/usr/bin/env python3
import sys

try:
    import mysql.connector
    from mysql.connector import Error
except ImportError:
    print("ERROR: mysql-connector-python is not installed")
    print("Please install it: pip install mysql-connector-python")
    sys.exit(1)

def main():
    db_config = {
        'host': 'swift.herosite.pro',
        'user': 'surpriseville_partners',
        'password': 'Sv@123@4567',
        'database': 'surpriseville_partners',
        'port': 3306
    }
    
    connection = None
    cursor = None
    
    try:
        print(f"Connecting to database '{db_config['database']}' on host '{db_config['host']}'...")
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()
        print("Successfully connected to the database.")
        
        # Describe manual_tasks to see existing columns
        print("Checking existing columns in table 'manual_tasks'...")
        cursor.execute("DESCRIBE manual_tasks")
        columns = [row[0] for row in cursor.fetchall()]
        
        column_name = "subcategory_id"
        if column_name in columns:
            print(f"Column '{column_name}' already exists in table 'manual_tasks'. No migration needed.")
        else:
            print(f"Column '{column_name}' does not exist. Adding column...")
            alter_query = "ALTER TABLE manual_tasks ADD COLUMN subcategory_id INT DEFAULT NULL AFTER category_id;"
            cursor.execute(alter_query)
            print(f"Successfully added column '{column_name}'.")
            
        # Verify the schema after modifications
        print("\nVerifying 'manual_tasks' table structure:")
        cursor.execute("DESCRIBE manual_tasks")
        updated_columns = cursor.fetchall()
        for col in updated_columns:
            print(f"  Field: {col[0]:<20} | Type: {col[1]:<15} | Null: {col[2]:<5} | Key: {col[3]:<5} | Default: {str(col[4]):<10} | Extra: {col[5]}")
            
        # Check if subcategory_id is in updated_columns
        verified = any(col[0] == column_name for col in updated_columns)
        if verified:
            print(f"\nSUCCESS: Column '{column_name}' verified in 'manual_tasks' schema.")
        else:
            print(f"\nFAILURE: Column '{column_name}' was not found in 'manual_tasks' schema after alter.")
            sys.exit(1)
            
    except Error as e:
        print(f"\nDatabase Error: {e}")
        sys.exit(1)
    except Exception as e:
        print(f"\nError: {e}")
        sys.exit(1)
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()
        print("Database connection closed.")

if __name__ == '__main__':
    main()
