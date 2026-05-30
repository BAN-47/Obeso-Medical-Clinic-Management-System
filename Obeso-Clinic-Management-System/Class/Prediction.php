<?php
class Prediction
{
    private $apiUrl;

    public function __construct($apiUrl = 'http://127.0.0.1:8000/predict')
    {
        $this->apiUrl = $apiUrl;
    }

    public function predictFromCheckup(array $checkup)
    {
        if (empty($checkup)) {
            return null;
        }

        $payload = [
            'patient_id' => $checkup['patient_id'] ?? '',
            'chief_complaint' => $checkup['chief_complaint'] ?? '',
            'history_present_illness' => $checkup['history_present_illness'] ?? '',
            'blood_pressure' => $checkup['blood_pressure'] ?? '',
            'heart_rate' => (int)($checkup['heart_rate'] ?? 0),
            'temperature' => (float)($checkup['temperature'] ?? 0),
            'respiratory_rate' => (int)($checkup['respiratory_rate'] ?? 0)
        ];

        return $this->requestApi($payload);
    }

    private function requestApi(array $payload)
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return [
                'error' => true,
                'message' => 'AI service unavailable. ' . ($curlError ?: 'Please start the prediction server.'),
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
            return [
                'error' => true,
                'message' => $data['error'] ?? 'Invalid AI response.',
            ];
        }

        return [
            'disease' => $data['disease'] ?? 'Unknown',
            'confidence' => $data['confidence'] ?? 0,
            'top3' => $data['top3'] ?? [],
            'followup' => $data['followup'] ?? null,
            'features' => $data['features'] ?? null
        ];
    }
}
