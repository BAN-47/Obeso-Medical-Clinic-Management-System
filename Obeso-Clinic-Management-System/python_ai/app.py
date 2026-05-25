from flask import Flask, request, jsonify
from flask_cors import CORS
import pandas as pd
from sklearn.tree import DecisionTreeClassifier
from sklearn.calibration import CalibratedClassifierCV
import mysql.connector
import logging
import os
import numpy as np

app = Flask(__name__)
CORS(app)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'obeso_clinic_database'
}

CSV_PATH = os.path.join(os.path.dirname(__file__), 'disease_data.csv')

SYMPTOMS = [
    'cough',
    'headache',
    'fatigue',
    'body_pain',
    'sore_throat',
    'vomiting',
    'diarrhea'
]

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
    'respiratory_rate',
    'past_checkup_count',
    'has_past_fever',
    'has_past_high_bp',
    'has_past_cough',
    'has_past_headache',
    'most_common_past_diagnosis'
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


def load_training_data_from_csv():
    df = pd.read_csv(CSV_PATH)

    if 'disease' not in df.columns:
        raise ValueError("CSV training file must contain a 'disease' column.")

    for col in FEATURE_COLUMNS:
        if col not in df.columns:
            df[col] = 0

    for col in FEATURE_COLUMNS:
        df[col] = pd.to_numeric(df[col], errors='coerce').fillna(0)

    return df[FEATURE_COLUMNS], df['disease']


def load_training_data_from_db(conn):
    query = """
    SELECT
        checkup_id,
        patient_id,
        checkup_date,
        diagnosis,
        blood_pressure,
        heart_rate,
        temperature,
        respiratory_rate,
        chief_complaint,
        history_present_illness
    FROM checkups
    WHERE diagnosis IS NOT NULL
    ORDER BY patient_id, checkup_date
    """

    df = pd.read_sql(query, conn)

    if df.empty:
        return pd.DataFrame(columns=FEATURE_COLUMNS), pd.Series(dtype='str')

    df = df.dropna(subset=[
        'diagnosis',
        'blood_pressure',
        'temperature',
        'heart_rate',
        'respiratory_rate'
    ])

    df['blood_pressure'] = df['blood_pressure'].astype(str).apply(
        lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
    )

    df['temperature'] = pd.to_numeric(df['temperature'], errors='coerce').fillna(0)
    df['heart_rate'] = pd.to_numeric(df['heart_rate'], errors='coerce').fillna(0)
    df['respiratory_rate'] = pd.to_numeric(df['respiratory_rate'], errors='coerce').fillna(0)

    df['fever'] = (df['temperature'] >= 38.0).astype(int)
    df['high_bp'] = (df['blood_pressure'] >= 140).astype(int)

    for symptom in SYMPTOMS:
        df[symptom] = df.apply(
            lambda row: int(
                text_contains_keywords(row['chief_complaint'], KEYWORDS[symptom]) or
                text_contains_keywords(row['history_present_illness'], KEYWORDS[symptom])
            ),
            axis=1
        )

    history_features = []

    for idx, row in df.iterrows():
        patient_id = row['patient_id']
        checkup_date = row['checkup_date']

        past_query = """
        SELECT diagnosis, temperature, blood_pressure,
               chief_complaint, history_present_illness
        FROM checkups
        WHERE patient_id = %s AND checkup_date < %s
        """

        past_df = pd.read_sql(
            past_query,
            conn,
            params=[patient_id, checkup_date]
        )

        past_checkup_count = len(past_df)

        has_past_fever = int(
            any(past_df['temperature'] >= 38.0)
            if not past_df.empty else 0
        )

        has_past_high_bp = int(
            any(
                past_df['blood_pressure'].astype(str).apply(
                    lambda x: int(x.split('/')[0])
                    if '/' in x else int(x)
                    if str(x).isdigit() else 0
                ) >= 140
            ) if not past_df.empty else 0
        )

        has_past_cough = int(
            any(
                past_df.apply(
                    lambda r:
                    text_contains_keywords(
                        r['chief_complaint'],
                        KEYWORDS['cough']
                    ) or
                    text_contains_keywords(
                        r['history_present_illness'],
                        KEYWORDS['cough']
                    ),
                    axis=1
                )
            ) if not past_df.empty else 0
        )

        has_past_headache = int(
            any(
                past_df.apply(
                    lambda r:
                    text_contains_keywords(
                        r['chief_complaint'],
                        KEYWORDS['headache']
                    ) or
                    text_contains_keywords(
                        r['history_present_illness'],
                        KEYWORDS['headache']
                    ),
                    axis=1
                )
            ) if not past_df.empty else 0
        )

        most_common_past_diagnosis = (
            past_df['diagnosis'].mode().iloc[0]
            if not past_df.empty and not past_df['diagnosis'].mode().empty
            else 'None'
        )

        history_features.append({
            'past_checkup_count': past_checkup_count,
            'has_past_fever': has_past_fever,
            'has_past_high_bp': has_past_high_bp,
            'has_past_cough': has_past_cough,
            'has_past_headache': has_past_headache,
            'most_common_past_diagnosis': hash(
                most_common_past_diagnosis
            ) % 1000
        })

    history_df = pd.DataFrame(history_features)

    df = pd.concat(
        [df.reset_index(drop=True), history_df],
        axis=1
    )

    X = df[FEATURE_COLUMNS]
    y = df['diagnosis']

    return X, y


