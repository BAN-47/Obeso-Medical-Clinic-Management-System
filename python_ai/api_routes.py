from flask import Blueprint, render_template, request, jsonify
import logging
import pandas as pd
import model_util

from model_util import (
    build_input_features,
    get_patient_history,
    get_followup_recommendation,
    CSV_FEATURE_COLUMNS
)
from database import connect_db

api = Blueprint('api', __name__)
logger = logging.getLogger(__name__)


@api.route("/")
def home():
    return "Obeso Clinic AI API - Running"


# ──────────────────────────────────────────────
# /retrain  — retrain model with latest DB data
# ──────────────────────────────────────────────
@api.route("/retrain", methods=["POST"])
def retrain():
    try:
        logger.info("Manual retrain requested")
        # Train and update the model in model_util module
        model_util.model = model_util.train_model()
        
        logger.info(f"Model retrained. Active features: {model_util.ACTIVE_FEATURE_COLUMNS}")
        
        return jsonify({
            "success": True,
            "message": "Model retrained successfully",
            "active_features": model_util.ACTIVE_FEATURE_COLUMNS
        })
    except Exception as e:
        logger.exception("Retrain error")
        return jsonify({"success": False, "error": str(e)}), 500


# ──────────────────────────────────────────────
# /predict  — real-time symptom prediction
# ──────────────────────────────────────────────
@api.route("/predict", methods=["POST"])
def predict():
    try:
        data = request.get_json()
        if not data:
            return jsonify({"error": "No data received"}), 400

        # Fetch real patient history from DB when patient_id is supplied
        patient_id = data.get('patient_id', '')
        patient_history = None

        if patient_id and str(patient_id) not in ('', 'N/A', '0'):
            try:
                conn = connect_db()
                patient_history = get_patient_history(conn, patient_id)
                conn.close()
                logger.info(
                    f"Patient {patient_id}: {patient_history['past_checkup_count']} "
                    f"past checkups, most common dx encoded as "
                    f"{patient_history['most_common_past_diagnosis']}"
                )
            except Exception as db_err:
                logger.warning(f"DB history lookup failed: {db_err}")

        input_data, features = build_input_features(data, patient_history)

        # Clinical evidence extraction
        clinician_diag = (data.get('diagnosis') or '').strip() or None
        evidence_text = ' '.join([str(data.get('chief_complaint', '')), str(data.get('history_present_illness', ''))])
        evidence_symptoms = model_util.extract_evidence_symptoms(evidence_text)
        evidence_vitals = {
            'temperature': data.get('temperature'),
            'blood_pressure': data.get('blood_pressure'),
            'heart_rate': data.get('heart_rate')
        }

        prediction    = model_util.model.predict(input_data)[0]
        probabilities = model_util.model.predict_proba(input_data)[0]
        classes       = model_util.model.classes_

        scores = {d: float(p) for d, p in zip(classes, probabilities)}

        # Build supporting evidence list
        supporting_evidence = []
        for s in sorted(evidence_symptoms):
            supporting_evidence.append(s)
        if clinician_diag:
            supporting_evidence.append(f"clinician diagnosis: {clinician_diag}")

        # Filter and prioritize predictions by clinical relevance
        relevant = []
        for d in classes:
            if model_util.disease_supported_by_evidence(d, evidence_symptoms, evidence_vitals, clinician_diag):
                relevant.append(d)

        filtered_scores = {}
        if relevant:
            # Keep only clinically related diseases
            for d in relevant:
                filtered_scores[d] = round(scores.get(d, 0.0) * 100, 1)
        else:
            # No clear related diseases -> fall back to model top scorers but mark low confidence for unrelated ones
            for d, p in scores.items():
                filtered_scores[d] = round(p * 100, 1)

        # Boost clinician diagnosis if present and supported
        if clinician_diag:
            # find exact match in classes (case-insensitive)
            match = None
            for c in classes:
                if str(c).strip().lower() == clinician_diag.strip().lower():
                    match = c
                    break
            if match:
                if model_util.disease_supported_by_evidence(match, evidence_symptoms, evidence_vitals, clinician_diag):
                    filtered_scores[match] = max(filtered_scores.get(match, 0.0), 92.0)
                else:
                    # clinical diagnosis contradicts evidence — keep but low
                    filtered_scores[match] = max(filtered_scores.get(match, 0.0), 5.0)

        # Sort and pick top3
        top3 = sorted(filtered_scores.items(), key=lambda x: x[1], reverse=True)[:3]

        # Determine current diagnosis (prefer clinician if present)
        current_diagnosis = clinician_diag if clinician_diag else (top3[0][0] if top3 else str(prediction))
        confidence = next((c for d, c in top3 if str(d) == str(current_diagnosis)), round(scores.get(prediction, 0.0) * 100, 1))

        followup = get_followup_recommendation(prediction, {
            'temperature':   float(data.get('temperature', 0) or 0),
            'blood_pressure': int(
                str(data.get('blood_pressure', '0')).split('/')[0]
                if '/' in str(data.get('blood_pressure', '0'))
                else data.get('blood_pressure', 0) or 0
            ),
            'heart_rate': int(data.get('heart_rate', 0) or 0)
        })

        # Build related_future_conditions list (exclude current_diagnosis)
        related_future_conditions = []
        for d, c in top3:
            if str(d).strip().lower() != (str(current_diagnosis).strip().lower() if current_diagnosis else ''):
                related_future_conditions.append({"condition": d, "probability": c})

        return jsonify({
            "current_diagnosis": str(current_diagnosis),
            "disease":           str(current_diagnosis),
            "confidence":        confidence,
            "supporting_evidence": supporting_evidence,
            "related_future_conditions": related_future_conditions,
            "top3":              [{"disease": d, "confidence": c} for d, c in top3],
            "followup": {
                "urgent":  followup.get('urgent', False),
                "days":    followup.get('days', 7),
                "actions": followup.get('actions', []),
                "tests":   followup.get('tests', [])
            },
            "features":      features,
            "history_used":  patient_history is not None,
            "past_checkups": (patient_history or {}).get('past_checkup_count', 0)
        })

    except Exception as e:
        logger.exception("Prediction error")
        return jsonify({"error": str(e)}), 500


