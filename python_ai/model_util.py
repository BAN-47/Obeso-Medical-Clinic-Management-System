import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.calibration import CalibratedClassifierCV
import logging
import os

from database import connect_db

logger = logging.getLogger(__name__)

CSV_PATH = os.path.join(os.path.dirname(__file__), 'disease_data.csv')


PLACEHOLDER_PATTERNS = ['test', 'dummy', 'placeholder', 'n/a', 'na', 'dev', 'sample', 'unknown', 'zzz']


def is_placeholder_text(s):
    if not s:
        return False
    t = str(s).strip().lower()
    for p in PLACEHOLDER_PATTERNS:
        if p in t:
            return True
    return False

# ── The 8 symptoms that exist in the CSV ──
CSV_FEATURE_COLUMNS = [
    'fever', 'cough', 'headache', 'fatigue',
    'body_pain', 'sore_throat', 'vomiting', 'diarrhea'
]

# ── Extended feature set used when DB records are available ──
DB_FEATURE_COLUMNS = CSV_FEATURE_COLUMNS + [
    'high_bp',
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

SYMPTOMS = [
    'cough', 'headache', 'fatigue', 'body_pain',
    'sore_throat', 'vomiting', 'diarrhea'
]

KEYWORDS = {
    'cough':       ['cough', 'dry cough', 'productive cough'],
    'headache':    ['headache', 'migraine', 'head pain'],
    'fatigue':     ['fatigue', 'tired', 'weakness'],
    'body_pain':   ['body pain', 'muscle pain', 'aches', 'myalgia'],
    'sore_throat': ['sore throat', 'throat pain', 'pharyngitis'],
    'vomiting':    ['vomit', 'vomiting', 'nausea'],
    'diarrhea':    ['diarrhea', 'diarrhoea', 'loose stool', 'loose bowel']
}

DIAGNOSIS_MAP = {}
DISEASE_SYMPTOMS = {}

# Will be set by train_model() — tracks which feature set the live model uses
ACTIVE_FEATURE_COLUMNS = CSV_FEATURE_COLUMNS


def text_contains_keywords(text, keywords):
    text = str(text).lower()
    return any(kw in text for kw in keywords)


def encode_diagnosis(diag):
    if not diag or diag == 'None':
        return 0
    if diag not in DIAGNOSIS_MAP:
        DIAGNOSIS_MAP[diag] = len(DIAGNOSIS_MAP) + 1
    return DIAGNOSIS_MAP[diag]


def load_disease_symptoms_map():
    """
    Build a mapping of disease -> set(symptoms) from the CSV baseline.
    This is used for simple clinical-rule filtering: a disease is considered
    related to the current presentation if at least one of its canonical
    symptoms appears in the current evidence (CC/HPI/objective).
    """
    global DISEASE_SYMPTOMS
    try:
        if not os.path.exists(CSV_PATH):
            return {}
        df = pd.read_csv(CSV_PATH)
        if 'disease' not in df.columns:
            return {}

        disease_map = {}
        for _, row in df.iterrows():
            d = row.get('disease')
            if not d:
                continue
            present = set()
            for s in CSV_FEATURE_COLUMNS:
                try:
                    if int(row.get(s, 0)):
                        present.add(s)
                except:
                    pass
            if d in disease_map:
                disease_map[d] |= present
            else:
                disease_map[d] = set(present)

        DISEASE_SYMPTOMS = disease_map
        return disease_map
    except Exception:
        return {}


def extract_evidence_symptoms(text):
    """Return a set of symptom keys (from SYMPTOMS/fever/high_bp)
    that appear in the provided free-text evidence."""
    found = set()
    if not text:
        return found
    t = str(text).lower()
    for s in SYMPTOMS:
        if text_contains_keywords(t, KEYWORDS.get(s, [])):
            found.add(s)
    # temperature and blood pressure are numerical and handled elsewhere
    return found


def disease_supported_by_evidence(disease, evidence_symptoms, evidence_vitals, clinician_diag=None):
    """
    Return True if `disease` is plausibly related to the provided evidence.
    Rules:
      - If clinician provided `clinician_diag` and it matches `disease`, treat
        it as supported (but still ensure at least one evidence item exists if possible).
      - Otherwise, require that disease's canonical symptom set intersects with
        `evidence_symptoms` OR that vital sign clues match (fever/high_bp).
    """
    if not disease:
        return False
    if clinician_diag and clinician_diag.strip().lower() == str(disease).strip().lower():
        # clinician diagnosis present -> supported
        return True

    # load mapping if not present
    if not DISEASE_SYMPTOMS:
        load_disease_symptoms_map()

    disease_symptoms = DISEASE_SYMPTOMS.get(disease, set())
    if disease_symptoms & evidence_symptoms:
        return True

    # check vitals hints
    temp = float(evidence_vitals.get('temperature', 0) or 0)
    bp   = str(evidence_vitals.get('blood_pressure', '') or '')
    try:
        systolic = int(bp.split('/')[0]) if '/' in bp else int(bp) if bp else 0
    except:
        systolic = 0

    if 'fever' in disease_symptoms and temp >= 38.0:
        return True
    if 'high_bp' in disease_symptoms and systolic >= 140:
        return True

    return False


# ──────────────────────────────────────────────
# Patient history lookup (for prediction time)
# ──────────────────────────────────────────────
def get_patient_history(conn, patient_id):
    empty = {
        'past_checkup_count': 0,
        'has_past_fever': 0,
        'has_past_high_bp': 0,
        'has_past_cough': 0,
        'has_past_headache': 0,
        'most_common_past_diagnosis': 0
    }
    if not patient_id or str(patient_id) in ('', 'N/A', '0'):
        return empty

    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT diagnosis, temperature, blood_pressure,
                   chief_complaint, history_present_illness
            FROM checkups
            WHERE patient_id = %s AND status = 'completed'
            ORDER BY checkup_date DESC
        """, (patient_id,))
        rows = cursor.fetchall()
        cursor.close()

        if not rows:
            return empty

        past_df = pd.DataFrame(rows)
        past_df['temperature'] = pd.to_numeric(
            past_df['temperature'], errors='coerce').fillna(0)

        def parse_bp(x):
            x = str(x)
            try:
                return int(x.split('/')[0]) if '/' in x else int(x)
            except:
                return 0

        past_df['bp_sys'] = past_df['blood_pressure'].apply(parse_bp)

        combined = (past_df['chief_complaint'].fillna('') + ' ' +
                    past_df['history_present_illness'].fillna(''))

        mode_s = past_df['diagnosis'].dropna().mode()
        most_common = mode_s.iloc[0] if not mode_s.empty else 'None'

        return {
            'past_checkup_count':        len(rows),
            'has_past_fever':            int((past_df['temperature'] >= 38.0).any()),
            'has_past_high_bp':          int((past_df['bp_sys'] >= 140).any()),
            'has_past_cough':            int(combined.apply(
                                             lambda t: text_contains_keywords(t, KEYWORDS['cough'])).any()),
            'has_past_headache':         int(combined.apply(
                                             lambda t: text_contains_keywords(t, KEYWORDS['headache'])).any()),
            'most_common_past_diagnosis': encode_diagnosis(most_common)
        }
    except Exception as e:
        logger.warning(f"Patient history lookup failed: {e}")
        return empty


# ──────────────────────────────────────────────
# Training data loaders
# ──────────────────────────────────────────────
def load_training_data_from_csv():
    """
    Load only the 8 symptom columns that the CSV actually contains.
    Do NOT pad with zeros for missing columns — that's what caused
    the model to always predict Diarrhea.
    """
    df = pd.read_csv(CSV_PATH)

    if 'disease' not in df.columns:
        raise ValueError("CSV must have a 'disease' column.")

    # Keep only the columns that are genuinely in the CSV
    available = [c for c in CSV_FEATURE_COLUMNS if c in df.columns]
    for col in available:
        df[col] = pd.to_numeric(df[col], errors='coerce').fillna(0)

    return df[available], df['disease'], available


def load_training_data_from_db(conn):
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT patient_id, checkup_date, diagnosis,
                   blood_pressure, heart_rate, temperature, respiratory_rate,
                   chief_complaint, history_present_illness
            FROM checkups
            WHERE diagnosis IS NOT NULL
              AND diagnosis != ''
              AND status = 'completed'
            ORDER BY patient_id, checkup_date
        """)
        rows = cursor.fetchall()
        cursor.close()
    except Exception as e:
        logger.warning(f"DB training load failed: {e}")
        return pd.DataFrame(), pd.Series(dtype='str'), []

    if not rows:
        return pd.DataFrame(), pd.Series(dtype='str'), []

    df = pd.DataFrame(rows)

    def parse_bp(x):
        x = str(x)
        try:
            return int(x.split('/')[0]) if '/' in x else int(x)
        except:
            return 0

    df['blood_pressure']   = df['blood_pressure'].apply(parse_bp)
    df['temperature']      = pd.to_numeric(df['temperature'],      errors='coerce').fillna(0)
    df['heart_rate']       = pd.to_numeric(df['heart_rate'],       errors='coerce').fillna(0)
    df['respiratory_rate'] = pd.to_numeric(df['respiratory_rate'], errors='coerce').fillna(0)

    df['fever']   = (df['temperature']   >= 38.0).astype(int)
    df['high_bp'] = (df['blood_pressure'] >= 140).astype(int)

    for symptom in SYMPTOMS:
        df[symptom] = df.apply(
            lambda row: int(
                text_contains_keywords(str(row.get('chief_complaint', '')), KEYWORDS[symptom]) or
                text_contains_keywords(str(row.get('history_present_illness', '')), KEYWORDS[symptom])
            ), axis=1
        )

    # ------------------
    # Filter out placeholder / mock / corrupted rows
    # ------------------
    def valid_row(r):
        diag = str(r.get('diagnosis', '')).strip()
        # exclude obvious placeholders
        if is_placeholder_text(diag):
            return False
        # exclude rows with no complaint and no vitals
        cc = str(r.get('chief_complaint', '')).strip()
        hpi = str(r.get('history_present_illness', '')).strip()
        temp = 0
        try:
            temp = float(r.get('temperature') or 0)
        except:
            temp = 0
        bp = str(r.get('blood_pressure') or '').strip()
        if not diag:
            return False
        if not cc and not hpi and temp == 0 and bp in ('', '0', '0/0'):
            return False
        # exclude developer placeholders in text
        if is_placeholder_text(cc) or is_placeholder_text(hpi):
            return False
        return True

    before = len(df)
    df = df[df.apply(valid_row, axis=1)].reset_index(drop=True)
    after = len(df)
    if after < before:
        logger.info(f"Filtered {before-after} placeholder/invalid DB rows from training data")

    # Per-row historical features
    history_rows = []
    for _, row in df.iterrows():
        pid, cdate = row['patient_id'], row['checkup_date']
        past = df[(df['patient_id'] == pid) & (df['checkup_date'] < cdate)]

        past_combined = (past['chief_complaint'].fillna('') + ' ' +
                         past['history_present_illness'].fillna(''))

        mode_s = past['diagnosis'].dropna().mode()
        history_rows.append({
            'past_checkup_count':         len(past),
            'has_past_fever':             int((past['temperature'] >= 38.0).any()) if not past.empty else 0,
            'has_past_high_bp':           int((past['blood_pressure'] >= 140).any()) if not past.empty else 0,
            'has_past_cough':             int(past_combined.apply(
                                              lambda t: text_contains_keywords(t, KEYWORDS['cough'])).any()) if not past.empty else 0,
            'has_past_headache':          int(past_combined.apply(
                                              lambda t: text_contains_keywords(t, KEYWORDS['headache'])).any()) if not past.empty else 0,
            'most_common_past_diagnosis': encode_diagnosis(mode_s.iloc[0] if not mode_s.empty else 'None')
        })

    hist_df = pd.DataFrame(history_rows)
    df = pd.concat([df.reset_index(drop=True), hist_df], axis=1)

    # Use full feature set for DB data
    available = [c for c in DB_FEATURE_COLUMNS if c in df.columns]
    return df[available], df['diagnosis'], available


