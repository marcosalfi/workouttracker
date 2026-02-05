import sqlite3
import csv
from collections import defaultdict

DB_PATH = r"D:\sorgentiMs\workouttrackersqllite\backup\marco_workout.db"
CSV_PATH = r"D:\sorgentiMs\workouttrackersqllite\backup\log_marco.last.csv"   # <-- il tuo nuovo file

# -----------------------------
# Connessione DB
# -----------------------------
conn = sqlite3.connect(DB_PATH)
cursor = conn.cursor()

# Tabella allineata allo schema reale
cursor.execute("""
CREATE TABLE IF NOT EXISTS workout_log (
    id TEXT PRIMARY KEY,
    wo_date TEXT NOT NULL,
    origin_date TEXT NOT NULL,
    title TEXT NOT NULL,
    activity TEXT NOT NULL,
    pairs TEXT NOT NULL,
    prev_pairs TEXT,
    activity_order INTEGER
);
""")
conn.commit()

# -----------------------------
# Lettura CSV
# -----------------------------
rows = []

with open(CSV_PATH, newline='', encoding="utf-8") as csvfile:
    reader = csv.DictReader(csvfile, delimiter=';')
    for row in reader:
        rows.append(row)

print("Campi CSV:", reader.fieldnames)

# -----------------------------
# Calcolo activity_order per wo_date
# -----------------------------
date_counter = defaultdict(int)

for row in rows:
    d = row["wo_date"]
    date_counter[d] += 1
    row["activity_order"] = date_counter[d]

# -----------------------------
# Inserimento DB
# -----------------------------
insert_sql = """
INSERT OR REPLACE INTO workout_log
(id, wo_date, origin_date, title, activity, pairs, prev_pairs, activity_order)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
"""

for r in rows:
    cursor.execute(insert_sql, (
        r["id"],
        r["wo_date"],
        r["origin_date"],
        "",                         # <-- title VOLUTAMENTE VUOTO
        r["activity"],
        r.get("pairs") or "",       # evita NULL
        r.get("prev_pairs") or None,
        r["activity_order"]
    ))

conn.commit()
conn.close()

print("Import wo_log completato con successo.")