def load_training_data():
    if os.path.exists(CSV_PATH):
        logger.info(f"Loading training data from CSV: {CSV_PATH}")

        csv_X, csv_y = load_training_data_from_csv()

        try:
            conn = connect_db()

            db_X, db_y = load_training_data_from_db(conn)

            conn.close()

            if not db_X.empty:
                logger.info(
                    "Appending database history examples to CSV training data."
                )

                X = pd.concat([csv_X, db_X], ignore_index=True)
                y = pd.concat([csv_y, db_y], ignore_index=True)

                return X, y

        except Exception as e:
            logger.warning(f"DB training examples unavailable: {e}")

        return csv_X, csv_y

    conn = connect_db()

    X, y = load_training_data_from_db(conn)

    conn.close()

    if X.empty:
        raise ValueError("No labeled training data available.")

    return X, y


def train_model():
    X, y = load_training_data()

<<<<<<< HEAD
    # FIX FOR YOUR ERROR:
    # Count smallest class size
    class_counts = y.value_counts()
    min_class_count = class_counts.min()

    logger.info(f"Class counts:\n{class_counts}")

=======
    # Make a smart decision tree with better tuning to prevent overfitting
    # max_depth=5 prevents the tree from becoming too complex and overfitting
    # min_samples_split=5 means each split must have at least 5 samples
>>>>>>> 708bbbde0eb704114aa3eca4da467d88e01c6ff1
    base_model = DecisionTreeClassifier(
        random_state=42,
        max_depth=5,
        min_samples_split=5,
        min_samples_leaf=2,
        class_weight='balanced'
    )

<<<<<<< HEAD
    # If there are enough samples, use calibration
    if min_class_count >= 2:

        # Use smaller cv automatically
        cv_value = min(5, min_class_count)

        logger.info(f"Using CalibratedClassifierCV with cv={cv_value}")

        model = CalibratedClassifierCV(
            estimator=base_model,
            method='sigmoid',
            cv=cv_value
        )

    else:
        # Not enough samples for calibration
        logger.warning(
            "Not enough samples for calibration. "
            "Using plain DecisionTreeClassifier."
        )

        model = base_model

=======
    # Avoid requesting more CV folds than the smallest class size
    min_class_count = y.value_counts().min()
    cv = min(5, min_class_count)
    if cv < 2:
        logger.warning(
            "Not enough examples per class for calibration cross-validation. Training without calibrated probabilities."
        )
        base_model.fit(X, y)
        logger.info(f"Trained Decision Tree on {len(X)} patient records")
        return base_model

    if cv < 5:
        logger.warning(
            f"Using {cv}-fold calibration because some classes have fewer than 5 examples."
        )

    # Wrap with CalibratedClassifierCV for better probability calibration
    model = CalibratedClassifierCV(base_model, method='sigmoid', cv=cv)

    # Teach the tree with the data
>>>>>>> 708bbbde0eb704114aa3eca4da467d88e01c6ff1
    model.fit(X, y)

    logger.info(f"Trained model on {len(X)} patient records")

    return model