def train_model():
    global ACTIVE_FEATURE_COLUMNS

    # Always load CSV data
    csv_X, csv_y, csv_cols = load_training_data_from_csv()
    logger.info(f"CSV training data: {len(csv_X)} rows, features: {csv_cols}")

    # Try to augment with DB data
    try:
        conn = connect_db()
        db_X, db_y, db_cols = load_training_data_from_db(conn)
        conn.close()

        if not db_X.empty and len(db_X) >= 5:
            # Align CSV data to the DB feature set (add missing cols as 0)
            for col in db_cols:
                if col not in csv_X.columns:
                    csv_X[col] = 0
            csv_X = csv_X[db_cols]

            # Downsample CSV baseline to avoid it overpowering DB patterns
            try:
                max_csv_rows = max(int(len(db_X) * 2), 50)
                if len(csv_X) > max_csv_rows:
                    csv_X = csv_X.sample(n=max_csv_rows, random_state=42).reset_index(drop=True)
                    # adjust csv_y to same index range if possible
                    if len(csv_y) >= max_csv_rows:
                        csv_y = csv_y.sample(n=max_csv_rows, random_state=42).reset_index(drop=True)
            except Exception:
                pass

            X = pd.concat([csv_X, db_X[db_cols]], ignore_index=True)
            y = pd.concat([csv_y, db_y], ignore_index=True)
            ACTIVE_FEATURE_COLUMNS = db_cols
            logger.info(f"Training with CSV+DB: {len(X)} rows, features: {db_cols}")
        else:
            X, y = csv_X, csv_y
            ACTIVE_FEATURE_COLUMNS = csv_cols
            logger.info("DB data insufficient, using CSV only")
    except Exception as e:
        logger.warning(f"DB unavailable, using CSV only: {e}")
        X, y = csv_X, csv_y
        ACTIVE_FEATURE_COLUMNS = csv_cols

    class_counts = y.value_counts()
    min_class_count = int(class_counts.min())

    # Reduce skew from extremely frequent classes by capping per-class samples
    try:
        cap = int(class_counts.median() * 3) if not class_counts.empty else None
        if cap and cap > 0:
            frames = []
            df_all = X.copy()
            df_all['__target__'] = y.values
            for cls, cnt in class_counts.items():
                cls_rows = df_all[df_all['__target__'] == cls]
                if len(cls_rows) > cap:
                    cls_rows = cls_rows.sample(n=cap, random_state=42)
                frames.append(cls_rows)
            balanced = pd.concat(frames, ignore_index=True)
            y = balanced['__target__']
            X = balanced.drop(columns=['__target__'])
            logger.info(f"Applied per-class cap={cap}, resulting rows={len(X)}")
    except Exception as e:
        logger.warning(f"Per-class capping failed: {e}")

    base = RandomForestClassifier(
        n_estimators=300,
        random_state=42,
        max_depth=None,         # let trees fully split — CSV is small, no overfitting risk
        min_samples_split=2,
        min_samples_leaf=1,
        class_weight='balanced',
        n_jobs=-1
    )

    if min_class_count >= 3:
        cv = min(5, min_class_count)
        trained = CalibratedClassifierCV(estimator=base, method='isotonic', cv=cv)
    else:
        trained = base   # skip calibration when too few samples per class

    trained.fit(X, y)
    logger.info(f"Model trained. Classes: {len(y.unique())}, Feature columns: {ACTIVE_FEATURE_COLUMNS}")
    return trained


