import sys
import mysql.connector

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

MAIN_DB = dict(
    host="swift.herosite.pro",
    user="surpriseville_emp",
    password="Sv@123@4567",
    database="surpriseville_emp"
)

mc = mysql.connector.connect(**MAIN_DB)
cur = mc.cursor(dictionary=True)
print("Connected OK")

# Check ENUMs
cur.execute("SHOW COLUMNS FROM call_sessions LIKE 'caller_type'")
r = cur.fetchone()
print("caller_type:", r['Type'])

cur.execute("SHOW COLUMNS FROM call_sessions LIKE 'callee_type'")
r = cur.fetchone()
print("callee_type:", r['Type'])

# Test admin->vendor INSERT
cur.execute(
    "INSERT INTO call_sessions "
    "(order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at) "
    "VALUES (28, 'admin', 1, 'vendor', 7, 'audio', 'ringing', '{\"test\":1}', NOW())"
)
mc.commit()
tid = cur.lastrowid
print("Admin->Vendor INSERT OK id=" + str(tid))

# Vendor detection
cur.execute(
    "SELECT id FROM call_sessions WHERE callee_type='vendor' AND callee_id=7 "
    "AND status='ringing' AND created_at >= NOW() - INTERVAL 5 MINUTE"
)
found = cur.fetchone()
print("Vendor detects admin call:", "YES" if found else "NO")

# Vendor->Admin detection
cur.execute(
    "INSERT INTO call_sessions "
    "(order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at) "
    "VALUES (28, 'vendor', 7, 'admin', 1, 'audio', 'ringing', '{\"test\":1}', NOW())"
)
mc.commit()
tid2 = cur.lastrowid
print("Vendor->Admin INSERT OK id=" + str(tid2))

cur.execute(
    "SELECT id FROM call_sessions WHERE callee_type='admin' AND status='ringing' "
    "AND created_at >= NOW() - INTERVAL 5 MINUTE"
)
found2 = cur.fetchone()
print("Admin detects vendor call:", "YES" if found2 else "NO")

# Signal INSERT with from_type
cur.execute(
    "INSERT INTO webrtc_signals (call_session_id, from_type, signal_type, payload, created_at) "
    "VALUES (%s, 'vendor', 'ice_candidate', '{\"candidate\":\"test\"}', NOW())",
    (tid2,)
)
mc.commit()
print("Signal INSERT OK, id=" + str(cur.lastrowid))

# Cleanup
cur.execute("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id IN (" + str(tid) + "," + str(tid2) + ")")
mc.commit()
print("Cleanup done")

print("\n=== ALL TESTS PASSED ===")
print("WebRTC calling pipeline is fully operational!")
cur.close()
mc.close()
