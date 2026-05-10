from flask import Flask, request, jsonify  # Import tools to make a web server
from flask_cors import CORS  # Allow the web server to talk to other websites
import pandas as pd  # Import a tool to work with data like spreadsheets
from sklearn.tree import DecisionTreeClassifier  # Import the smart tree that guesses illnesses
import mysql.connector  # Import tool to talk to the database
import logging  # Import tool to write messages about what's happening

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


def load_training_data():
    # Connect to the database
    conn = connect_db()
    # Ask the database for old patient visits that have sickness names
    query = """
    SELECT
        checkup_id,  # ID of the visit
        patient_id,  # ID of the patient
        checkup_date,  # When the visit happened
        diagnosis,  # What sickness the doctor said
        blood_pressure,  # Blood pressure number
        heart_rate,  # Heart beat number
        temperature,  # Temperature number
        respiratory_rate,  # Breathing number
        chief_complaint,  # What the patient said was wrong
        history_present_illness  # More details about what's wrong
    FROM checkups
    WHERE diagnosis IS NOT NULL  # Only visits where doctor gave a sickness name
    ORDER BY patient_id, checkup_date  # Sort by patient and date
    """

    # Get the data from database and put it in a table
    df = pd.read_sql(query, conn)

    # If no data, stop and say there's no data
    if df.empty:
        conn.close()
        raise ValueError("No labeled checkup records available for training.")

    # Remove visits that don't have all the numbers we need
    df = df.dropna(subset=['diagnosis', 'blood_pressure', 'temperature', 'heart_rate', 'respiratory_rate'])
    
    # Fix blood pressure to just the top number
    df['blood_pressure'] = df['blood_pressure'].astype(str).apply(
        lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
    )
    # Make sure numbers are the right type
    df['temperature'] = df['temperature'].astype(float)
    df['heart_rate'] = df['heart_rate'].astype(int)
    df['respiratory_rate'] = df['respiratory_rate'].astype(int)

    # Check if temperature is high (fever)
    df['fever'] = (df['temperature'] >= 38.0).astype(int)
    # Check if blood pressure is high
    df['high_bp'] = (df['blood_pressure'] >= 140).astype(int)

    # For each sickness sign, check if patient mentioned it
    for symptom in SYMPTOMS:
        df[symptom] = df.apply(
            lambda row: int(
                text_contains_keywords(row['chief_complaint'], KEYWORDS[symptom])
                or text_contains_keywords(row['history_present_illness'], KEYWORDS[symptom])
            ),
            axis=1  # Check each row
        )

    # Add information from patient's past visits
    history_features = []
    for idx, row in df.iterrows():  # For each visit
        patient_id = row['patient_id']
        checkup_date = row['checkup_date']
        
        # Ask database for this patient's visits before this date
        past_query = """
        SELECT diagnosis, temperature, blood_pressure, chief_complaint, history_present_illness
        FROM checkups
        WHERE patient_id = %s AND checkup_date < %s
        """
        past_df = pd.read_sql(past_query, conn, params=[patient_id, checkup_date])
        
        # Count how many past visits
        past_checkup_count = len(past_df)
        # Check if patient had fever before
        has_past_fever = int(any(past_df['temperature'] >= 38.0) if not past_df.empty else 0)
        # Check if patient had high BP before
        has_past_high_bp = int(any(
            past_df['blood_pressure'].astype(str).apply(
                lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
            ) >= 140
        ) if not past_df.empty else 0)
        
        # Check if patient coughed before
        has_past_cough = int(any(
            past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['cough']) or 
                                 text_contains_keywords(r['history_present_illness'], KEYWORDS['cough']), axis=1)
        ) if not past_df.empty else 0)
        
        # Check if patient had headaches before
        has_past_headache = int(any(
            past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['headache']) or 
                                 text_contains_keywords(r['history_present_illness'], KEYWORDS['headache']), axis=1)
        ) if not past_df.empty else 0)
        
        # Find the sickness this patient had most often
        most_common_past_diagnosis = past_df['diagnosis'].mode().iloc[0] if not past_df.empty and not past_df['diagnosis'].mode().empty else 'None'
        
        # Add all past info to the list
        history_features.append({
            'past_checkup_count': past_checkup_count,
            'has_past_fever': has_past_fever,
            'has_past_high_bp': has_past_high_bp,
            'has_past_cough': has_past_cough,
            'has_past_headache': has_past_headache,
            'most_common_past_diagnosis': hash(most_common_past_diagnosis) % 1000  # Turn sickness name into a number
        })
    
    # Add past info to the main table
    history_df = pd.DataFrame(history_features)
    df = pd.concat([df, history_df], axis=1)

    # Close the database door
    conn.close()

    # Get the clues (X) and the answers (y)
    X = df[FEATURE_COLUMNS]
    y = df['diagnosis']
    return X, y


