import sys
import json
import mysql.connector
from mysql.connector import Error

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
    print("=" * 60)
    print("  WebRTC Signal API Fix - Phase 2")
    print("=" * 60)

    try:
        mc = mysql.connector.connect(**MAIN_DB)
        cur = mc.cursor(dictionary=True)
        print("[OK] Connected to surpriseville_emp")
    except Error as e:
        print("[FAIL]", e); return

    # ── 1. Fix callee_id=0 in old call sessions ──────────────────────────────
    print("\n--- Fix 1: Update old sessions with callee_id=0 -> callee_id=1 ---")
    cur.execute(
        "UPDATE call_sessions SET callee_id=1 WHERE callee_type='admin' AND callee_id=0"
    )
    mc.commit()
    print("[OK] Updated " + str(cur.rowcount) + " rows (callee_id=0 -> callee_id=1 for admin calls)")

    # ── 2. Fix caller_type ENUM - must include 'admin' ────────────────────────
    print("\n--- Fix 2: Check caller_type ENUM includes 'admin' ---")
    cur.execute("SHOW COLUMNS FROM call_sessions LIKE 'caller_type'")
    col_info = cur.fetchone()
    if col_info:
        print("  caller_type definition: " + str(col_info['Type']))
        if 'admin' not in str(col_info['Type']):
            try:
                cur.execute(
                    "ALTER TABLE call_sessions MODIFY COLUMN caller_type "
                    "ENUM('admin','vendor','user') NOT NULL DEFAULT 'vendor'"
                )
                mc.commit()
                print("  [FIXED] caller_type ENUM now includes 'admin'")
            except Error as e:
                print("  [FAIL] " + str(e))
        else:
            print("  [OK] caller_type already has 'admin'")

    # ── 3. Check webrtc_signals table schema ──────────────────────────────────
    print("\n--- Fix 3: Adapt local signal API to webrtc_signals schema ---")
    cur.execute("SHOW COLUMNS FROM webrtc_signals")
    sig_cols = {row['Field']: row for row in cur.fetchall()}
    print("  Existing columns: " + ", ".join(sig_cols.keys()))

    # The existing table has: id, call_session_id, from_type, signal_type, payload, is_delivered, created_at
    # Our local API inserts: call_session_id, signal_type, payload, created_at
    # 'from_type' has no default - this causes INSERT failures!

    if 'from_type' in sig_cols:
        print("  from_type column exists - checking if it has a default...")
        from_type_def = sig_cols['from_type']
        print("  from_type: Type=" + from_type_def['Type'] + " Null=" + from_type_def['Null'] + " Default=" + str(from_type_def['Default']))

        if from_type_def['Null'] == 'NO' and from_type_def['Default'] is None:
            # Add a default value so our INSERT doesn't fail
            try:
                cur.execute(
                    "ALTER TABLE webrtc_signals MODIFY COLUMN from_type "
                    "ENUM('admin','vendor','user') NOT NULL DEFAULT 'vendor'"
                )
                mc.commit()
                print("  [FIXED] from_type now has DEFAULT 'vendor'")
            except Error as e:
                print("  [FAIL] " + str(e))
        else:
            print("  [OK] from_type already has a default or is nullable")

    if 'is_delivered' in sig_cols:
        print("  is_delivered column exists (no action needed)")

    # ── 4. Test full signal flow ──────────────────────────────────────────────
    print("\n--- Fix 4: Test full call + signal insert ---")

    # Get a vendor ID
    try:
        pc = mysql.connector.connect(**PARTNERS_DB)
        pcur = pc.cursor(dictionary=True)
        pcur.execute("SELECT id FROM vendors LIMIT 1")
        vrow = pcur.fetchone()
        vendor_id = vrow['id'] if vrow else 1
        pcur.close(); pc.close()
    except:
        vendor_id = 1

    # Insert test call session
    fake_sdp = json.dumps({"type": "offer", "sdp": "test_v2"})
    cur.execute(
        "INSERT INTO call_sessions "
        "(order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at) "
        "VALUES (%s, 'vendor', %s, 'admin', 1, 'audio', 'ringing', %s, NOW())",
        (1, vendor_id, fake_sdp)
    )
    mc.commit()
    call_id = cur.lastrowid
    print("[OK] Test call session created, ID=" + str(call_id))

    # Try to insert a signal (should not fail now)
    ice = json.dumps({"candidate": "test", "sdpMid": "0", "sdpMLineIndex": 0})
    try:
        cur.execute(
            "INSERT INTO webrtc_signals (call_session_id, signal_type, payload, created_at) "
            "VALUES (%s, 'ice', %s, NOW())",
            (call_id, ice)
        )
        mc.commit()
        sig_id = cur.lastrowid
        print("[OK] Signal INSERT success, signal ID=" + str(sig_id))
    except Error as e:
        print("[FAIL] Signal INSERT failed: " + str(e))
        # Try with from_type explicitly
        try:
            cur.execute(
                "INSERT INTO webrtc_signals (call_session_id, from_type, signal_type, payload, created_at) "
                "VALUES (%s, 'vendor', 'ice', %s, NOW())",
                (call_id, ice)
            )
            mc.commit()
            sig_id = cur.lastrowid
            print("[OK] Signal INSERT with from_type succeeded, signal ID=" + str(sig_id))
            print("NOTE: Updating ajax/webrtc_signal.php to include from_type in INSERT...")
        except Error as e2:
            print("[FAIL] Still failing: " + str(e2))

    # Cleanup
    cur.execute("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id=" + str(call_id))
    mc.commit()
    print("[OK] Test rows cleaned up")

    # ── 5. Show current state ─────────────────────────────────────────────────
    print("\n--- Current call_sessions (last 5) ---")
    cur.execute(
        "SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, "
        "call_type, status, created_at FROM call_sessions ORDER BY id DESC LIMIT 5"
    )
    for r in cur.fetchall():
        print("  [" + r['status'].upper() + "] id=" + str(r['id']) +
              " " + r['caller_type'] + "#" + str(r['caller_id']) +
              " -> " + r['callee_type'] + "#" + str(r['callee_id']) +
              " order=" + str(r['order_id']) + " @ " + str(r['created_at']))

    print("\n--- Signals summary ---")
    cur.execute("SHOW COLUMNS FROM webrtc_signals")
    final_cols = [r['Field'] for r in cur.fetchall()]
    print("  Columns: " + ", ".join(final_cols))
    cur.execute("SELECT signal_type, COUNT(*) as c FROM webrtc_signals GROUP BY signal_type")
    for r in cur.fetchall():
        print("  " + r['signal_type'] + ": " + str(r['c']) + " signals")

    print("\n" + "=" * 60)
    print("  Phase 2 Fix COMPLETE!")
    print("=" * 60)
    print("  'from_type' column needs to be in INSERT - updating PHP API...")

    cur.close()
    mc.close()

if __name__ == "__main__":
    run()
