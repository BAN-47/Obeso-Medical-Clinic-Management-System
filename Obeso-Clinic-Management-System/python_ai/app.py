from flask import Flask, request, jsonify
from flask_cors import CORS
import pandas as pd
from sklearn.tree import DecisionTreeClassifier
import mysql.connector
import logging

app = Flask(__name__)
CORS(app)

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'obeso_clinic_database'
}

SYMPTOMS = ['cough', 'headache', 'fatigue', 'body_pain',
            'sore_throat', 'vomiting', 'diarrhea']
FEATURE_COLUMNS = [
    'fever',
    'high_bp',
    'cough',
    'headache',
    'fatigue',
    'body_pain',
    'sore_throat',
    'vomiting',
    'diarrhea',
    'blood_pressure',
    'heart_rate',
    'temperature',
    'respiratory_rate'
]

KEYWORDS = {
    'cough': ['cough', 'dry cough', 'productive cough'],
    'headache': ['headache', 'migraine', 'head pain'],
    'fatigue': ['fatigue', 'tired', 'weakness'],
    'body_pain': ['body pain', 'muscle pain', 'aches'],
    'sore_throat': ['sore throat', 'throat pain', 'pharyngitis'],
    'vomiting': ['vomit', 'vomiting', 'nausea'],
    'diarrhea': ['diarrhea', 'diarrhoea', 'loose stool']
}


def connect_db():
    return mysql.connector.connect(**DB_CONFIG)


def text_contains_keywords(text, keywords):
    text = str(text).lower()
    return any(keyword in text for keyword in keywords)


def load_training_data():
    conn = connect_db()
    query = """
    SELECT
        diagnosis,
        blood_pressure,
        heart_rate,
        temperature,
        respiratory_rate,
        chief_complaint,
        history_present_illness
    FROM checkups
    WHERE diagnosis IS NOT NULL
    """

    df = pd.read_sql(query, conn)
    conn.close()

    if df.empty:
        raise ValueError("No labeled checkup records available for training.")

    df = df.dropna(subset=['diagnosis', 'blood_pressure', 'temperature', 'heart_rate', 'respiratory_rate'])
    df['blood_pressure'] = df['blood_pressure'].astype(str).apply(
        lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
    )
    df['temperature'] = df['temperature'].astype(float)
    df['heart_rate'] = df['heart_rate'].astype(int)
    df['respiratory_rate'] = df['respiratory_rate'].astype(int)

    df['fever'] = (df['temperature'] >= 38.0).astype(int)
    df['high_bp'] = (df['blood_pressure'] >= 140).astype(int)

    for symptom in SYMPTOMS:
        df[symptom] = df.apply(
            lambda row: int(
                text_contains_keywords(row['chief_complaint'], KEYWORDS[symptom])
                or text_contains_keywords(row['history_present_illness'], KEYWORDS[symptom])
            ),
            axis=1
        )

    X = df[FEATURE_COLUMNS]
    y = df['diagnosis']
    return X, y


def train_model():
    X, y = load_training_data()
    model = DecisionTreeClassifier(random_state=42, max_depth=8)
    model.fit(X, y)
    logger.info(f"Trained Decision Tree on {len(X)} patient records")
    return model


def build_input_features(data):
    bp_input = str(data.get('blood_pressure', '0')).strip()
    systolic = 0
    if '/' in bp_input:
        try:
            systolic = int(bp_input.split('/')[0])
        except ValueError:
            systolic = 0
    elif bp_input.isdigit():
        systolic = int(bp_input)

    temperature = float(data.get('temperature') or 0)
    heart_rate = int(data.get('heart_rate') or 0)
    respiratory_rate = int(data.get('respiratory_rate') or 0)

    combined_text = ' '.join([
        str(data.get('chief_complaint', '')),
        str(data.get('history_present_illness', ''))
    ]).lower()

    features = {
        'fever': int(temperature >= 38.0),
        'high_bp': int(systolic >= 140),
        'cough': int(text_contains_keywords(combined_text, KEYWORDS['cough'])),
        'headache': int(text_contains_keywords(combined_text, KEYWORDS['headache'])),
        'fatigue': int(text_contains_keywords(combined_text, KEYWORDS['fatigue'])),
        'body_pain': int(text_contains_keywords(combined_text, KEYWORDS['body_pain'])),
        'sore_throat': int(text_contains_keywords(combined_text, KEYWORDS['sore_throat'])),
        'vomiting': int(text_contains_keywords(combined_text, KEYWORDS['vomiting'])),
        'diarrhea': int(text_contains_keywords(combined_text, KEYWORDS['diarrhea'])),
        'blood_pressure': systolic,
        'heart_rate': heart_rate,
        'temperature': temperature,
        'respiratory_rate': respiratory_rate
    }

    return [features[col] for col in FEATURE_COLUMNS], features


model = train_model()


@app.route("/")
def home():
    return "Obeso Clinic AI API - Running"


@app.route("/predict", methods=["POST"])
def predict():
    try:
        data = request.get_json()
        if not data:
            return jsonify({"error": "No data received"}), 400

        input_data, features = build_input_features(data)
        prediction = model.predict([input_data])[0]

        if hasattr(model, 'predict_proba'):
            probabilities = model.predict_proba([input_data])[0]
            classes = model.classes_
            scores = {
                disease: round(float(prob) * 100, 1)
                for disease, prob in zip(classes, probabilities)
            }
        else:
            scores = {prediction: 100.0}

        top3 = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:3]

        return jsonify({
            "disease": prediction,
            "confidence": scores.get(prediction, 0.0),
            "top3": [{"disease": d, "confidence": c} for d, c in top3],
            "features": features
        })

    except Exception as e:
        logger.exception("Prediction error")
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8000, debug=True)
