<div class="card shadow mb-4 border-start border-4 border-primary">
<div class="card-body">

  <h5>🧠 AI Illness Prediction <span class="badge bg-primary ms-1" style="font-size:0.65rem;">Real-Time</span></h5>
  <p class="text-muted small mb-3">
    Fill in the patient vitals and symptoms, then click <strong>Predict</strong>.
     The AI will analyze the data and provide a likely diagnosis along with confidence levels and recommended follow-ups.
  </p>

  <!-- Symptom Checkboxes -->
  <div class="row g-2 mb-3">
    <?php
    $symptoms = [
      'fever'       => '🌡️ Fever',
      'cough'       => '😮‍💨 Cough',
      'headache'    => '🤕 Headache',
      'fatigue'     => '😴 Fatigue',
      'body_pain'   => '💢 Body Pain',
      'sore_throat' => '🤒 Sore Throat',
      'vomiting'    => '🤢 Vomiting',
      'diarrhea'    => '🚻 Diarrhea'
    ];
    foreach ($symptoms as $id => $label): ?>
      <div class="col-6 col-md-3">
        <div class="form-check">
          <input class="form-check-input symptom-check"
                 type="checkbox"
                 id="<?= $id ?>"
                 value="<?= $id ?>">
          <label class="form-check-label" for="<?= $id ?>">
            <?= $label ?>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Patient History Badge (shown when patient_id is present) -->
  <div id="historyBadge" class="mb-3" style="display:none;">
    <span class="badge bg-success">
      <i class="fa fa-history me-1"></i>
      <span id="historyBadgeText">Using patient history</span>
    </span>
  </div>

  <button class="btn btn-primary" onclick="predictDisease()" id="predictBtn">
    <i class="fa fa-brain me-1"></i> Predict Illness
  </button>

  <!-- RESULT AREA -->
  <div id="predictionResult" class="mt-3" style="display:none;">

    <div class="alert alert-warning mb-2">
      <strong>🧠 Predicted Illness:</strong>
      <span id="predictedDisease" class="fs-5 fw-bold ms-2"></span>
      <span class="badge bg-warning text-dark ms-2" id="confidenceBadge"></span>
      <span class="badge bg-success ms-1" id="historyUsedBadge" style="display:none;">
        <i class="fa fa-history me-1"></i>History-informed
      </span>
    </div>

    <!-- Future illness risk from history -->
    <div id="futureRiskBlock" class="mb-3" style="display:none;">
      <div class="alert alert-secondary py-2">
        <strong>📂 Past Checkups Analyzed:</strong>
        <span id="pastCheckupCount" class="badge bg-secondary ms-1"></span>
        <span id="pastCheckupSummary" class="text-muted ms-2 small"></span>
      </div>
    </div>

    <div id="futureOutcomeBlock" class="mb-3" style="display:none;">
      <div class="alert alert-info mb-2">
        <strong>📋 Recommended Followup:</strong>
        <span id="futureOutcomeRisk" class="badge ms-2"></span>
      </div>
      <div id="futureOutcomeSummary" class="mb-2 text-muted"></div>
    </div>

    <p class="text-muted small mb-1">Top possibilities:</p>
    <ul class="list-group list-group-flush" id="top3List"></ul>

    <p class="text-danger small mt-2">
      ⚠️ This is AI-generated and should not replace a doctor's diagnosis.
    </p>

  </div>

  <!-- ERROR -->
  <div id="predictionError" class="alert alert-danger mt-3" style="display:none;">
    ❌ Could not connect to AI server.
  </div>

</div>
</div>

<script>
// Show history badge when patient_id is available
(function() {
    const pid = document.getElementById('patient_id')?.value;
    if (pid && pid !== 'N/A' && pid !== '0' && pid !== '') {
        document.getElementById('historyBadge').style.display = 'block';
        document.getElementById('historyBadgeText').textContent =
            'Will use past records for patient #' + pid;
    }
})();

