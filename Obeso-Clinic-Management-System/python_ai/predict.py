import mysql.connector
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
import sys
import json

# ================= DATABASE CONNECTION =================

conn = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="obeso_clinic_database"
)

# ================= LOAD CHECKUP RECORDS =================

query = """
SELECT
    diagnosis,
    blood_pressure,
    heart_rate,
    temperature,
    respiratory_rate,
    chief_complaint
FROM checkups
WHERE diagnosis IS NOT NULL
"""

df = pd.read_sql(query, conn)

# ================= CLEAN DATA =================

df = df.dropna()

# Convert BP to systolic number

df['blood_pressure'] = df['blood_pressure'].apply(
    lambda x: int(str(x).split('/')[0])
)

# ================= FEATURE ENGINEERING =================

df['fever'] = df['temperature'].apply(
    lambda x: 1 if float(x) >= 38 else 0
)

df['high_bp'] = df['blood_pressure'].apply(
    lambda x: 1 if x >= 140 else 0
)

df['cough'] = df['chief_complaint'].apply(
    lambda x: 1 if 'cough' in str(x).lower() else 0
)

# ================= FEATURES =================

X = df[
    [
        'fever',
        'high_bp',
        'cough',
        'blood_pressure',
        'heart_rate',
        'temperature',
        'respiratory_rate'
    ]
]

# ================= TARGET =================

y = df['diagnosis']

# ================= TRAIN MODEL =================

model = RandomForestClassifier(
    n_estimators=100,
    random_state=42
)

model.fit(X, y)

# ================= RECEIVE DATA FROM PHP =================

fever = int(sys.argv[1])
high_bp = int(sys.argv[2])
cough = int(sys.argv[3])
bp = int(sys.argv[4])
hr = int(sys.argv[5])
temp = float(sys.argv[6])
rr = int(sys.argv[7])

# ================= PREDICT =================

prediction = model.predict([
    [
        fever,
        high_bp,
        cough,
        bp,
        hr,
        temp,
        rr
    ]
])

# ================= CONFIDENCE =================

probabilities = model.predict_proba([
    [
        fever,
        high_bp,
        cough,
        bp,
        hr,
        temp,
        rr
    ]
])

confidence = round(max(probabilities[0]) * 100, 2)

# ================= RISK LEVEL =================

risk = "Low"

if confidence >= 80:
    risk = "High"
elif confidence >= 50:
    risk = "Moderate"

# ================= RECOMMENDATION =================

recommendation = "Maintain healthy lifestyle."

if prediction[0] == "Hypertension":
    recommendation = "Monitor blood pressure regularly."

elif prediction[0] == "Fever":
    recommendation = "Increase fluids and monitor temperature."

elif prediction[0] == "Cough":
    recommendation = "Observe respiratory condition."

# ================= OUTPUT =================

print(json.dumps({
    "prediction": prediction[0],
    "confidence": str(confidence) + "%",
    "risk": risk,
    "recommendation": recommendation
}))