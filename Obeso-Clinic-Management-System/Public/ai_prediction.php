<div class="card shadow mb-4 border-start border-4 border-primary">
<div class="card-body">

  <h5>🧠 AI Illness Prediction (Real-Time)</h5>
  <p class="text-muted small mb-3">
    Check all symptoms present then click Predict.
  </p>

  <div class="row g-2 mb-3">
    <?php
    $symptoms = [
      'fever'       => 'Fever',
      'cough'       => 'Cough',
      'headache'    => 'Headache',
      'fatigue'     => 'Fatigue',
      'body_pain'   => 'Body Pain',
      'sore_throat' => 'Sore Throat',
      'vomiting'    => 'Vomiting',
      'diarrhea'    => 'Diarrhea'
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

  <button class="btn btn-primary" onclick="predictDisease()" id="predictBtn">
    <i class="fa fa-brain me-1"></i> Predict Illness
  </button>

  <!-- RESULT AREA -->
  <div id="predictionResult" class="mt-3" style="display:none;">

    <div class="alert alert-warning mb-2">
      <strong>🧠 Predicted Illness:</strong>
      <span id="predictedDisease" class="fs-5 fw-bold ms-2"></span>
      <span class="badge bg-warning text-dark ms-2" id="confidenceBadge"></span>
    </div>

    <p class="text-muted small mb-1">Top possibilities:</p>
    <ul class="list-group list-group-flush" id="top3List"></ul>

    <p class="text-danger small mt-2">
      ⚠️ This is AI-generated and should not replace a doctor's diagnosis.
    </p>

  </div>

  <!-- ERROR -->
  <div id="predictionError" class="alert alert-danger mt-3" style="display:none;">
    ❌ Could not connect to AI server. Make sure Flask is running.
  </div>

</div>
</div>

<script>
async function predictDisease() {
    const btn = document.getElementById("predictBtn");
    const resultDiv  = document.getElementById("predictionResult");
    const errorDiv   = document.getElementById("predictionError");

    // Reset
    resultDiv.style.display = "none";
    errorDiv.style.display  = "none";
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Predicting...';

    // Build symptom payload
    const symptoms = [
        'fever','cough','headache','fatigue',
        'body_pain','sore_throat','vomiting','diarrhea'
    ];

    const data = {};
    symptoms.forEach(s => {
        data[s] = document.getElementById(s).checked ? 1 : 0;
    });

    try {
        const response = await fetch("http://127.0.0.1:8000/predict", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.error) {
            throw new Error(result.error);
        }

        // Show result
        document.getElementById("predictedDisease").textContent =
            result.disease;
        document.getElementById("confidenceBadge").textContent =
            result.confidence + "% confident";

        // Top 3 list
        const list = document.getElementById("top3List");
        list.innerHTML = "";
        result.top3.forEach((item, i) => {
            const icon = i === 0 ? "🥇" : i === 1 ? "🥈" : "🥉";
            list.innerHTML += `
                <li class="list-group-item d-flex justify-content-between">
                    <span>${icon} ${item.disease}</span>
                    <span class="badge bg-secondary">${item.confidence}%</span>
                </li>`;
        });

        resultDiv.style.display = "block";

    } catch (error) {
        console.error(error);
        errorDiv.style.display = "block";

    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-brain me-1"></i> Predict Illness';
    }
}
</script>