from flask import Flask, request, jsonify  # Import tools to make a web server
from flask_cors import CORS  # Allow the web server to talk to other websites
import pandas as pd  # Import a tool to work with data like spreadsheets
from sklearn.tree import DecisionTreeClassifier  # Import the smart tree that guesses illnesses
from sklearn.calibration import CalibratedClassifierCV  # For better probability calibration
from sklearn.preprocessing import LabelEncoder  # For encoding categorical data
import mysql.connector  # Import tool to talk to the database
import logging  # Import tool to write messages about what's happening
import os  # Import tool to work with file paths
import numpy as np  # For numerical operations

app = Flask(__name__)  # Make a new web server
CORS(app)  # Let the server talk to the clinic website

# Set up messages to help us know what's happening
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Tell the computer how to connect to the clinic's database
DB_CONFIG = {
    'host': 'localhost',  # The database is on this computer
    'user': 'root',  # Username to log in
    'password': '',  # No password needed
    'database': 'obeso_clinic_database'  # Name of the database
}

# Path to the CSV file that can also provide training examples for the AI
CSV_PATH = os.path.join(os.path.dirname(__file__), 'disease_data.csv')

# List of sickness signs we look for in patient words
SYMPTOMS = ['cough', 'headache', 'fatigue', 'body_pain',
            'sore_throat', 'vomiting', 'diarrhea']

# List of things the smart tree uses to guess illnesses
FEATURE_COLUMNS = [
    'fever',  # Does the patient have a high temperature?
    'high_bp',  # Does the patient have high blood pressure?
    'cough',  # Did the patient mention coughing?
    'headache',  # Did the patient mention headache?
    'fatigue',  # Did the patient mention feeling tired?
    'body_pain',  # Did the patient mention body pain?
    'sore_throat',  # Did the patient mention sore throat?
    'vomiting',  # Did the patient mention throwing up?
    'diarrhea',  # Did the patient mention stomach problems?
    'blood_pressure',  # The patient's blood pressure number
    'heart_rate',  # The patient's heart beat speed
    'temperature',  # The patient's temperature number
    'respiratory_rate',  # The patient's breathing speed
    # Things from the patient's past visits
    'past_checkup_count',  # How many times has the patient visited before?
    'has_past_fever',  # Has the patient had fever before?
    'has_past_high_bp',  # Has the patient had high blood pressure before?
    'has_past_cough',  # Has the patient coughed before?
    'has_past_headache',  # Has the patient had headaches before?
    'most_common_past_diagnosis'  # What sickness did the patient have most often?
]

# Words that mean each sickness sign
KEYWORDS = {
    'cough': ['cough', 'dry cough', 'productive cough'],  # Words for coughing
    'headache': ['headache', 'migraine', 'head pain'],  # Words for head pain
    'fatigue': ['fatigue', 'tired', 'weakness'],  # Words for feeling tired
    'body_pain': ['body pain', 'muscle pain', 'aches'],  # Words for body hurting
    'sore_throat': ['sore throat', 'throat pain', 'pharyngitis'],  # Words for throat pain
    'vomiting': ['vomit', 'vomiting', 'nausea'],  # Words for throwing up
    'diarrhea': ['diarrhea', 'diarrhoea', 'loose stool']  # Words for stomach issues
}


def connect_db():
    # Open a door to talk to the database
    return mysql.connector.connect(**DB_CONFIG)


def text_contains_keywords(text, keywords):
    # Make all words lowercase so we can find them easily
    text = str(text).lower()
    # Check if any of the special words are in the patient's words
    return any(keyword in text for keyword in keywords)