# ──────────────────────────────────────────────
# /predict-trend  — insights: next-month forecast
# Uses the last 3 months of checkup data to build
# an aggregate symptom profile and asks the model
# what illness is most likely to dominate next month.
# ──────────────────────────────────────────────
@api.route("/predict-trend", methods=["GET"])
def predict_trend():
    try:
        conn = connect_db()
        cursor = conn.cursor(dictionary=True)

        # Pull last 3 months of completed checkups
        cursor.execute("""
            SELECT diagnosis, temperature, blood_pressure, heart_rate,
                   respiratory_rate, chief_complaint, history_present_illness
            FROM checkups
            WHERE status = 'completed'
              AND checkup_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
              AND diagnosis IS NOT NULL AND diagnosis != ''
        """)
        rows = cursor.fetchall()
        cursor.close()
        conn.close()

        if not rows or len(rows) < 3:
            # Not enough DB data — use the CSV disease distribution
            return _csv_based_trend()

        df = pd.DataFrame(rows)

        # Parse vitals
        def parse_bp(x):
            x = str(x)
            try:
                return int(x.split('/')[0]) if '/' in x else int(x)
            except:
                return 0

        df['bp_sys']  = df['blood_pressure'].apply(parse_bp)
        df['temp']    = pd.to_numeric(df['temperature'],      errors='coerce').fillna(0)
        df['hr']      = pd.to_numeric(df['heart_rate'],       errors='coerce').fillna(0)
        df['rr']      = pd.to_numeric(df['respiratory_rate'], errors='coerce').fillna(0)

        # Build aggregate symptom profile (mean across recent checkups)
        from model_util import text_contains_keywords, KEYWORDS

        avg_fever      = float((df['temp'] >= 38.0).mean())
        avg_high_bp    = float((df['bp_sys'] >= 140).mean())
        avg_temp       = float(df['temp'].mean())
        avg_bp         = float(df['bp_sys'].mean())
        avg_hr         = float(df['hr'].mean())
        avg_rr         = float(df['rr'].mean())

        combined = (df['chief_complaint'].fillna('') + ' ' +
                    df['history_present_illness'].fillna(''))

        symptom_avgs = {
            s: float(combined.apply(
                lambda t: text_contains_keywords(t, KEYWORDS[s])).mean())
            for s in ['cough', 'headache', 'fatigue', 'body_pain',
                      'sore_throat', 'vomiting', 'diarrhea']
        }

        # Build a synthetic input from the aggregate profile
        trend_input = {
            'fever':       avg_fever,
            'cough':       symptom_avgs['cough'],
            'headache':    symptom_avgs['headache'],
            'fatigue':     symptom_avgs['fatigue'],
            'body_pain':   symptom_avgs['body_pain'],
            'sore_throat': symptom_avgs['sore_throat'],
            'vomiting':    symptom_avgs['vomiting'],
            'diarrhea':    symptom_avgs['diarrhea'],
            # Extended fields (only used if model was trained with them)
            'high_bp':              avg_high_bp,
            'blood_pressure':       avg_bp,
            'heart_rate':           avg_hr,
            'temperature':          avg_temp,
            'respiratory_rate':     avg_rr,
            'past_checkup_count':   0,
            'has_past_fever':       0,
            'has_past_high_bp':     0,
            'has_past_cough':       0,
            'has_past_headache':    0,
            'most_common_past_diagnosis': 0,
        }

        row = {col: trend_input[col] for col in model_util.ACTIVE_FEATURE_COLUMNS}
        X   = pd.DataFrame([row])[model_util.ACTIVE_FEATURE_COLUMNS]

        prediction    = model_util.model.predict(X)[0]
        probabilities = model_util.model.predict_proba(X)[0]
        classes       = model_util.model.classes_

        scores = {
            d: round(float(p) * 100, 1)
            for d, p in zip(classes, probabilities)
        }
        top3 = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:3]

        # Also include what the DB says is the actual top illness last 3 months
        diag_counts = df['diagnosis'].value_counts().head(3).to_dict()

        return jsonify({
            "predicted_next_month": prediction,
            "confidence":           scores.get(prediction, 0.0),
            "top3":                 [{"disease": d, "confidence": c} for d, c in top3],
            "recent_top_diagnoses": [{"disease": d, "count": c} for d, c in diag_counts.items()],
            "based_on_records":     len(rows),
            "source":               "db"
        })

    except Exception as e:
        logger.exception("Trend prediction error")
        return jsonify({"error": str(e)}), 500


