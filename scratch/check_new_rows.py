import mysql.connector

def check_new():
    try:
        conn = mysql.connector.connect(
            host="swift.herosite.pro",
            user="surpriseville_emp",
            password="Sv@123@4567",
            database="surpriseville_emp"
        )
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM chat_messages ORDER BY id DESC LIMIT 10")
        rows = cursor.fetchall()
        print("Last 10 messages:")
        for r in rows:
            print(f"ID: {r['id']}, TaskID: {r.get('task_id')}, OrderID: {r.get('order_id')}, SenderID: {r.get('sender_id')}, SenderType: {r.get('sender_type')}, Message: {r.get('message')}, IsRead: {r.get('is_read')}, CreatedAt: {r.get('created_at')}")
        conn.close()
    except Exception as e:
        print("Error:", e)

if __name__ == "__main__":
    check_new()
