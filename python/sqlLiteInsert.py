import sqlite3
import csv
from collections import defaultdict

DB_PATH = "marco_workout.db"
CSV_PATH = "log_marco.title.csv"

# Connessione DB
conn = sqlite3.connect(DB_PATH)
cursor = conn.cursor()

# (Opzionale) crea tabella se non esiste
cursor.execute("""
CREATE TABLE IF NOT EXISTS workout_log (
    id TEXT PRIMARY KEY,
    current_date TEXT NOT NULL,
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
# Legge CSV
# -----------------------------

rows = []

with open(CSV_PATH, newline='', encoding="utf-8") as csvfile:
    reader = csv.DictReader(csvfile, delimiter=';')
    for row in reader:
        rows.append(row)
print(reader.fieldnames)

# -----------------------------
# Calcolo activity_order
# -----------------------------

date_counter = defaultdict(int)

for row in rows:
    date = row["current_date"]
    date_counter[date] += 1
    row["activity_order"] = date_counter[date]


# -----------------------------
# Inserimento DB
# -----------------------------

insert_sql = """
INSERT OR REPLACE INTO workout_log
(id, current_date, origin_date, title, activity, pairs, prev_pairs, activity_order)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
"""

for r in rows:
    cursor.execute(insert_sql, (
        r["id"],
        r["current_date"],
        r["origin_date"],
        r["title"],
        r["activity"],
        r["pairs"],
        r.get("prev_pairs"),  # evita crash se campo vuoto
        r["activity_order"]
    ))

conn.commit()
conn.close()

print("Import completato con successo.")