def load_training_data_from_csv():
    # Load training examples straight from the CSV file
    df = pd.read_csv(CSV_PATH)

    # Make sure the CSV has the expected target column
    if 'disease' not in df.columns:
        raise ValueError("CSV training file must contain a 'disease' column.")

    # Add any missing feature columns as zeros so the model can still train
    for col in FEATURE_COLUMNS:
        if col not in df.columns:
            df[col] = 0

    # Convert all feature columns to numeric values
    for col in FEATURE_COLUMNS:
        df[col] = pd.to_numeric(df[col], errors='coerce').fillna(0).astype(int)

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

    history_features = []
    for idx, row in df.iterrows():
        patient_id = row['patient_id']
        checkup_date = row['checkup_date']
        past_query = """
        SELECT diagnosis, temperature, blood_pressure, chief_complaint, history_present_illness
        FROM checkups
        WHERE patient_id = %s AND checkup_date < %s
        """
        past_df = pd.read_sql(past_query, conn, params=[patient_id, checkup_date])

        past_checkup_count = len(past_df)
        has_past_fever = int(any(past_df['temperature'] >= 38.0) if not past_df.empty else 0)
        has_past_high_bp = int(any(
            past_df['blood_pressure'].astype(str).apply(
                lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
            ) >= 140
        ) if not past_df.empty else 0)
        has_past_cough = int(any(
            past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['cough']) or
                                 text_contains_keywords(r['history_present_illness'], KEYWORDS['cough']), axis=1)
        ) if not past_df.empty else 0)
        has_past_headache = int(any(
            past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['headache']) or
                                 text_contains_keywords(r['history_present_illness'], KEYWORDS['headache']), axis=1)
        ) if not past_df.empty else 0)
        most_common_past_diagnosis = past_df['diagnosis'].mode().iloc[0] if not past_df.empty and not past_df['diagnosis'].mode().empty else 'None'

        history_features.append({
            'past_checkup_count': past_checkup_count,
            'has_past_fever': has_past_fever,
            'has_past_high_bp': has_past_high_bp,
            'has_past_cough': has_past_cough,
            'has_past_headache': has_past_headache,
            'most_common_past_diagnosis': hash(most_common_past_diagnosis) % 1000
        })

    history_df = pd.DataFrame(history_features)
    df = pd.concat([df.reset_index(drop=True), history_df], axis=1)
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
                logger.info("Appending database history examples to CSV training data.")
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
    # Get the training data
    X, y = load_training_data()
    # Make a smart decision tree with better tuning to prevent overfitting
    # max_depth=5 prevents the tree from becoming too complex and overfitting
    # min_samples_split=5 means each split must have at least 5 samples
    base_model = DecisionTreeClassifier(
        random_state=42, 
        max_depth=5,  # Reduced from 8 to prevent overfitting and 100% confidence
        min_samples_split=5,  # Require at least 5 samples per split
        min_samples_leaf=2,  # Require at least 2 samples per leaf
        class_weight='balanced'  # Handle class imbalance
    )
    
    # Wrap with CalibratedClassifierCV for better probability calibration
    model = CalibratedClassifierCV(base_model, method='sigmoid', cv=5)
    
    # Teach the tree with the data
    model.fit(X, y)
    
    # Write a message saying the tree is ready
    logger.info(f"Trained Calibrated Decision Tree on {len(X)} patient records")
    return model