def train_model():
    # Get the training data
    X, y = load_training_data()
    # Make a smart decision tree
    # The tree learns by asking yes/no questions about the clues
    # It tries to group similar sicknesses together
    # max_depth=8 means the tree won't get too tall (prevents guessing too much)
    model = DecisionTreeClassifier(random_state=42, max_depth=8)
    # Teach the tree with the data
    model.fit(X, y)
    # Write a message saying the tree is ready
    logger.info(f"Trained Decision Tree on {len(X)} patient records")
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

    # Connect to database to get patient's past visits
    conn = connect_db()
    past_query = """
    SELECT diagnosis, temperature, blood_pressure, chief_complaint, history_present_illness
    FROM checkups
    WHERE patient_id = %s
    """
    past_df = pd.read_sql(past_query, conn, params=[patient_id])
    conn.close()
    
    # Count past visits
    past_checkup_count = len(past_df)
    # Check past fever
    has_past_fever = int(any(past_df['temperature'] >= 38.0) if not past_df.empty else 0)
    # Check past high BP
    has_past_high_bp = int(any(
        past_df['blood_pressure'].astype(str).apply(
            lambda x: int(x.split('/')[0]) if '/' in x else int(x) if str(x).isdigit() else 0
        ) >= 140
    ) if not past_df.empty else 0)
    
    # Check past cough
    has_past_cough = int(any(
        past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['cough']) or 
                             text_contains_keywords(r['history_present_illness'], KEYWORDS['cough']), axis=1)
    ) if not past_df.empty else 0)
    
    # Check past headache
    has_past_headache = int(any(
        past_df.apply(lambda r: text_contains_keywords(r['chief_complaint'], KEYWORDS['headache']) or 
                             text_contains_keywords(r['history_present_illness'], KEYWORDS['headache']), axis=1)
    ) if not past_df.empty else 0)
    
    # Find most common past sickness
    most_common_past_diagnosis = past_df['diagnosis'].mode().iloc[0] if not past_df.empty and not past_df['diagnosis'].mode().empty else 'None'
    most_common_past_diagnosis_hash = hash(most_common_past_diagnosis) % 1000  # Turn into number

    # Make a list of all the clues for this patient
    features = {
        'fever': int(temperature >= 38.0),  # Is temperature high?
        'high_bp': int(systolic >= 140),  # Is blood pressure high?
        'cough': int(text_contains_keywords(combined_text, KEYWORDS['cough'])),  # Did patient mention cough?
        'headache': int(text_contains_keywords(combined_text, KEYWORDS['headache'])),  # Did patient mention headache?
        'fatigue': int(text_contains_keywords(combined_text, KEYWORDS['fatigue'])),  # Did patient mention tiredness?
        'body_pain': int(text_contains_keywords(combined_text, KEYWORDS['body_pain'])),  # Did patient mention body pain?
        'sore_throat': int(text_contains_keywords(combined_text, KEYWORDS['sore_throat'])),  # Did patient mention sore throat?
        'vomiting': int(text_contains_keywords(combined_text, KEYWORDS['vomiting'])),  # Did patient mention vomiting?
        'diarrhea': int(text_contains_keywords(combined_text, KEYWORDS['diarrhea'])),  # Did patient mention diarrhea?
        'blood_pressure': systolic,  # Blood pressure number
        'heart_rate': heart_rate,  # Heart rate number
        'temperature': temperature,  # Temperature number
        'respiratory_rate': respiratory_rate,  # Breathing rate number
        # Past visit information
        'past_checkup_count': past_checkup_count,  # How many past visits?
        'has_past_fever': has_past_fever,  # Had fever before?
        'has_past_high_bp': has_past_high_bp,  # Had high BP before?
        'has_past_cough': has_past_cough,  # Coughed before?
        'has_past_headache': has_past_headache,  # Had headaches before?
        'most_common_past_diagnosis': most_common_past_diagnosis_hash  # Most common past sickness (as number)
    }

    # Return the clues in the right order, and all the features
    return [features[col] for col in FEATURE_COLUMNS], features


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
        prediction = model.predict([input_data])[0]

        # Get how sure the tree is about each guess
        if hasattr(model, 'predict_proba'):
            probabilities = model.predict_proba([input_data])[0]
            classes = model.classes_
            # Make a list of sicknesses with how sure the tree is
            scores = {
                disease: round(float(prob) * 100, 1)
                for disease, prob in zip(classes, probabilities)
            }
        else:
            # If no sure-ness info, just say 100% for the guess
            scores = {prediction: 100.0}

        # Pick the top 3 best guesses
        top3 = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:3]

        # Send back the answer
        return jsonify({
            "disease": prediction,  # The sickness the tree thinks
            "confidence": scores.get(prediction, 0.0),  # How sure the tree is
            "top3": [{"disease": d, "confidence": c} for d, c in top3],  # Top 3 guesses
            "features": features  # All the clues used
        })

    except Exception as e:
        # If something goes wrong, write it down and send error message
        logger.exception("Prediction error")
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    # Start the web server so doctors can ask for predictions
    app.run(host="127.0.0.1", port=8000, debug=True)
