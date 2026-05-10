from flask import Flask, request, jsonify
from flask_cors import CORS
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.tree import DecisionTreeClassifier
import os
import logging

app = Flask(__name__)
CORS(app)

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

base_dir = os.path.dirname(os.path.abspath(__file__))
dataset_path = os.path.join(base_dir, "disease_data.csv")

if not os.path.exists(dataset_path):
    raise FileNotFoundError(f"Dataset not found: {dataset_path}")

logger.info(f"Loading dataset from {dataset_path}")

df = pd.read_csv(dataset_path)

SYMPTOMS = ['fever', 'cough', 'headache', 'fatigue',
            'body_pain', 'sore_throat', 'vomiting', 'diarrhea']

X = df[SYMPTOMS]
y = df['disease']

model = RandomForestClassifier(n_estimators=100, random_state=42)
model.fit(X, y)

# ===== HOME ROUTE =====
@app.route("/")
def home():
    return "Obeso Clinic AI API - Running"

# ===== PREDICT ROUTE =====
@app.route("/predict", methods=["POST"])
def predict():
    try:
        data = request.get_json()

        if not data:
            return jsonify({"error": "No data received"}), 400

        # Build input — default 0 if symptom not sent
        input_data = [[int(data.get(s, 0)) for s in SYMPTOMS]]

        # Predict
        prediction    = model.predict(input_data)[0]
        probabilities = model.predict_proba(input_data)[0]
        classes       = model.classes_

        # Confidence scores
        scores = {
            disease: round(float(prob) * 100, 1)
            for disease, prob in zip(classes, probabilities)
        }

        # Top 3
        top3 = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:3]

        return jsonify({
            "disease":    prediction,
            "confidence": scores[prediction],
            "top3": [
                {"disease": d, "confidence": c}
                for d, c in top3
            ]
        })

    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8000, debug=True)