def build_input_features(data):
    # Get the patient's ID
    patient_id = data.get('patient_id', '')
    # Get blood pressure and fix it
    bp_input = str(data.get('blood_pressure', '0')).strip()
    systolic = 0
    if '/' in bp_input:
        try:
            systolic = int(bp_input.split('/')[0])  # Take the top number
        except ValueError:
            systolic = 0
    elif bp_input.isdigit():
        systolic = int(bp_input)

    # Get other numbers
    temperature = float(data.get('temperature') or 0)
    heart_rate = int(data.get('heart_rate') or 0)
    respiratory_rate = int(data.get('respiratory_rate') or 0)

    # Put all patient words together
    combined_text = ' '.join([
        str(data.get('chief_complaint', '')),
        str(data.get('history_present_illness', ''))
    ]).lower()

    # Keep direct symptom flags from the UI if provided, otherwise fall back to text search
    symptom_flags = {
        symptom: int(data.get(symptom, 0))
        for symptom in SYMPTOMS
    }

    # Default history info when no database history is available
    past_checkup_count = 0
    has_past_fever = 0
    has_past_high_bp = 0
    has_past_cough = 0
    has_past_headache = 0
    most_common_past_diagnosis = 'None'
    most_common_past_diagnosis_hash = 0

    if patient_id:
        try:
            conn = connect_db()
            past_query = """
            SELECT diagnosis, temperature, blood_pressure, chief_complaint, history_present_illness
            FROM checkups
            WHERE patient_id = %s
            """
            past_df = pd.read_sql(past_query, conn, params=[patient_id])
            conn.close()

            if not past_df.empty:
                past_checkup_count = len(past_df)
                has_past_fever = int(any(past_df['temperature'] >= 38.0))
                has_past_high_bp = int(any(
                    past_df['blood_pressure'].astype(str).apply(
                        lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
                    ) >= 140
                ))
                has_past_cough = int(any(
                    past_df.apply(
                        lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['cough'])
                                  or text_contains_keywords(r['history_present_illness'], KEYWORDS['cough']),
                        axis=1
                    )
                ))
                has_past_headache = int(any(
                    past_df.apply(
                        lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['headache'])
                                  or text_contains_keywords(r['history_present_illness'], KEYWORDS['headache']),
                        axis=1
                    )
                ))
                most_common_past_diagnosis = (
                    past_df['diagnosis'].mode().iloc[0]
                    if not past_df['diagnosis'].mode().empty
                    else 'None'
                )
                most_common_past_diagnosis_hash = hash(most_common_past_diagnosis) % 1000
        except Exception as e:
            logger.warning(f"Past history lookup failed: {e}")

    # Make a list of all the clues for this patient
    features = {
        'fever': int(data.get('fever', 0)) or int(temperature >= 38.0),
        'high_bp': int(systolic >= 140),
        'cough': symptom_flags['cough'] or int(text_contains_keywords(combined_text, KEYWORDS['cough'])),
        'headache': symptom_flags['headache'] or int(text_contains_keywords(combined_text, KEYWORDS['headache'])),
        'fatigue': symptom_flags['fatigue'] or int(text_contains_keywords(combined_text, KEYWORDS['fatigue'])),
        'body_pain': symptom_flags['body_pain'] or int(text_contains_keywords(combined_text, KEYWORDS['body_pain'])),
        'sore_throat': symptom_flags['sore_throat'] or int(text_contains_keywords(combined_text, KEYWORDS['sore_throat'])),
        'vomiting': symptom_flags['vomiting'] or int(text_contains_keywords(combined_text, KEYWORDS['vomiting'])),
        'diarrhea': symptom_flags['diarrhea'] or int(text_contains_keywords(combined_text, KEYWORDS['diarrhea'])),
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

    # Return the clues in the right order, and all the features
    return pd.DataFrame([features])[FEATURE_COLUMNS], features


def generate_future_outcome(prediction, features):
    risk_level = 'Low'
    summary = 'Patient is likely to remain stable with current care.'
    recommendation = 'Continue monitoring and follow standard treatment guidelines.'

    if features['past_checkup_count'] >= 3 or features['has_past_high_bp'] == 1:
        risk_level = 'High'
        summary = 'Patient has a history of repeat visits and significant vitals changes; future illness risk is elevated.'
        recommendation = 'Review chronic conditions and schedule an early follow-up visit.'
    elif features['has_past_fever'] == 1 or features['has_past_headache'] == 1:
        risk_level = 'Moderate'
        summary = 'Patient has prior symptoms that could recur; prepare for potential follow-up care.'
        recommendation = 'Advise symptom tracking and prompt re-evaluation if symptoms return.'

    if prediction in ['Hypertension', 'Heart Failure', 'Diabetes', 'COPD', 'Asthma', 'Chronic Migraine']:
        risk_level = max(risk_level, 'High', key=lambda x: ['Low','Moderate','High'].index(x))
        summary = 'Predicted condition is chronic and needs careful monitoring.'
        recommendation = 'Assess long-term management and patient education on warning signs.'
    elif prediction in ['COVID-19', 'Pneumonia', 'Dengue', 'Typhoid']:
        risk_level = 'High'
        summary = 'Predicted serious infectious illness requires urgent clinical attention and monitoring.'
        recommendation = 'Immediate testing/confirmation, isolation precautions, and close follow-up care.'
    elif prediction in ['Influenza']:
        risk_level = max(risk_level, 'Moderate', key=lambda x: ['Low','Moderate','High'].index(x))
        summary = 'Predicted infectious illness may require close follow-up.'
        recommendation = 'Ensure early recheck and supportive care if symptoms worsen.'

    return {
        'risk_level': risk_level,
        'summary': summary,
        'recommendation': recommendation
    }


# Train the smart tree once when the program starts
model = train_model()


@app.route("/")
def home():
    # This is like the front door - just says hello
    return "Obeso Clinic AI API - Running"


@app.route("/predict", methods=["POST"])
def predict():
    try:
        # Get the information sent from the website
        data = request.get_json()
        if not data:
            return jsonify({"error": "No data received"}), 400

        # Turn the patient info into clues for the tree
        input_data, features = build_input_features(data)

        # Ask the smart tree to guess the sickness
        prediction = model.predict(input_data)[0]

        # Get how sure the tree is about each guess - use predict_proba for calibrated probabilities
        probabilities = model.predict_proba(input_data)[0]
        classes = model.classes_
        
        # Make a list of sicknesses with how sure the tree is (in percentages)
        scores = {
            disease: round(float(prob) * 100, 1)
            for disease, prob in zip(classes, probabilities)
        }

        # Pick the top 3 best guesses
        top3 = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:3]
        
        # Get the confidence for the main prediction
        confidence = scores.get(prediction, 0.0)
        
        # Generate future outcome based on past history
        future_outcome = generate_future_outcome(prediction, features)

        # Send back the answer
        return jsonify({
            "disease": prediction,  # The sickness the tree thinks
            "confidence": confidence,  # How sure the tree is
            "top3": [{"disease": d, "confidence": c} for d, c in top3],  # Top 3 guesses
            "future_outcome": future_outcome,
            "features": features  # All the clues used
        })

    except Exception as e:
        # If something goes wrong, write it down and send error message
        logger.exception("Prediction error")
        return jsonify({"error": str(e)}), 500