async function predictDisease() {
    const btn       = document.getElementById("predictBtn");
    const resultDiv = document.getElementById("predictionResult");
    const errorDiv  = document.getElementById("predictionError");
    const apiUrl    = `https://obeso-medical-clinic-management-system-3.onrender.com/`; // Update with your Flask server URL if needed

    // Reset
    resultDiv.style.display = "none";
    errorDiv.style.display  = "none";
    document.getElementById('futureOutcomeBlock').style.display  = 'none';
    document.getElementById('futureRiskBlock').style.display     = 'none';
    document.getElementById('historyUsedBadge').style.display    = 'none';

    btn.disabled    = true;
    btn.innerHTML   = '<i class="fa fa-spinner fa-spin me-1"></i> Predicting...';

    const symptomKeys = [
        'fever','cough','headache','fatigue',
        'body_pain','sore_throat','vomiting','diarrhea'
    ];

    const data = {
        patient_id:                document.getElementById('patient_id')?.value || "",
        chief_complaint:           document.getElementById('chief_complaint')?.value || "",
        history_present_illness:   document.getElementById('history_present_illness')?.value || "",
        blood_pressure:            document.getElementById('blood_pressure')?.value || "0",
        heart_rate:                parseInt(document.getElementById('heart_rate')?.value) || 0,
        temperature:               parseFloat(document.getElementById('temperature')?.value) || 0,
        respiratory_rate:          parseInt(document.getElementById('respiratory_rate')?.value) || 0
    };

    symptomKeys.forEach(s => {
        const el = document.getElementById(s);
        data[s] = (el && el.checked) ? 1 : 0;
    });

    try {
        const response = await fetch(apiUrl, {
            method:  "POST",
            mode:    "cors",
            headers: { "Content-Type": "application/json" },
            body:    JSON.stringify(data)
        });

        if (!response.ok) {
            throw new Error(`Server returned ${response.status}`);
        }

        const result = await response.json();

        if (result.error) {
            throw new Error(result.error);
        }

        // Main prediction
        document.getElementById("predictedDisease").textContent = result.disease;
        document.getElementById("confidenceBadge").textContent  = result.confidence + "% confident";

        // History badge
        if (result.history_used) {
            document.getElementById('historyUsedBadge').style.display = 'inline-block';
        }

        // Past checkup info
        if (result.past_checkups > 0) {
            document.getElementById('futureRiskBlock').style.display = 'block';
            document.getElementById('pastCheckupCount').textContent  = result.past_checkups + ' records';
            document.getElementById('pastCheckupSummary').textContent =
                'AI factored prior visits and recurring conditions into this prediction.';
        }

        // Top 3 list
        const list = document.getElementById("top3List");
        list.innerHTML = "";
        result.top3.forEach((item, i) => {
            const icon  = i === 0 ? "🥇" : i === 1 ? "🥈" : "🥉";
            const color = i === 0 ? "bg-warning text-dark" : "bg-secondary";
            list.innerHTML += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>${icon} ${item.disease}</span>
                    <span class="badge ${color}">${item.confidence}%</span>
                </li>`;
        });

        // Followup block
        if (result.followup) {
            const urgent    = result.followup.urgent;
            const badgeClass= urgent ? 'bg-danger' : 'bg-success';
            const urgentLabel = urgent ? '🚨 URGENT' : '✅ Routine';

            document.getElementById('futureOutcomeRisk').textContent  = urgentLabel;
            document.getElementById('futureOutcomeRisk').className    = 'badge ' + badgeClass;

            const actionsList = (result.followup.actions || []).join(', ') || 'Monitor condition';
            const testsList   = (result.followup.tests   || []).join(', ') || 'As recommended';

            document.getElementById('futureOutcomeSummary').innerHTML = `
                <strong>Follow-up in ${result.followup.days} day(s)</strong><br>
                <strong>Actions:</strong> ${actionsList}<br>
                <strong>Recommended Tests:</strong> ${testsList}
            `;
            document.getElementById('futureOutcomeBlock').style.display = 'block';
        }

        resultDiv.style.display = "block";

    } catch (err) {
        console.error("Prediction error:", err);
        errorDiv.style.display  = "block";
        errorDiv.innerHTML = `
            ❌ <strong>Connection error:</strong> ${err.message}<br>
            <small>Make sure the Flask AI server is running on port 8000.<br>
            Run: <code>python app.py</code> in the <code>python_ai</code> folder.</small>`;
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-brain me-1"></i> Predict Illness';
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const apiUrl = `http://${window.location.hostname}:8000/`;

    fetch(apiUrl)
        .then(response => response.text())
        .then(data => {
            console.log("AI server ready:", data);
            // Auto-trigger an initial prediction request on page load
            predictDisease();
        })
        .catch(error => console.error("AI server fetch error on load:", error));
});
</script>