# ──────────────────────────────────────────────
# Build input vector at prediction time
# ──────────────────────────────────────────────
def build_input_features(data, patient_history=None):
    """
    Build a feature row that matches ACTIVE_FEATURE_COLUMNS exactly.
    Checkbox values from the UI are integers (1/0).
    Vitals and history fill in the extended columns when the model was
    trained with them.
    """
    def get_symptom(name):
        val = data.get(name)
        if val in (1, '1', True, 'true'):   return 1
        if val in (0, '0', False, 'false'): return 0
        # fallback to keyword parse from free-text fields
        combined = (str(data.get('chief_complaint', '')) + ' ' +
                    str(data.get('history_present_illness', ''))).lower()
        return int(text_contains_keywords(combined, KEYWORDS.get(name, [])))

    bp_raw  = str(data.get('blood_pressure', '0')).strip()
    try:
        systolic = int(bp_raw.split('/')[0]) if '/' in bp_raw else int(bp_raw)
    except:
        systolic = 0

    temp  = float(data.get('temperature')     or 0)
    hr    = int(  data.get('heart_rate')      or 0)
    rr    = int(  data.get('respiratory_rate') or 0)

    hist = patient_history or {
        'past_checkup_count': 0, 'has_past_fever': 0, 'has_past_high_bp': 0,
        'has_past_cough': 0, 'has_past_headache': 0, 'most_common_past_diagnosis': 0
    }

    # Full value map — we'll select only the columns the model was trained on
    all_values = {
        'fever':        max(int(temp >= 38.0), get_symptom('fever')),
        'cough':        get_symptom('cough'),
        'headache':     get_symptom('headache'),
        'fatigue':      get_symptom('fatigue'),
        'body_pain':    get_symptom('body_pain'),
        'sore_throat':  get_symptom('sore_throat'),
        'vomiting':     get_symptom('vomiting'),
        'diarrhea':     get_symptom('diarrhea'),
        'high_bp':      int(systolic >= 140),
        'blood_pressure':  systolic,
        'heart_rate':      hr,
        'temperature':     temp,
        'respiratory_rate': rr,
        'past_checkup_count':         hist['past_checkup_count'],
        'has_past_fever':             hist['has_past_fever'],
        'has_past_high_bp':           hist['has_past_high_bp'],
        'has_past_cough':             hist['has_past_cough'],
        'has_past_headache':          hist['has_past_headache'],
        'most_common_past_diagnosis': hist['most_common_past_diagnosis']
    }

    # Use only the columns the live model was actually trained on
    row = {col: all_values[col] for col in ACTIVE_FEATURE_COLUMNS}
    return pd.DataFrame([row])[ACTIVE_FEATURE_COLUMNS], all_values


