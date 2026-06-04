# AI Deployment Guide: InfinityFree (PHP) + Render (Python AI)

This guide explains how to deploy your clinic system across two servers:
- **PHP/MySQL**: InfinityFree (or any PHP host)
- **Python AI**: Render (free tier available)

## Architecture

```
[Browser] 
    ↓
[InfinityFree PHP] 
    ├→ ai_predict.php (proxy)
    │   ↓
    └→ [Render Python AI API]
         ├→ /predict
         └→ /retrain
```

## Setup Steps

### Step 1: Deploy Python AI to Render

1. Go to https://render.com
2. Create a new "Web Service"
3. Connect your GitHub repo (or upload ZIP)
4. Use these settings:

```
Name: obeso-clinic-ai
Runtime: Python 3
Build Command: pip install -r requirements.txt
Start Command: gunicorn app:app
```

5. In "Environment" tab, add:
```
MYSQLHOST=your-db-host
MYSQLUSER=your-db-user
MYSQLPASSWORD=your-db-password
MYSQLDATABASE=obeso_clinic_database
MYSQLPORT=3306
```

6. After deployment, you'll get a URL like:
```
https://obeso-clinic-ai-xxxx.onrender.com
```

### Step 2: Configure PHP Environment Variables

On InfinityFree, create an `.env` file in your root directory:

```
AI_API_URL=https://obeso-clinic-ai-xxxx.onrender.com/predict
AI_RETRAIN_URL=https://obeso-clinic-ai-xxxx.onrender.com/retrain
```

Or edit the PHP files directly:

**ai_predict.php** (line 25):
```php
define('AI_API_URL', 'https://obeso-clinic-ai-xxxx.onrender.com/predict');
```

**ai_retrain_proxy.php** (line 17):
```php
define('AI_RETRAIN_URL', 'https://obeso-clinic-ai-xxxx.onrender.com/retrain');
```

### Step 3: Verify Endpoints

Test from your browser:

1. **Predict endpoint:**
```
POST https://your-infinityfree-domain/Public/ai_predict.php
Content-Type: application/json
Body: {
  "patient_id": 1,
  "diagnosis": "URTI",
  "chief_complaint": "cough",
  "history_present_illness": "",
  "blood_pressure": "120/80",
  "heart_rate": 90,
  "temperature": 38.2
}
```

2. **Retrain endpoint:**
```
http://your-infinityfree-domain/Public/admin_retrain.php
```

## Files Created

### 1. `Public/ai_predict.php`
- Secure proxy for AI predictions
- Forwards browser → PHP → Python
- Handles errors gracefully
- Uses cURL for reliable HTTP calls

### 2. `Public/ai_retrain_proxy.php`
- Handles manual model retraining
- Calls Python `/retrain` endpoint
- Can be triggered via AJAX or admin page

### 3. `Public/admin_retrain.php`
- UI dashboard for manual retraining
- Shows progress/status
- No automatic retraining (manual only)

## Usage in Medical Records Page

### When doctor saves a record:
```
1. PHP saves checkup → MySQL
2. NO automatic retrain (removed)
3. Page reloads with success message
```

### When doctor views AI insights:
```
1. JS calls ai_predict.php
2. ai_predict.php forwards to Render Python AI
3. AI returns predictions
4. UI displays results
```

### To retrain manually:
```
Click "Retrain AI" button in admin dashboard
→ Visits admin_retrain.php
→ Calls ai_retrain_proxy.php
→ Triggers Render /retrain endpoint
→ Model retrains with latest DB data
```

## Local Testing (Before Deployment)

To test locally before pushing to InfinityFree:

1. Start Python AI:
```bash
cd python_ai
python app.py
```

2. Update config to localhost:
```php
define('AI_API_URL', 'http://127.0.0.1:8000/predict');
define('AI_RETRAIN_URL', 'http://127.0.0.1:8000/retrain');
```

3. Test endpoints via browser or curl

## Production Checklist

- [ ] Python AI deployed to Render
- [ ] Render URL accessible from InfinityFree
- [ ] Environment variables set on InfinityFree
- [ ] ai_predict.php uses correct Render URL
- [ ] ai_retrain_proxy.php uses correct Render URL
- [ ] admin_retrain.php accessible to doctors
- [ ] Test with sample patient record
- [ ] Error handling working (no UI crashes)
- [ ] Logs checked for any errors

## Environment Variables

Use these in production:

```bash
# Python AI (Render environment)
MYSQLHOST=your-mysql-host
MYSQLUSER=clinic_user
MYSQLPASSWORD=strong_password
MYSQLDATABASE=obeso_clinic_database
MYSQLPORT=3306

# PHP (InfinityFree environment or hardcoded)
AI_API_URL=https://obeso-clinic-ai-xxxx.onrender.com/predict
AI_RETRAIN_URL=https://obeso-clinic-ai-xxxx.onrender.com/retrain
```

## Troubleshooting

### "AI service unavailable"
- Check Render service is running
- Verify URL is correct
- Check MySQL credentials on Render
- Look at Render logs

### Slow predictions
- Check network latency
- Render free tier sleeps after 15 min inactivity
- Consider upgrading Render plan

### Retraining fails
- Ensure at least 5 completed checkups in DB
- Check cURL is enabled on PHP host
- Verify timeout setting (30 sec for retrain)

## Security Notes

✅ **What's protected:**
- Medical data not exposed to browser
- Predictions routed through PHP proxy
- Render communicates only with authorized MySQL

⚠️ **Best practices:**
- Use HTTPS everywhere (Render gives free SSL)
- Set strong MySQL password
- Restrict admin_retrain.php access if needed
- Monitor logs for errors

## Support

If you need help:
1. Check Render logs: `Logs` tab in dashboard
2. Check InfinityFree error logs
3. Test with curl locally
4. Verify network connectivity between servers
