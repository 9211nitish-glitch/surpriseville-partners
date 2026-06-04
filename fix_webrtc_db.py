import sys
import json
import mysql.connector
from mysql.connector import Error

# Fix Windows terminal encoding
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

MAIN_DB = dict(
    host="swift.herosite.pro",
    user="surpriseville_emp",
    password="Sv@123@4567",
    database="surpriseville_emp"
)

PARTNERS_DB = dict(
    host="swift.herosite.pro",
    user="surpriseville_partners",
    password="Sv@123@4567",
    database="surpriseville_partners"
)

def run():
    print("=" * 55)
    print("  WebRTC DB Fix Script")
    print("=" * 55)

    try:
        main_conn = mysql.connector.connect(**MAIN_DB)
        main_cur  = main_conn.cursor(dictionary=True)
        print("\n[OK] Connected to surpriseville_emp")
    except Error as e:
        print("[FAIL] Cannot connect to main DB:", e)
        return

    try:
        p_conn = mysql.connector.connect(**PARTNERS_DB)
        p_cur  = p_conn.cursor(dictionary=True)
        print("[OK] Connected to surpriseville_partners")
    except Error as e:
        print("[WARN] Cannot connect to partners DB:", e)
        p_conn = None
        p_cur  = None

    # Step 1: Current columns
    print("\n--- Current call_sessions columns ---")
    main_cur.execute("SHOW COLUMNS FROM call_sessions")
    existing = {}
    for row in main_cur.fetchall():
        existing[row['Field']] = row
        print("  " + row['Field'] + " (" + row['Type'] + ")")

    # Step 2: Add missing columns
    print("\n--- Adding missing columns ---")
    to_add = [
        ("callee_type",     "ALTER TABLE call_sessions ADD COLUMN callee_type ENUM('admin','vendor','user') NOT NULL DEFAULT 'admin' AFTER caller_id"),
        ("callee_id",       "ALTER TABLE call_sessions ADD COLUMN callee_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER callee_type"),
        ("sdp_offer",       "ALTER TABLE call_sessions ADD COLUMN sdp_offer MEDIUMTEXT NULL AFTER status"),
        ("sdp_answer",      "ALTER TABLE call_sessions ADD COLUMN sdp_answer MEDIUMTEXT NULL AFTER sdp_offer"),
        ("answered_at",     "ALTER TABLE call_sessions ADD COLUMN answered_at DATETIME NULL"),
        ("ended_at",        "ALTER TABLE call_sessions ADD COLUMN ended_at DATETIME NULL"),
        ("duration_seconds","ALTER TABLE call_sessions ADD COLUMN duration_seconds INT UNSIGNED NULL DEFAULT 0"),
    ]

    for col_name, sql in to_add:
        if col_name in existing:
            print("  SKIP  - " + col_name + " already exists")
        else:
            try:
                main_cur.execute(sql)
                main_conn.commit()
                print("  [ADDED] " + col_name)
            except Error as e:
                print("  [FAIL] " + col_name + ": " + str(e))

    # Step 3: Verify
    print("\n--- Schema after fix ---")
    main_cur.execute("SHOW COLUMNS FROM call_sessions")
    new_cols = [row['Field'] for row in main_cur.fetchall()]
    for c in new_cols:
        print("  [OK] " + c)

    required = ['callee_type', 'callee_id', 'sdp_offer', 'sdp_answer']
    missing = [c for c in required if c not in new_cols]
    if missing:
        print("\n[FAIL] Still missing: " + str(missing))
        return
    print("\n[OK] All required columns present!")

    # Step 4: Test INSERT
    print("\n--- Test INSERT (vendor->admin) ---")
    vendor_id = 1
    if p_cur:
        try:
            p_cur.execute("SELECT id FROM vendors LIMIT 1")
            vrow = p_cur.fetchone()
            if vrow:
                vendor_id = vrow['id']
        except:
            pass

    fake_sdp = json.dumps({"type": "offer", "sdp": "test"})
    try:
        main_cur.execute(
            "INSERT INTO call_sessions "
            "(order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at) "
            "VALUES (%s, 'vendor', %s, 'admin', 1, 'audio', 'ringing', %s, NOW())",
            (1, vendor_id, fake_sdp)
        )
        main_conn.commit()
        test_id = main_cur.lastrowid
        print("[OK] INSERT success - test call_session ID=" + str(test_id))
    except Error as e:
        print("[FAIL] INSERT failed: " + str(e))
        return

    # Step 5: Admin detection test
    print("\n--- Admin poll detection ---")
    main_cur.execute(
        "SELECT id, callee_type, status FROM call_sessions "
        "WHERE callee_type='admin' AND status='ringing' "
        "AND created_at >= NOW() - INTERVAL 5 MINUTE "
        "ORDER BY id DESC LIMIT 1"
    )
    found = main_cur.fetchone()
    if found:
        print("[OK] Admin CAN detect vendor calls! id=" + str(found['id']))
    else:
        print("[FAIL] Admin CANNOT detect calls")

    # Cleanup
    main_cur.execute("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id=" + str(test_id))
    main_conn.commit()
    print("[OK] Cleaned up test row")

    # Step 6: Recent sessions
    print("\n--- Recent call_sessions (last 10) ---")
    main_cur.execute(
        "SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, "
        "call_type, status, created_at FROM call_sessions ORDER BY id DESC LIMIT 10"
    )
    rows = main_cur.fetchall()
    if rows:
        for r in rows:
            print("  [" + r['status'].upper() + "] id=" + str(r['id']) +
                  " order=" + str(r['order_id']) +
                  " " + r['caller_type'] + "#" + str(r['caller_id']) +
                  " -> " + r['callee_type'] + "#" + str(r['callee_id']) +
                  " (" + r['call_type'] + ") @ " + str(r['created_at']))
    else:
        print("  No call sessions yet.")

    # Step 7: webrtc_signals check
    print("\n--- webrtc_signals table ---")
    try:
        main_cur.execute("SHOW COLUMNS FROM webrtc_signals")
        sc = [r['Field'] for r in main_cur.fetchall()]
        print("  Columns: " + ", ".join(sc))
        main_cur.execute("SELECT COUNT(*) as c FROM webrtc_signals")
        print("  Total signals: " + str(main_cur.fetchone()['c']))
    except Error:
        print("  webrtc_signals missing - creating...")
        try:
            main_cur.execute(
                "CREATE TABLE IF NOT EXISTS webrtc_signals ("
                "id INT UNSIGNED NOT NULL AUTO_INCREMENT, "
                "call_session_id INT UNSIGNED NOT NULL, "
                "signal_type ENUM('offer','answer','ice','decline','end','cmd') NOT NULL, "
                "payload MEDIUMTEXT NOT NULL, "
                "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, "
                "PRIMARY KEY (id), "
                "INDEX idx_session (call_session_id, id)"
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            )
            main_conn.commit()
            print("  [OK] webrtc_signals table created!")
        except Error as e2:
            print("  [FAIL] " + str(e2))

    print("\n" + "=" * 55)
    print("  FIX COMPLETE!")
    print("=" * 55)
    print("Next steps:")
    print("  1. Vendor login -> kisi order pe jaao -> Call karo")
    print("  2. Admin me 4 seconds mein ring aana chahiye")
    print("  3. Admin se bhi call karo (admin/tracking.php)")

    main_cur.close()
    main_conn.close()
    if p_cur:
        p_cur.close()
        p_conn.close()

if __name__ == "__main__":
    run()