def build_input_features(data):
    patient_id = data.get('patient_id', '')

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

    symptom_flags = {
        symptom: int(data.get(symptom, 0))
        for symptom in SYMPTOMS
    }

    past_checkup_count = 0
    has_past_fever = 0
    has_past_high_bp = 0
    has_past_cough = 0
    has_past_headache = 0
    most_common_past_diagnosis_hash = 0

    if patient_id:
        try:
            conn = connect_db()

            past_query = """
            SELECT diagnosis, temperature, blood_pressure,
                   chief_complaint, history_present_illness
            FROM checkups
            WHERE patient_id = %s
            """

            past_df = pd.read_sql(
                past_query,
                conn,
                params=[patient_id]
            )

            conn.close()

            if not past_df.empty:

                past_checkup_count = len(past_df)

                has_past_fever = int(
                    any(past_df['temperature'] >= 38.0)
                )

                has_past_high_bp = int(
                    any(
                        past_df['blood_pressure'].astype(str).apply(
                            lambda x: int(x.split('/')[0])
                            if '/' in x else int(x)
                            if str(x).isdigit() else 0
                        ) >= 140
                    )
                )

                has_past_cough = int(
                    any(
                        past_df.apply(
                            lambda r:
                            text_contains_keywords(
                                r['chief_complaint'],
                                KEYWORDS['cough']
                            ) or
                            text_contains_keywords(
                                r['history_present_illness'],
                                KEYWORDS['cough']
                            ),
                            axis=1
                        )
                    )
                )

                has_past_headache = int(
                    any(
                        past_df.apply(
                            lambda r:
                            text_contains_keywords(
                                r['chief_complaint'],
                                KEYWORDS['headache']
                            ) or
                            text_contains_keywords(
                                r['history_present_illness'],
                                KEYWORDS['headache']
                            ),
                            axis=1
                        )
                    )
                )

                most_common_past_diagnosis = (
                    past_df['diagnosis'].mode().iloc[0]
                    if not past_df['diagnosis'].mode().empty
                    else 'None'
                )

                most_common_past_diagnosis_hash = (
                    hash(most_common_past_diagnosis) % 1000
                )

        except Exception as e:
            logger.warning(f"Past history lookup failed: {e}")

    features = {
        'fever': int(data.get('fever', 0)) or int(temperature >= 38.0),
        'high_bp': int(systolic >= 140),

        'cough':
            symptom_flags['cough'] or
            int(text_contains_keywords(combined_text, KEYWORDS['cough'])),

        'headache':
            symptom_flags['headache'] or
            int(text_contains_keywords(combined_text, KEYWORDS['headache'])),

        'fatigue':
            symptom_flags['fatigue'] or
            int(text_contains_keywords(combined_text, KEYWORDS['fatigue'])),

        'body_pain':
            symptom_flags['body_pain'] or
            int(text_contains_keywords(combined_text, KEYWORDS['body_pain'])),

        'sore_throat':
            symptom_flags['sore_throat'] or
            int(text_contains_keywords(combined_text, KEYWORDS['sore_throat'])),

        'vomiting':
            symptom_flags['vomiting'] or
            int(text_contains_keywords(combined_text, KEYWORDS['vomiting'])),

        'diarrhea':
            symptom_flags['diarrhea'] or
            int(text_contains_keywords(combined_text, KEYWORDS['diarrhea'])),

        'blood_pressure': systolic,
        'heart_rate': heart_rate,
        'temperature': temperature,
        'respiratory_rate': respiratory_rate,
        'past_checkup_count': past_checkup_count,
        'has_past_fever': has_past_fever,
        'has_past_high_bp': has_past_high_bp,
        'has_past_cough': has_past_cough,
        'has_past_headache': has_past_headache,
        'most_common_past_diagnosis': most_common_past_diagnosis_hash
    }

    return pd.DataFrame([features])[FEATURE_COLUMNS], features


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

        prediction = model.predict(input_data)[0]

        probabilities = model.predict_proba(input_data)[0]

        classes = model.classes_

        scores = {
            disease: round(float(prob) * 100, 1)
            for disease, prob in zip(classes, probabilities)
        }

        top3 = sorted(
            scores.items(),
            key=lambda x: x[1],
            reverse=True
        )[:3]

        confidence = scores.get(prediction, 0.0)

        return jsonify({
            "disease": prediction,
            "confidence": confidence,
            "top3": [
                {
                    "disease": d,
                    "confidence": c
                }
                for d, c in top3
            ],
            "features": features
        })

    except Exception as e:
        logger.exception("Prediction error")

        return jsonify({
            "error": str(e)
        }), 500


if __name__ == "__main__":
    app.run(
        host="127.0.0.1",
        port=8000,
        debug=True
    )