def _csv_based_trend():
    """
    Fallback: run the model over each distinct symptom pattern in the CSV
    and return the most likely illness overall.
    """
    try:
        import os, csv as csv_mod
        from model_util import CSV_PATH

        disease_scores = {}
        with open(CSV_PATH, newline='') as f:
            reader = csv_mod.DictReader(f)
            for row in reader:
                synth = {k: int(row.get(k, 0)) for k in CSV_FEATURE_COLUMNS}
                # pad to ACTIVE_FEATURE_COLUMNS if needed
                for col in model_util.ACTIVE_FEATURE_COLUMNS:
                    if col not in synth:
                        synth[col] = 0
                X = pd.DataFrame([{col: synth[col] for col in model_util.ACTIVE_FEATURE_COLUMNS}])[model_util.ACTIVE_FEATURE_COLUMNS]
                probs = model_util.model.predict_proba(X)[0]
                for d, p in zip(model_util.model.classes_, probs):
                    disease_scores[d] = disease_scores.get(d, 0) + p

        top3 = sorted(disease_scores.items(), key=lambda x: x[1], reverse=True)[:3]
        total = sum(disease_scores.values()) or 1
        top3_pct = [(d, round(s / total * 100, 1)) for d, s in top3]

        return jsonify({
            "predicted_next_month": top3_pct[0][0],
            "confidence":           top3_pct[0][1],
            "top3":                 [{"disease": d, "confidence": c} for d, c in top3_pct],
            "recent_top_diagnoses": [],
            "based_on_records":     0,
            "source":               "csv"
        })
    except Exception as e:
        logger.exception("CSV trend fallback error")
        return jsonify({"error": str(e)}), 500