@app.route("/future-illnesses", methods=["POST"])
def get_future_illnesses():
    """Get top 3 most likely future illnesses for a patient based on their medical history"""
    try:
        data = request.get_json()
        patient_id = data.get('patient_id')
        
        if not patient_id:
            return jsonify({"error": "Patient ID required"}), 400
        
        # Query past checkups for this patient
        conn = connect_db()
        past_query = """
        SELECT diagnosis, checkup_date 
        FROM checkups
        WHERE patient_id = %s
        ORDER BY checkup_date DESC
        LIMIT 20
        """
        past_df = pd.read_sql(past_query, conn, params=[patient_id])
        conn.close()
        
        if past_df.empty:
            return jsonify({
                "patient_id": patient_id,
                "future_illnesses": [],
                "message": "No past medical history found"
            })
        
        # Count the frequency of each diagnosis from past checkups
        diagnosis_counts = past_df['diagnosis'].value_counts().head(10)
        
        # Get all possible diagnoses from the training data
        X, y = load_training_data()
        all_diagnoses = set(y.unique())
        
        # Predict what illnesses might occur based on common past patterns
        # Create a list of likely future illnesses
        future_illnesses = []
        
        # Add diagnoses that appeared in the patient's history
        for diagnosis, count in diagnosis_counts.items():
            if diagnosis and diagnosis != 'None':
                frequency_score = (count / len(past_df)) * 100
                future_illnesses.append({
                    "disease": diagnosis,
                    "likelihood": round(frequency_score, 1),
                    "reason": f"Recurring condition (appeared {int(count)} times in history)"
                })
        
        # If we have room, add common diseases from the training data
        if len(future_illnesses) < 3:
            # Get diseases from the disease_data.csv and count frequency
            try:
                disease_df = pd.read_csv(CSV_PATH)
                if 'disease' in disease_df.columns:
                    disease_counts = disease_df['disease'].value_counts()
                    for disease, count in disease_counts.items():
                        if disease not in [ill['disease'] for ill in future_illnesses]:
                            general_likelihood = (count / len(disease_df)) * 100
                            future_illnesses.append({
                                "disease": disease,
                                "likelihood": round(general_likelihood, 1),
                                "reason": "Common disease in general population"
                            })
                        if len(future_illnesses) >= 3:
                            break
            except Exception as e:
                logger.warning(f"Could not load disease data: {e}")
        
        # Sort by likelihood and take top 3
        future_illnesses = sorted(future_illnesses, key=lambda x: x['likelihood'], reverse=True)[:3]
        
        return jsonify({
            "patient_id": patient_id,
            "future_illnesses": future_illnesses,
            "total_past_checkups": len(past_df)
        })
        
    except Exception as e:
        logger.exception("Future illnesses prediction error")
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    # Start the web server so doctors can ask for predictions
    app.run(host="127.0.0.1", port=8000, debug=True)