# ──────────────────────────────────────────────
# Followup rules
# ──────────────────────────────────────────────
FOLLOWUP_RULES = {
    'Influenza':            {'urgent': False, 'days': 5,
                             'actions': ['Bed rest and isolation', 'Stay hydrated', 'Antipyretics for fever', 'Antiviral if within 48 h of onset'],
                             'tests':   ['Rapid flu test', 'CBC']},
    'Dengue':               {'urgent': True,  'days': 2,
                             'actions': ['Monitor for bleeding/severe abdominal pain', 'Oral rehydration', 'Avoid NSAIDs/aspirin'],
                             'tests':   ['NS1 antigen', 'CBC with platelet count', 'Dengue IgM/IgG']},
    'Pneumonia':            {'urgent': True,  'days': 3,
                             'actions': ['Complete antibiotic course', 'Rest and hydration', 'Monitor SpO2'],
                             'tests':   ['Chest X-ray', 'CBC', 'SpO2', 'Sputum Gram stain']},
    'Gastroenteritis':      {'urgent': False, 'days': 2,
                             'actions': ['ORS therapy', 'BRAT diet', 'Avoid dairy/fatty foods'],
                             'tests':   ['Stool culture if bloody diarrhea', 'Electrolytes if severe']},
    'Hypertension':         {'urgent': False, 'days': 7,
                             'actions': ['Check BP daily', 'Reduce salt', '30 min exercise/day'],
                             'tests':   ['BP monitoring', 'Lipid panel', 'ECG']},
    'Diabetes':             {'urgent': False, 'days': 14,
                             'actions': ['Monitor blood glucose', 'Diet control', 'Exercise', 'Take medications'],
                             'tests':   ['HbA1c', 'Fasting glucose', 'Lipid panel', 'Urinalysis']},
    'UTI':                  {'urgent': False, 'days': 7,
                             'actions': ['Complete antibiotics', 'Increase fluids', 'Avoid caffeine/alcohol'],
                             'tests':   ['Urinalysis', 'Urine culture']},
    'Typhoid':              {'urgent': True,  'days': 2,
                             'actions': ['Complete antibiotic course', 'Rest and hydration', 'Isolate patient'],
                             'tests':   ['Widal test', 'Blood culture', 'CBC']},
    'Typhoid Fever':        {'urgent': True,  'days': 2,
                             'actions': ['Complete antibiotic course', 'Rest and hydration', 'Isolate patient'],
                             'tests':   ['Widal test', 'Blood culture', 'CBC']},
    'Common Cold':          {'urgent': False, 'days': 5,
                             'actions': ['Rest, fluids, decongestants', 'Avoid cold/dusty environments'],
                             'tests':   []},
    'Sinusitis':            {'urgent': False, 'days': 7,
                             'actions': ['Nasal saline rinse', 'Decongestants', 'Complete antibiotics if bacterial'],
                             'tests':   ['X-ray sinuses if chronic']},
    'Bronchitis':           {'urgent': False, 'days': 7,
                             'actions': ['Rest', 'Expectorants', 'Avoid smoking/pollutants'],
                             'tests':   ['Chest X-ray if severe']},
    'Asthma':               {'urgent': True,  'days': 3,
                             'actions': ['Use reliever inhaler', 'Avoid triggers', 'Follow action plan'],
                             'tests':   ['Peak flow', 'SpO2']},
    'Malaria':              {'urgent': True,  'days': 1,
                             'actions': ['Start anti-malarial', 'Bed rest', 'Hydration'],
                             'tests':   ['RDT/Malaria smear', 'CBC']},
    'Leptospirosis':        {'urgent': True,  'days': 1,
                             'actions': ['Antibiotics immediately', 'IV fluids if severe'],
                             'tests':   ['Leptospira IgM', 'Urinalysis', 'LFT', 'RFT']},
    'Appendicitis':         {'urgent': True,  'days': 0,
                             'actions': ['Immediate surgical referral'],
                             'tests':   ['CBC', 'CT abdomen', 'Ultrasound']},
    'Food Poisoning':       {'urgent': False, 'days': 2,
                             'actions': ['ORS', 'Light diet', 'Rest'],
                             'tests':   ['Stool culture if bloody']},
    'Cholera':              {'urgent': True,  'days': 1,
                             'actions': ['Immediate IV/oral rehydration', 'Isolate patient', 'Antibiotics'],
                             'tests':   ['Stool culture', 'Electrolytes']},
}


def get_followup_recommendation(diagnosis, patient_data):
    rule = dict(FOLLOWUP_RULES.get(diagnosis, {
        'urgent': False, 'days': 7,
        'actions': ['Schedule routine checkup', 'Monitor condition'], 'tests': []
    }))

    temp = float(patient_data.get('temperature', 0) or 0)
    bp   = int(  patient_data.get('blood_pressure', 0) or 0)
    hr   = int(  patient_data.get('heart_rate', 0) or 0)

    if temp >= 39.5:
        rule['urgent'] = True
        rule['days'] = 1
    elif temp >= 39.0:
        rule['urgent'] = True
        rule['days'] = min(rule.get('days', 7), 2)

    if bp >= 180:
        rule['urgent'] = True
        rule['days'] = 1

    if hr > 120 or (0 < hr < 50):
        rule['urgent'] = True
        rule['days'] = min(rule.get('days', 7), 1)

    return rule


model = train_model()