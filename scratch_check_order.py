import mysql.connector

def check_task_13_all_txs():
    try:
        partners_conn = mysql.connector.connect(
            host="swift.herosite.pro",
            user="surpriseville_partners",
            password="Sv@123@4567",
            database="surpriseville_partners"
        )
        cursor = partners_conn.cursor(dictionary=True)
        
        print("--- ALL TRANSACTIONS FOR VENDOR #7 ---")
        cursor.execute("SELECT * FROM wallet_transactions WHERE vendor_id = 7 ORDER BY id DESC")
        txs = cursor.fetchall()
        for tx in txs:
            print(f"ID: {tx['id']}, Order ID: {tx['order_id']}, Type: {tx['type']}, Amount: {tx['amount']}, Desc: {tx['description']}, Created: {tx['created_at']}")
            
        cursor.close()
        partners_conn.close()
    except Exception as e:
        print("Error:", e)

if __name__ == "__main__":
    check_task_13_all_txs()
